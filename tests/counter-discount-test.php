<?php
/**
 * counter-discount-test.php — proves the admin/agent Counter discount (Task 9):
 * flat ₹ or % off the base fare, clamped to counter_max_discount_pct, with a
 * clean Base → Discount → Final breakdown stored on the booking.
 *
 * Creates real counter sales on a far-future date and cleans up after itself.
 *
 *   php -c .claude/php-dev.ini tests/counter-discount-test.php
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

const CD_PHONE = '910000991';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Counter discount (Task 9) ===\n\n";

Settings::set('counter_max_discount_pct', 15, 'float', 'booking', true);

$w = bookingWindow(); $D = addDaysISO($w['from'], 26);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");

$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . CD_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};

if ($route === null) { echo "  \033[33mSKIP\033[0m  no active sleeper route\n"; }
else {
    $rid = (int) $route['id'];
    $adminId = (int) Database::scalar("SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0);
    $sched = Seats::schedule($rid, $D);
    $cleanup();

    $sell = function (array $seats, array $extra) use ($route, $rid, $D) {
        $sched = Seats::schedule($rid, $D);
        return BookingService::counterSale($route, (int) $sched['id'], $D, $seats,
            array_merge(['name' => 'Disc Test', 'phone' => CD_PHONE . '0', 'paymentMethod' => 'cash'], $extra),
            (int) Database::scalar("SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0),
            'admin');
    };

    try {
        // Base with amount override 2000, flat ₹200 discount → 1800.
        $b1 = $sell(['L20'], ['amount' => 2000, 'discountType' => 'flat', 'discountValue' => 200]);
        check("flat ₹200 off ₹2000: base_total=2000", (float) $b1['base_total'] === 2000.0, 'base=' . $b1['base_total']);
        check("flat ₹200 off ₹2000: coupon_discount=200", (float) $b1['coupon_discount'] === 200.0, 'disc=' . $b1['coupon_discount']);
        check("flat ₹200 off ₹2000: total_amount=1800", (float) $b1['total_amount'] === 1800.0, 'total=' . $b1['total_amount']);

        // Percent 10% off 2000 → 200 off → 1800.
        $b2 = $sell(['L21'], ['amount' => 2000, 'discountType' => 'percent', 'discountValue' => 10]);
        check("10% off ₹2000: discount=200 & total=1800",
            (float) $b2['coupon_discount'] === 200.0 && (float) $b2['total_amount'] === 1800.0,
            'disc=' . $b2['coupon_discount'] . ' total=' . $b2['total_amount']);

        // Over-cap: 50% requested but cap is 15% → capped at 300 off 2000 → 1700.
        $b3 = $sell(['L22'], ['amount' => 2000, 'discountType' => 'percent', 'discountValue' => 50]);
        check("50% requested but capped to 15%: discount=300 (not 1000)",
            (float) $b3['coupon_discount'] === 300.0, 'disc=' . $b3['coupon_discount']);
        check("capped sale: total_amount=1700", (float) $b3['total_amount'] === 1700.0, 'total=' . $b3['total_amount']);

        // A huge flat discount is also capped to 15% of base.
        $b4 = $sell(['L23'], ['amount' => 2000, 'discountType' => 'flat', 'discountValue' => 5000]);
        check("flat ₹5000 capped to 15% (₹300): total=1700", (float) $b4['total_amount'] === 1700.0, 'total=' . $b4['total_amount']);

        // No discount → total == base.
        $b5 = $sell(['L24'], ['amount' => 2000]);
        check("no discount: total==base==2000 & coupon_discount=0",
            (float) $b5['total_amount'] === 2000.0 && (float) $b5['coupon_discount'] === 0.0);

        // The payment row must record the POST-discount amount.
        $pay = Database::scalar('SELECT amount FROM payments WHERE booking_id = :b LIMIT 1', ['b' => (int) $b1['id']], 0);
        check("payments.amount = post-discount total (1800)", (float) $pay === 1800.0, 'paid=' . $pay);
    } catch (Throwable $e) {
        check('counter sales ran without a fatal', false, $e->getMessage());
    } finally {
        $cleanup();
    }
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
