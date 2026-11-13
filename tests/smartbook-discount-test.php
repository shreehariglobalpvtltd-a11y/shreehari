<?php
/**
 * smartbook-discount-test.php — 13 Nov 2026 (mg17): the desk discount on the
 * fast ticket form (SmartBook).
 *
 *   - who may give one: admin / counter sessions only (never customer, agent)
 *   - the arithmetic is BookingService::create()'s: flat or % of the base,
 *     capped at counter_max_discount_pct, never above the payable, never
 *     stacked on a running offer (the bigger wins)
 *   - a real quote: the total drops by exactly the discount; a customer who
 *     sends the same fields gets the full price
 *
 * Read-only against the TEST database.   php tests/smartbook-discount-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    $ok ? $PASS++ : $FAIL++;
    echo '  ' . ($ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m") . "  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
}

echo "-- who may discount --\n";
$in = ['discountType' => 'flat', 'discountValue' => 200];
check('admin: yes', SmartBook::deskDiscountIn($in, 'admin') === ['flat', 200.0]);
check('counter: yes', SmartBook::deskDiscountIn($in, 'counter') === ['flat', 200.0]);
check('agent: no', SmartBook::deskDiscountIn($in, 'agent') === null);
check('customer: no', SmartBook::deskDiscountIn($in, 'customer') === null);
check('zero / negative / rubbish: no', SmartBook::deskDiscountIn(['discountType' => 'flat', 'discountValue' => 0], 'admin') === null
    && SmartBook::deskDiscountIn(['discountType' => 'flat', 'discountValue' => -50], 'admin') === null
    && SmartBook::deskDiscountIn(['discountType' => 'gift', 'discountValue' => 50], 'admin') === null
    && SmartBook::deskDiscountIn(['discountType' => 'flat', 'discountValue' => 'abc'], 'admin') === null);
check('percent is held to 100', SmartBook::deskDiscountIn(['discountType' => 'percent', 'discountValue' => 500], 'admin') === ['percent', 100.0]);

echo "-- the arithmetic (cap " . Settings::getFloat('counter_max_discount_pct', 15.0) . "%) --\n";
$cap  = max(0.0, Settings::getFloat('counter_max_discount_pct', 15.0));
$base = ['base' => 2000.0, 'total' => 2000.0, 'couponDiscount' => 0.0, 'couponCode' => null];
$p = SmartBook::withDeskDiscount($base, 'flat', 100);
check('flat 100 off 2000 = 1900', abs($p['total'] - 1900.0) < 0.01 && abs($p['deskDiscount'] - 100.0) < 0.01 && empty($p['deskCut']), (string) $p['total']);
$p = SmartBook::withDeskDiscount($base, 'percent', 5);
check('5% off 2000 = 1900', abs($p['total'] - 1900.0) < 0.01, (string) $p['total']);
$p = SmartBook::withDeskDiscount($base, 'flat', 5000);
$capAmt = round(2000 * $cap / 100, 2);
check('a huge discount is cut to the cap and says so', abs($p['total'] - (2000 - $capAmt)) < 0.01 && !empty($p['deskCut']), $p['total'] . ' (cap ' . $capAmt . ')');
$offer = ['base' => 2000.0, 'total' => 1800.0, 'couponDiscount' => 200.0, 'couponCode' => 'DASHAIN10'];
$p = SmartBook::withDeskDiscount($offer, 'flat', 100);
check('a smaller desk discount never stacks on an offer', abs($p['total'] - 1800.0) < 0.01 && $p['couponCode'] === 'DASHAIN10' && empty($p['deskDiscount']), (string) $p['total']);
if ($capAmt > 200) {
    $p = SmartBook::withDeskDiscount($offer, 'flat', 250);
    check('a bigger desk discount replaces the offer (not added to it)', abs($p['total'] - 1750.0) < 0.01 && $p['couponCode'] === null, (string) $p['total']);
}
$p = SmartBook::withDeskDiscount(['base' => 0.0, 'total' => 0.0, 'couponDiscount' => 0.0], 'flat', 100);
check('nothing to discount: unchanged', (float) $p['total'] === 0.0 && !isset($p['deskDiscount']));

echo "-- a real quote --\n";
$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($super === null) {
    echo "  (no superadmin in the test database — quote part skipped)\n";
} else {
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $super['id'], 'username' => (string) $super['username'], 'full_name' => (string) $super['full_name'],
        'role' => (string) $super['role'], 'permissions' => jsonColumn($super['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
    $mode = SmartBook::sessionMode('counter');
    try {
        $plain = SmartBook::quote(['direction' => 'toNepal', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], $mode);
        $with  = SmartBook::quote(['direction' => 'toNepal', 'pax' => [['gender' => 'Male', 'band' => 'adult']], 'discountType' => 'flat', 'discountValue' => 100], $mode);
        if (($plain['price'] ?? null) === null || (float) $plain['price']['discount'] > 0) {
            echo "  (no priced seat, or an offer is running — the live comparison is skipped)\n";
        } else {
            $d = (float) $plain['price']['total'] - (float) $with['price']['total'];
            check('staff quote: the total is 100 lower', abs($d - 100.0) < 0.01, $plain['price']['total'] . ' → ' . $with['price']['total']);
            check('the quote says what the desk gave', abs((float) $with['price']['desk'] - 100.0) < 0.01 && abs((float) $with['price']['discount'] - 100.0) < 0.01);
            unset($_SESSION[ADMIN_SESSION_KEY]);
            $cust = SmartBook::quote(['direction' => 'toNepal', 'pax' => [['gender' => 'Male', 'band' => 'adult']], 'discountType' => 'flat', 'discountValue' => 100], SmartBook::sessionMode());
            check('a customer sending the same fields pays the full price', ($cust['price'] ?? null) === null || (float) ($cust['price']['desk'] ?? 0) === 0.0);
        }
    } catch (Throwable $ex) {
        echo '  (quote not available on this database: ' . $ex->getMessage() . ")\n";
    }
    unset($_SESSION[ADMIN_SESSION_KEY]);
}

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
