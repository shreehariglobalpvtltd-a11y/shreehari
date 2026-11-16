<?php
/**
 * =====================================================================
 *  u9-X1-money-test.php — Master v2.0 unit X1 (16 Nov 2026): the last
 *  money rules, every one behind an owner switch that ships OFF.
 *
 *    P4-5d  return-journey discount (return_discount_pct / _days)
 *    P8-3f / P4-4d  extra luggage at the desk (luggage_fee_per_piece) and an
 *           infant on the lap, no berth (infant_seat_on / infant_seat_fare)
 *
 *  1. Quote parity: with every switch off, Fare::quote() gives the SAME
 *     total as the pre-X1 arithmetic (base -> group -> coupon -> tier ->
 *     points -> tax -> fee, replayed here with the same helpers), and the
 *     new returnDiscount is 0. A mobile that does not qualify with the
 *     switch ON is priced the same as with it off.
 *  2. Pure: BookingService::deskExtras() / extrasTotal(), Ticket::
 *     discountTotal() / discountSource() / extrasLine().
 *  3. Database (shari_test only, own rows, all deleted; every setting this
 *     suite flips is restored in finally):
 *       - the return rule: other way + window + live (confirmed / pending
 *         with proof) + one discount per other-way ticket; off = 0
 *       - a Quick Ticket desk sale with luggage + an infant: total, columns,
 *         payment, commission unchanged, PNG one strip taller, PDF line
 *       - the same sale with the switches off: nothing added
 *       - a return-way desk sale stores return_discount and prints it
 *
 *    php -c .claude/php-dev.ini tests/u9-X1-money-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';   // fare / seats / boarding / ticket / booking / notify / agentwallet
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  PASSED: $PASS   FAILED: $FAIL\n";
    exit($FAIL === 0 ? 0 : 1);
}

echo "\n=== X1 money rules: return discount · desk luggage · lap infant ===\n\n";

/* ---- every setting this suite flips, restored exactly as found ----------- */
$PINNED = ['return_discount_pct', 'return_discount_days', 'luggage_fee_per_piece', 'infant_seat_on', 'infant_seat_fare',
           'whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver', 'daily_service_on'];
$prior = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        try {
            if ($row === null) {
                Database::delete('settings', 'skey = :k', ['k' => $k]);
            } else {
                Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]);
            }
        } catch (Throwable $e) {}
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};
$set = static function (string $k, mixed $v, string $type): void {
    Settings::set($k, $v, $type, 'booking', $k === 'return_discount_pct');
    Settings::flush();
};

/* The pre-X1 Fare::quote(), replayed with the same helpers (HEAD de36eecf). */
$legacyQuote = static function (float $base, int $n, int $life, int $ptsReq, int $ptsAvail, string $code, string $phone, ?int $rid, ?int $dep): float {
    $running = $base - Fare::groupDiscount($base, $n);
    if ($code !== '') {
        $c = Fare::couponDiscount($code, $running, $phone, $rid, $dep);
        if ($c['ok']) { $running -= (float) $c['amount']; }
    } else {
        $a = Fare::autoOffer($running, $phone, $rid, $dep);
        if (!empty($a['ok'])) { $running -= (float) $a['amount']; }
    }
    $running -= (float) Fare::tierDiscount($running, $life)['amount'];
    $running -= (float) Fare::pointsRedemption($ptsReq, $ptsAvail, $running)['value'];
    $tax = Fare::tax($running);
    return max(0.0, round($running + $tax + Settings::getFloat('booking_fee', 0.0)));
};

$routes = QuickTicket::routes();
$out = null; $ret = null;
foreach ($routes as $r) {
    if ($out === null && $r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $out = $r; }
    if ($ret === null && $r['direction'] === 'toIndia' && (string) $r['coach_type'] === 'sleeper') { $ret = $r; }
}

try {
    /* ================================================================
     *  1. Quote parity with every switch off
     * ================================================================ */
    echo "-- Quote parity (all switches off)\n";
    $set('return_discount_pct', 0, 'float');
    $set('luggage_fee_per_piece', 0, 'int');
    $set('infant_seat_on', false, 'bool');
    check('return_discount_pct off: no percent for anybody', Fare::returnDiscountPct('9876543210', $out !== null ? (int) $out['id'] : 2, time() + 86400) === 0.0);
    $samples = [[2200.0, 1, 0, 0, 0, '9876543210'], [4400.0, 2, 0, 0, 0, ''], [11000.0, 5, 900, 0, 0, '9800000001'],
                [15400.0, 7, 2500, 100, 400, '9812345678'], [1800.0, 1, 0, 0, 0, '0000000000']];
    $rids = [$out['id'] ?? null, $ret['id'] ?? null, null];   // each way, and a quote with no route
    $same = true; $why = '';
    foreach ($samples as [$b, $n, $life, $req, $avail, $ph]) {
        foreach ($rids as $rid) {
            $dep = $rid !== null ? strtotime(addDaysISO(todayISO(), 5) . ' 13:00:00') : null;
            $q   = Fare::quote($b, $n, $life, $req, $avail, '', $ph, $rid !== null ? (int) $rid : null, $dep);
            $old = $legacyQuote($b, $n, $life, $req, $avail, '', $ph, $rid !== null ? (int) $rid : null, $dep);
            $qNo = Fare::quote($b, $n, $life, $req, $avail, '', $ph, $rid !== null ? (int) $rid : null, $dep, false);
            if ((float) $q['total'] !== $old || (float) $q['returnDiscount'] !== 0.0 || (float) $qNo['total'] !== $old) {
                $same = false; $why = "base $b x$n: new {$q['total']} / legacy $old";
            }
            foreach ($q['breakdown'] as $line) {
                if (str_contains((string) $line['label'], 'फिर्ती')) { $same = false; $why = 'a return line appeared'; }
            }
        }
    }
    check('every sample quotes exactly the pre-X1 total, returnDiscount 0, no return line', $same, $why !== '' ? $why : count($samples) * count($rids) . ' quotes');
    if ($out !== null) {
        $sched = ['id' => 0, 'route_id' => (int) $out['id'], 'travel_date' => addDaysISO(todayISO(), 5), 'slot' => 1, 'fare_override' => null, 'dep_time_override' => null];
        $route = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => (int) $out['id']]);
        $p1 = BookingService::priceFor($route, $sched, ['A1', 'A2'], 'sharing', 'single', '', '9876543210');
        $p0 = Fare::quote((float) $p1['base'], 2, 0, 0, 0, '', '9876543210', (int) $out['id'], Fare::departTs($sched, $route));
        check('the sale\'s own pricing (priceFor) = Fare::quote, returnDiscount 0', (float) $p1['total'] === (float) $p0['total'] && (float) $p1['returnDiscount'] === 0.0,
            $p1['total'] . ' / ' . $p0['total']);
    }

    /* ================================================================
     *  2. Pure helpers
     * ================================================================ */
    echo "\n-- Desk extras (switches off, then on)\n";
    $x = BookingService::deskExtras(['luggagePieces' => 3, 'infantCount' => 1], 2);
    check('switches off: a seller asking for luggage + an infant gets nothing', $x === ['luggage_pieces' => 0, 'luggage_fee' => 0.0, 'infant_count' => 0, 'infant_fee' => 0.0]);
    check('a customer (no seller) never has extras', BookingService::deskExtras([], 0)['luggage_fee'] === 0.0);
    $set('luggage_fee_per_piece', 150, 'int');
    $set('infant_seat_on', true, 'bool');
    $set('infant_seat_fare', 0, 'int');
    $x = BookingService::deskExtras(['luggagePieces' => 2, 'infantCount' => 1], 2);
    check('luggage on: 2 pieces x 150 = 300; infant free', $x['luggage_pieces'] === 2 && $x['luggage_fee'] === 300.0 && $x['infant_count'] === 1 && $x['infant_fee'] === 0.0, json_encode($x));
    $x = BookingService::deskExtras(['luggagePieces' => 99, 'infantCount' => 9], 1);
    check('caps: at most ' . BookingService::LUGGAGE_MAX . ' pieces, one infant per seat', $x['luggage_pieces'] === BookingService::LUGGAGE_MAX && $x['infant_count'] === 1, json_encode($x));
    $x = BookingService::deskExtras(['luggagePieces' => -4, 'infantCount' => 'x'], 3);
    check('junk / negative counts are 0', $x['luggage_pieces'] === 0 && $x['infant_count'] === 0);
    $set('infant_seat_fare', 50, 'int');
    $x = BookingService::deskExtras(['infantCount' => 2], 4);
    check('infant_seat_fare 50: 2 infants = 100', $x['infant_count'] === 2 && $x['infant_fee'] === 100.0, json_encode($x));
    check('extrasTotal(): luggage + infant, never negative, 0 without the columns',
        BookingService::extrasTotal(['luggage_fee' => 300, 'infant_fee' => 50]) === 350.0
        && BookingService::extrasTotal(['luggage_fee' => -5]) === 0.0 && BookingService::extrasTotal([]) === 0.0);
    $set('luggage_fee_per_piece', 0, 'int');
    $set('infant_seat_on', false, 'bool');
    $set('infant_seat_fare', 0, 'int');

    echo "\n-- Ticket helpers\n";
    $b = ['base_total' => 4000, 'coupon_discount' => 0, 'group_discount' => 0, 'return_discount' => 200, 'tier_discount' => 0];
    check('discountTotal() counts the return discount', Ticket::discountTotal($b) === 200.0);
    check('discountSource() names it "फिर्ती छुट · Return"', Ticket::discountSource($b) === 'फिर्ती छुट · Return', Ticket::discountSource($b));
    check('a row without the column: unchanged (u4b answers)', Ticket::discountTotal(['coupon_discount' => 300, 'group_discount' => 250]) === 550.0
        && Ticket::discountSource(['coupon_discount' => 300, 'coupon_code' => 'x', 'group_discount' => 250]) === 'X + GROUP');
    $xb = ['luggage_pieces' => 2, 'luggage_fee' => 300, 'infant_count' => 1, 'infant_fee' => 0];
    check('extrasLine(): Nepali first', Ticket::extrasLine($xb) === 'अतिरिक्त सामान 2 थान ₹300  ·  शिशु (काखमा, बर्थ छैन) 1 · निःशुल्क', Ticket::extrasLine($xb));
    check('extrasLine(latin) for the PDF', Ticket::extrasLine($xb, true) === 'Extra luggage 2 pc = INR 300  ·  Infant (lap, no berth) 1 - free', Ticket::extrasLine($xb, true));
    check('no extras / no columns: ""', Ticket::extrasLine([]) === '' && Ticket::extrasLine(['luggage_pieces' => 0, 'infant_count' => 0]) === '');

    /* ================================================================
     *  3. Database
     * ================================================================ */
    echo "\n-- Database part\n";
    if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
        echo "  \033[33mSKIP\033[0m  DB_NAME is not shari_test — refusing to write\n";
        $restore();
        finish();
    }
    if ($out === null || $ret === null) {
        echo "  \033[33mSKIP\033[0m  needs an active sleeper route each way\n";
        $restore();
        finish();
    }
    if (!CounterDesk::hasColumn('bookings', 'return_discount') || !CounterDesk::hasColumn('bookings', 'luggage_fee')) {
        check('upgrade-2026-11-16c-X1-money.sql is applied on shari_test', false, 'apply it, then run again');
        $restore();
        finish();
    }
} catch (Throwable $e) {
    check('pure part ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $restore();
    finish();
}

$tag      = substr(bin2hex(random_bytes(4)), 0, 6);
$phoneA   = '93' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);   // the return-rule passenger
$phoneB   = '94' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);   // desk sales
$phoneC   = '95' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);   // return-way desk sale
$phones   = [$phoneA, $phoneB, $phoneC];
$madeSch  = [];
$agentId  = 0;
$DT       = addDaysISO(todayISO(), 47);   // the outbound fixture's travel date
$DS       = addDaysISO(todayISO(), 48);   // desk sales date (staff any-date)

/** A fixture schedule for $rid on $date in a free high slot (blocked: never sold to anybody). */
$mkSched = static function (int $rid, string $date) use (&$madeSch): int {
    for ($slot = 96; $slot >= 80; $slot--) {
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => $rid, 'd' => $date, 's' => $slot]) === null) {
            $id = (int) Database::insert('schedules', ['route_id' => $rid, 'travel_date' => $date, 'slot' => $slot,
                'dep_time_override' => '13:00:00', 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            $madeSch[] = $id;
            return $id;
        }
    }
    throw new RuntimeException('no free schedule slot');
};
/** A fixture booking (+ its outbound leg) for $phone on schedule $sid. */
$mkBooking = static function (string $phone, int $sid, string $date, string $status, float $retDisc = 0.0) use ($tag): int {
    static $n = 0; $n++;
    $id = (int) Database::insert('bookings', ['pnr' => 'SHG-U9X1-' . strtoupper($tag) . '-' . $n, 'contact_phone' => $phone, 'contact_country_code' => '91',
        'booking_mode' => 'sharing', 'fare_per_seat' => 2200, 'base_total' => 2200, 'return_discount' => $retDisc, 'total_amount' => 2200 - $retDisc,
        'status' => $status, 'source' => 'admin', 'confirmed_at' => $status === 'confirmed' ? date('Y-m-d H:i:s') : null]);
    Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => $sid, 'travel_date' => $date,
        'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha', 'fare_per_seat' => 2200, 'seat_count' => 1, 'leg_total' => 2200]);
    return $id;
};
$retRoute = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => (int) $ret['id']]);
$outRoute = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => (int) $out['id']]);
$depBack  = static fn(string $d): ?int => Fare::departTs(['travel_date' => $d], $retRoute);

try {
    /* ---- the return rule ------------------------------------------------- */
    echo "\n-- Return-journey rule (P4-5d)\n";
    $set('return_discount_pct', 5, 'float');
    $set('return_discount_days', 30, 'int');
    $sOut = $mkSched((int) $out['id'], $DT);
    $sRet = $mkSched((int) $ret['id'], addDaysISO($DT, 7));
    check('no other-way ticket yet: 0', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, 7))) === 0.0);
    $bPend = $mkBooking($phoneA, $sOut, $DT, 'pending');
    check('a pending other-way booking with no payment proof does not count', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, 7))) === 0.0);
    Database::insert('payments', ['booking_id' => $bPend, 'payment_ref' => 'U9X1' . $tag . 'P', 'method' => 'upi', 'mode' => 'utr', 'amount' => 2200,
        'currency' => 'INR', 'utr_number' => '123456789012', 'payer_phone' => $phoneA, 'status' => 'pending']);
    check('pending WITH a UTR waiting in the verify queue counts: 5%', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, 7))) === 5.0);
    Database::update('bookings', ['status' => 'cancelled'], 'id = :i', ['i' => $bPend]);
    check('a cancelled other-way ticket does not count', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, 7))) === 0.0);
    $bOut = $mkBooking($phoneA, $sOut, $DT, 'confirmed');
    $dep7 = $depBack(addDaysISO($DT, 7));
    check('a confirmed other-way ticket 7 days apart: 5%', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $dep7) === 5.0);
    check('the same direction never gets it', Fare::returnDiscountPct($phoneA, (int) $out['id'], Fare::departTs(['travel_date' => addDaysISO($DT, 7)], $outRoute)) === 0.0);
    check('another mobile never gets it', Fare::returnDiscountPct($phoneB, (int) $ret['id'], $dep7) === 0.0);
    check('outside return_discount_days (31 days): 0', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, 31))) === 0.0);
    check('inside it on the other side (return booked before the outbound, 30 days): 5%', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $depBack(addDaysISO($DT, -30))) === 5.0);
    check('the walk-in placeholder 0000000000 never qualifies', Fare::returnDiscountPct('0000000000', (int) $ret['id'], $dep7) === 0.0);
    $q5 = Fare::quote(2000.0, 1, 0, 0, 0, '', $phoneA, (int) $ret['id'], $dep7);
    $legacy = $legacyQuote(2000.0 - 100.0, 1, 0, 0, 0, '', $phoneA, (int) $ret['id'], $dep7);
    check('Fare::quote: 5% of 2000 = 100 off, after group, before the offer', (float) $q5['returnDiscount'] === 100.0 && (float) $q5['total'] === $legacy,
        'return ' . $q5['returnDiscount'] . ' total ' . $q5['total']);
    check('  …with its own breakdown line', in_array('फिर्ती छुट · Return discount', array_column($q5['breakdown'], 'label'), true));
    check('  …and $returnOk = false leaves it out (website checkout that did not show it)',
        (float) Fare::quote(2000.0, 1, 0, 0, 0, '', $phoneA, (int) $ret['id'], $dep7, false)['returnDiscount'] === 0.0);
    $schR = Database::fetch('SELECT * FROM schedules WHERE id = :i', ['i' => $sRet]);
    $pr   = BookingService::priceFor($retRoute, $schR, ['A1'], 'sharing', 'single', '', $phoneA);
    check('priceFor (the sale\'s pricing) carries it', (float) $pr['returnDiscount'] === round((float) $pr['base'] * 0.05) && (float) $pr['total'] === (float) $pr['base'] - (float) $pr['returnDiscount'],
        'base ' . $pr['base'] . ' return ' . $pr['returnDiscount'] . ' total ' . $pr['total']);
    $bUsed = $mkBooking($phoneA, $sRet, addDaysISO($DT, 7), 'confirmed', 110.0);
    check('one other-way ticket pays for ONE return discount (a used one is counted against it)', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $dep7) === 0.0);
    Database::update('bookings', ['status' => 'cancelled'], 'id = :i', ['i' => $bUsed]);
    check('  …and is free again once that return ticket is cancelled', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $dep7) === 5.0);
    $set('return_discount_pct', 0, 'float');
    check('switch back to 0: nothing, even with the other-way ticket there', Fare::returnDiscountPct($phoneA, (int) $ret['id'], $dep7) === 0.0
        && (float) Fare::quote(2000.0, 1, 0, 0, 0, '', $phoneA, (int) $ret['id'], $dep7)['total'] === $legacyQuote(2000.0, 1, 0, 0, 0, '', $phoneA, (int) $ret['id'], $dep7));

    /* ---- desk sales through Quick Ticket ---------------------------------- */
    echo "\n-- Desk sale with luggage + a lap infant (P8-3f / P4-4d)\n";
    $set('whatsapp_notify_customer', false, 'bool');
    $set('whatsapp_notify_admin', false, 'bool');
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
    Settings::set('daily_service_on', true, 'bool', 'booking', true);
    Settings::flush();
    $agentId = (int) Database::insert('admins', ['username' => 'u9x1-' . $tag, 'password_hash' => password_hash('U9x1@' . $tag, PASSWORD_BCRYPT),
        'full_name' => 'X1 Test Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
    $staffRow = ['id' => $agentId, 'username' => 'u9x1-' . $tag, 'role' => 'agent', 'full_name' => 'X1 Test Agent'];
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'u9x1-' . $tag, 'role' => 'agent', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
    $preSched = array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $DS]), 'id'));
    $sale = static fn(string $name, string $phone, array $more = []) => QuickTicket::sell($more + ['name' => $name, 'phone' => $phone, 'date' => $DS,
        'direction' => 'toNepal', 'pay' => 'cash', 'gender' => 'Male'], $staffRow);
    $row  = static fn(int $id): array => Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $id]) ?? [];
    $comm = static fn(int $id): float => (float) Database::scalar("SELECT COALESCE(SUM(amount), 0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission'", ['b' => $id], 0);

    // a) switches OFF: the extras the desk typed are ignored, the total is the plan's fare
    $plan0 = QuickTicket::plan(['date' => $DS, 'direction' => 'toNepal', 'phone' => $phoneB]);
    $s0 = $sale('X1 Off Pax', $phoneB, ['luggagePieces' => 2, 'infantCount' => 1]);
    $b0 = $row((int) $s0['bookingId']);
    check('switches off: the sale total = the plan\'s fare (nothing added)', abs((float) $b0['total_amount'] - (float) $plan0['fare']['total']) < 0.01,
        $b0['total_amount'] . ' / ' . $plan0['fare']['total']);
    check('  …and no extras stored', (int) $b0['luggage_pieces'] === 0 && (float) $b0['luggage_fee'] === 0.0 && (int) $b0['infant_count'] === 0);

    // b) switches ON: 2 pieces x 150 + one free infant
    $set('luggage_fee_per_piece', 150, 'int');
    $set('infant_seat_on', true, 'bool');
    $set('infant_seat_fare', 0, 'int');
    $s1 = $sale('X1 Lug Pax', $phoneB, ['luggagePieces' => 2, 'infantCount' => 3]);
    $b1 = $row((int) $s1['bookingId']);
    check('luggage on: total = the fare + 2 x 150', abs((float) $b1['total_amount'] - ((float) $b0['total_amount'] + 300)) < 0.01,
        $b1['total_amount'] . ' vs ' . $b0['total_amount'] . ' + 300');
    check('  columns: 2 pieces / 300, one infant (one seat = one lap) / free', (int) $b1['luggage_pieces'] === 2 && (float) $b1['luggage_fee'] === 300.0
        && (int) $b1['infant_count'] === 1 && (float) $b1['infant_fee'] === 0.0);
    $pay1 = (float) Database::scalar('SELECT amount FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b1['id']], 0);
    check('  the payment taken = the new total', abs($pay1 - (float) $b1['total_amount']) < 0.01, (string) $pay1);
    check('  commission is the same as the sale without extras (never on luggage)', abs($comm((int) $b1['id']) - $comm((int) $b0['id'])) < 0.01,
        $comm((int) $b1['id']) . ' / ' . $comm((int) $b0['id']));
    check('  the desk result says the total with the extras', abs((float) $s1['total'] - (float) $b1['total_amount']) < 0.01);
    $png0 = Ticket::pngPath((int) $b0['id'], true); $png1 = Ticket::pngPath((int) $b1['id'], true);
    $i0 = is_file($png0) ? @getimagesize($png0) : false; $i1 = is_file($png1) ? @getimagesize($png1) : false;
    check('PNG: the ticket with extras is exactly one strip (74 px) taller; others unchanged', $i0 !== false && $i1 !== false && (int) $i1[1] - (int) $i0[1] === 74,
        ($i0 ? $i0[1] : '?') . ' -> ' . ($i1 ? $i1[1] : '?'));
    $pdf1 = Ticket::pdfPath((int) $b1['id'], true);
    $raw  = is_file($pdf1) ? (string) file_get_contents($pdf1) : '';
    $plain = $raw;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
        foreach ($m[1] as $s) { $z = @gzuncompress($s); if ($z !== false) { $plain .= $z; } }
    }
    check('PDF renders and carries the extras line', str_starts_with($raw, '%PDF') && str_contains($plain, 'Extra luggage 2 pc = INR 300'));

    // c) a paid infant: 50 each
    $set('infant_seat_fare', 50, 'int');
    $s2 = $sale('X1 Inf Pax', $phoneB, ['infantCount' => 1]);
    $b2 = $row((int) $s2['bookingId']);
    check('infant_seat_fare 50: total = the fare + 50, no luggage', abs((float) $b2['total_amount'] - ((float) $b0['total_amount'] + 50)) < 0.01
        && (int) $b2['infant_count'] === 1 && (float) $b2['infant_fee'] === 50.0 && (int) $b2['luggage_pieces'] === 0, (string) $b2['total_amount']);

    // c2) review fix: a per-seat cancel on a two-seat ticket with luggage - the luggage
    //     belongs to the ticket, so only one seat's FARE share comes off (never half the luggage)
    $set('infant_seat_on', false, 'bool');
    $s3 = $sale('X1 Two Pax', $phoneB, ['seats' => 2, 'luggagePieces' => 2]);
    $b3 = $row((int) $s3['bookingId']);
    $seats3 = array_map('strval', array_column(Database::fetchAll(
        'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL ORDER BY seat_no', ['b' => (int) $b3['id']]), 'seat_no'));
    if (count($seats3) === 2 && (float) $b3['luggage_fee'] === 300.0) {
        $fare2 = (float) $b3['total_amount'] - 300;
        BookingService::cancelSeat((int) $b3['id'], $seats3[1], $agentId, 'X1 review: one seat off');
        $b3b = $row((int) $b3['id']);
        check('per-seat cancel: one seat\'s fare comes off, the luggage stays on the ticket',
            abs((float) $b3b['total_amount'] - ($fare2 / 2 + 300)) < 0.01 && (float) $b3b['luggage_fee'] === 300.0,
            $b3['total_amount'] . ' -> ' . $b3b['total_amount'] . ' (fare ' . $fare2 . ' for 2 + luggage 300)');
    } else {
        check('a two-seat desk sale with 2 luggage pieces', false, count($seats3) . ' seats, luggage ' . ($b3['luggage_fee'] ?? '?'));
    }

    // d) a return-way desk sale for the passenger who holds the outbound ticket
    echo "\n-- Return-way desk sale stores and prints it\n";
    $set('luggage_fee_per_piece', 0, 'int');
    $set('infant_seat_on', false, 'bool');
    $set('return_discount_pct', 10, 'float');
    $bOutC = $mkBooking($phoneC, $sOut, $DT, 'confirmed');
    $retDate = addDaysISO($DT, 3);
    $planR = QuickTicket::plan(['date' => $retDate, 'direction' => 'toIndia', 'phone' => $phoneC]);
    check('the desk plan already shows the return discount', (float) ($planR['fare']['returnDiscount'] ?? 0) > 0, (string) ($planR['fare']['returnDiscount'] ?? 'none'));
    $preSched = array_merge($preSched, array_map('intval', array_column(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $retDate]), 'id')));
    $sR = QuickTicket::sell(['name' => 'X1 Return Pax', 'phone' => $phoneC, 'date' => $retDate, 'direction' => 'toIndia', 'pay' => 'cash', 'gender' => 'Male'], $staffRow);
    $bR = $row((int) $sR['bookingId']);
    check('sale total = the plan total (shown = charged)', abs((float) $bR['total_amount'] - (float) $planR['fare']['total']) < 0.01,
        $bR['total_amount'] . ' / ' . $planR['fare']['total']);
    check('bookings.return_discount = 10% of the fare', abs((float) $bR['return_discount'] - round((float) $bR['base_total'] * 0.10)) < 0.01, (string) $bR['return_discount']);
    $tk = Ticket::discountSource($bR);
    check('the ticket names it "फिर्ती छुट · Return"', str_contains($tk, 'फिर्ती छुट · Return'), $tk);
    $pngR = Ticket::pngPath((int) $bR['id'], true);
    check('its PNG renders', is_file($pngR) && @getimagesize($pngR) !== false);
} catch (Throwable $e) {
    check('database part ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone IN (:a, :b, :c)', ['a' => $phoneA, 'b' => $phoneB, 'c' => $phoneC]) as $r) {
        $bid = (int) $r['id'];
        $t = Database::fetch('SELECT png_path, pdf_path FROM tickets WHERE booking_id = :b', ['b' => $bid]);
        foreach (['png_path', 'pdf_path'] as $c) {
            if (!empty($t[$c])) { @unlink(TICKET_PATH . '/' . basename((string) $t[$c])); }
        }
        foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) { @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . $ext); }
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
        foreach (['agent_ledger', 'tickets', 'payments', 'coupon_redemptions', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $tb) {
            try { Database::delete($tb, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
    }
    // only the schedules THIS run created (its fixtures + the ones the sales made lazily)
    $mine = $madeSch;
    foreach ([$DS, addDaysISO($DT, 3)] as $d) {
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            if (!in_array((int) $s['id'], $preSched ?? [], true)) { $mine[] = (int) $s['id']; }
        }
    }
    foreach (array_unique($mine) as $sid) {
        if (Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0) > 0) { continue; }   // someone else's sale
        foreach (['seat_locks', 'schedule_unit_locks', 'booking_seats'] as $tb) {
            try { Database::delete($tb, 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
        try { Database::delete('schedules', 'id = :s', ['s' => $sid]); } catch (Throwable $e) {}
    }
    if ($agentId > 0) {
        try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    }
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $restore();
}
check('cleanup left no test booking behind', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone IN (:a, :b, :c)', ['a' => $phoneA, 'b' => $phoneB, 'c' => $phoneC], 0) === 0);
check('every flipped setting is back', (static function () use ($PINNED, $prior): bool {
    foreach ($PINNED as $k) {
        $now = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
        if (($prior[$k]['svalue'] ?? null) !== ($now['svalue'] ?? null)) { return false; }
    }
    return true;
})());
finish();
