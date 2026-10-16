<?php
/**
 * auto-offer-test.php — the office's dated running offer (Admin → Offers,
 * auto_apply = 1) is the one switch for a Dashain/Tihar discount:
 *   - nothing configured → nobody gets a discount;
 *   - inside its dates → Fare::quote() (web, counter create(), QuickBot) and
 *     the desk form's counterSale() all take it, and it is redeemed once;
 *   - before its start date / switched off → nothing again;
 *   - a register line that typed the collected amount, or a clerk's own
 *     discount, is never stacked with it.
 *
 *   php tests/auto-offer-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const AO_PHONE = '910000771';
const AO_CODE  = 'TESTAUTOOFFER';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Running offer (auto-apply, dated) ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 27);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$today = todayISO();

$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . AO_PHONE . "%'") as $r) {
        Database::delete('coupon_redemptions', 'booking_id = :i', ['i' => (int) $r['id']]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
    Database::delete('coupons', 'code = :c', ['c' => AO_CODE]);
};

if ($route === null) { echo "  \033[33mSKIP\033[0m  no active sleeper route\n"; exit(0); }

$rid = (int) $route['id'];
$cleanup();
// Other running offers in the fixture DB would change the arithmetic below.
Database::run('UPDATE coupons SET auto_apply = 2 WHERE auto_apply = 1');

$adminId = (int) Database::scalar("SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0);
$sell = function (array $seats, array $extra) use ($route, $rid, $D, $adminId) {
    $sched = Seats::schedule($rid, $D);
    return BookingService::counterSale($route, (int) $sched['id'], $D, $seats,
        array_merge(['name' => 'Offer Test', 'phone' => AO_PHONE . '0', 'paymentMethod' => 'cash'], $extra),
        $adminId, 'admin');
};
$addOffer = function (string $from, string $until, int $active = 1) {
    Database::delete('coupons', 'code = :c', ['c' => AO_CODE]);
    Database::insert('coupons', [
        'code' => AO_CODE, 'title' => 'Test Dashain offer', 'discount_type' => 'percent',
        'discount_value' => 10, 'max_discount' => null, 'min_amount' => 0, 'route_id' => null,
        'valid_from' => $from, 'valid_until' => $until, 'is_active' => $active, 'auto_apply' => 1,
        'usage_limit' => null, 'used_count' => 0, 'per_user_limit' => 0,
    ]);
};

try {
    $q0 = Fare::quote(2000.0, 1, 0, 0, 0, '', AO_PHONE . '0', $rid);
    check('no offer configured: quote has no discount', (float) $q0['couponDiscount'] === 0.0 && (float) $q0['total'] === 2000.0,
        'disc=' . $q0['couponDiscount'] . ' total=' . $q0['total']);

    $addOffer($today, addDaysISO($today, 3));
    $q1 = Fare::quote(2000.0, 1, 0, 0, 0, '', AO_PHONE . '0', $rid);
    check('offer dated today: quote takes 10% (₹200)', (float) $q1['couponDiscount'] === 200.0 && (float) $q1['total'] === 1800.0,
        'disc=' . $q1['couponDiscount'] . ' total=' . $q1['total']);
    check('quote names the offer code', (string) $q1['couponCode'] === AO_CODE, 'code=' . $q1['couponCode']);

    $b1 = $sell(['L20'], []);
    $base1 = (float) $b1['base_total'];
    check('desk form sale (no amount, no clerk discount) takes the offer',
        (float) $b1['coupon_discount'] === round($base1 * 0.10) && (float) $b1['total_amount'] === $base1 - round($base1 * 0.10),
        'base=' . $base1 . ' disc=' . $b1['coupon_discount'] . ' total=' . $b1['total_amount']);
    check('desk sale stores the offer code', (string) ($b1['coupon_code'] ?? '') === AO_CODE, 'code=' . ($b1['coupon_code'] ?? 'NULL'));
    $red = (int) Database::scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE booking_id = :b', ['b' => (int) $b1['id']], 0);
    $used = (int) Database::scalar('SELECT used_count FROM coupons WHERE code = :c', ['c' => AO_CODE], 0);
    check('desk sale redeems the offer once', $red === 1 && $used === 1, "redemptions=$red used=$used");
    $pay = (float) Database::scalar('SELECT amount FROM payments WHERE booking_id = :b LIMIT 1', ['b' => (int) $b1['id']], 0);
    check('payment row = post-offer total', $pay === (float) $b1['total_amount'], 'paid=' . $pay);

    $b2 = $sell(['L21'], ['amount' => 2000]);
    check('register line with typed amount: no offer', (float) $b2['coupon_discount'] === 0.0 && (float) $b2['total_amount'] === 2000.0,
        'disc=' . $b2['coupon_discount'] . ' total=' . $b2['total_amount']);

    $b3 = $sell(['L22'], ['discountType' => 'flat', 'discountValue' => 50]);
    /* 7 Oct 2026 (live audit, includes/booking.php): the two never stack —
       the BIGGER of the clerk's own discount and the running offer wins. A
       ₹50 flat against a 10% offer on a ₹2000 fare means the passenger pays
       ₹1800, not ₹1950 and not ₹1750. Before that fix any clerk discount,
       even ₹50, silently replaced the offer. */
    $offerCut = round(((float) $b3['base_total']) * 0.10, 2);
    check("clerk's ₹50 loses to the bigger offer, and the two never stack",
        (float) $b3['coupon_discount'] === $offerCut && (string) ($b3['coupon_code'] ?? '') === AO_CODE,
        'disc=' . $b3['coupon_discount'] . ' (offer ' . $offerCut . ', clerk 50) code=' . ($b3['coupon_code'] ?? 'NULL'));

    $addOffer(addDaysISO($today, 1), addDaysISO($today, 5));
    $q2 = Fare::quote(2000.0, 1, 0, 0, 0, '', AO_PHONE . '0', $rid);
    check('offer starting tomorrow: no discount today', (float) $q2['couponDiscount'] === 0.0, 'disc=' . $q2['couponDiscount']);

    $addOffer(addDaysISO($today, -5), addDaysISO($today, -1));
    $q3 = Fare::quote(2000.0, 1, 0, 0, 0, '', AO_PHONE . '0', $rid);
    check('offer that ended yesterday: no discount', (float) $q3['couponDiscount'] === 0.0, 'disc=' . $q3['couponDiscount']);

    $addOffer($today, addDaysISO($today, 3), 0);
    $q4 = Fare::quote(2000.0, 1, 0, 0, 0, '', AO_PHONE . '0', $rid);
    check('offer switched OFF: no discount', (float) $q4['couponDiscount'] === 0.0, 'disc=' . $q4['couponDiscount']);
    $b4 = $sell(['L23'], []);
    check('offer switched OFF: desk sale pays full fare', (float) $b4['coupon_discount'] === 0.0, 'disc=' . $b4['coupon_discount']);
} catch (Throwable $e) {
    check('ran without a fatal', false, $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    Database::run('UPDATE coupons SET auto_apply = 1 WHERE auto_apply = 2');
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
