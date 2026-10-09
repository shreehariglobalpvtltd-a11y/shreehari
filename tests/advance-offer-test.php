<?php
/**
 * =====================================================================
 *  advance-offer-test.php — "10% off when booked 24 hours before
 *  departure" (Dashain–Tihar, 6 Oct 2026).
 *
 *    php tests/advance-offer-test.php
 *
 *  coupons.min_hours_ahead: refused with no known departure or inside the
 *  window, given outside it (capped by the max); the same through
 *  Fare::quote() / autoOffer(); Fare::departTs() reads the bus time; the
 *  bot / AI / site / pamphlet all SAY the offer. Test database only; the
 *  coupon it makes is removed, other running offers are restored.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/offercard.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const AV_CODE = 'TESTADV24';
$hasCol = Database::fetch("SHOW COLUMNS FROM coupons LIKE 'min_hours_ahead'") !== null;
Database::run('UPDATE coupons SET auto_apply = 3 WHERE auto_apply = 1');

try {
    check('coupons.min_hours_ahead exists (database/upgrade-2026-10-06-dashain-offer.sql)', $hasCol);
    if (!$hasCol) {
        throw new RuntimeException('apply the migration first');
    }
    Database::delete('coupons', 'code = :c', ['c' => AV_CODE]);
    Database::insert('coupons', [
        'code' => AV_CODE, 'title' => 'Dashain-Tihar offer 10% (booked 24h ahead)', 'discount_type' => 'percent',
        'discount_value' => 10, 'max_discount' => 2000, 'min_amount' => 0, 'route_id' => null,
        'valid_from' => todayISO(), 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'is_active' => 1, 'auto_apply' => 1,
        'usage_limit' => null, 'used_count' => 0, 'per_user_limit' => 0, 'min_hours_ahead' => 24,
    ]);

    echo "\n— the 24-hour rule\n";
    $r = Fare::couponDiscount(AV_CODE, 2000, '9800000001', null, null);
    check('no known departure → no discount (never guessed in the passenger\'s favour)', empty($r['ok']));
    $r = Fare::couponDiscount(AV_CODE, 2000, '9800000001', null, time() + 23 * 3600);
    check('bus leaves in 23 h → refused, and says why', empty($r['ok']) && str_contains((string) $r['error'], '24 hours'), (string) ($r['error'] ?? ''));
    $r = Fare::couponDiscount(AV_CODE, 2000, '9800000001', null, time() + 25 * 3600);
    check('bus leaves in 25 h → 10% = ₹200', !empty($r['ok']) && (float) $r['amount'] === 200.0, json_encode($r['amount'] ?? null));
    $r = Fare::couponDiscount(AV_CODE, 40000, '9800000001', null, time() + 48 * 3600);
    check('a huge group booking is capped at the max (₹2,000)', !empty($r['ok']) && (float) $r['amount'] === 2000.0);
    $a = Fare::autoOffer(2000, '9800000001', null, time() + 30 * 3600);
    check('autoOffer gives it automatically (no code)', !empty($a['ok']) && $a['code'] === AV_CODE && (float) $a['amount'] === 200.0);
    check('  but not for a bus 3 hours away', empty(Fare::autoOffer(2000, '9800000001', null, time() + 3 * 3600)['ok']));
    $q = Fare::quote(4000.0, 2, 0, 0, 0, '', '9800000001', null, time() + 72 * 3600);
    check('Fare::quote: 2 seats ₹4,000 → ₹3,600, offer named in the breakdown',
        (float) $q['total'] === 3600.0 && $q['couponCode'] === AV_CODE && str_contains(json_encode($q['breakdown'], JSON_UNESCAPED_UNICODE), 'Dashain'), json_encode($q['total']));
    $q = Fare::quote(4000.0, 2, 0, 0, 0, '', '9800000001', null, time() + 2 * 3600);
    check('  same-day departure → full fare', (float) $q['total'] === 4000.0);
    $q = Fare::quote(4000.0, 2, 0, 0, 0, '', '9800000001', null);
    check('  old callers without a departure → full fare (safe)', (float) $q['total'] === 4000.0);
    $twice = Fare::couponDiscount(AV_CODE, 2000, '9800000001', null, time() + 30 * 3600);
    check('per_user_limit 0 → the same passenger gets it on every trip', !empty($twice['ok']));

    echo "\n— the bus time\n";
    $s = Database::fetch("SELECT s.*, r.dep_time FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.travel_date >= CURDATE() ORDER BY s.travel_date LIMIT 1");
    if ($s !== null) {
        $want = strtotime($s['travel_date'] . ' ' . ($s['dep_time_override'] ?: $s['dep_time']));
        check('departTs = travel date + the departure time', Fare::departTs($s) === $want, Fare::departTs($s) . ' vs ' . $want);
        check('  read from the schedule id alone', Fare::departTs(['id' => (int) $s['id']]) === $want);
    }
    check('no schedule → null', Fare::departTs(null) === null);

    echo "\n— everyone says it\n";
    $run = Fare::runningOffers();
    $mine = array_values(array_filter($run, static fn ($o) => $o['code'] === AV_CODE))[0] ?? null;
    check('runningOffers carries the 24 h rule', $mine !== null && (int) $mine['ahead'] === 24);
    check('  offerLine says it', $mine !== null && str_contains(Fare::offerLine($mine), '24 hours or more before departure'), $mine ? Fare::offerLine($mine) : '');
    require_once INCLUDE_PATH . '/aiprompt.php';
    check('the AI chat prompt knows the offer', str_contains(ai_system_prompt(), '24 hours or more before departure'));
    $o = OfferCard::offer();
    check('the pamphlet picks the running offer', $o !== null && $o['code'] === AV_CODE);
    $png = $o !== null ? OfferCard::png('ne', $o, '+91 91570 01507', 'shreehariglobal.in', null) : '';
    check('  and draws a 1080×1350 PNG', str_starts_with($png, "\x89PNG") && ($sz = @getimagesizefromstring($png)) && $sz[0] === 1080 && $sz[1] === 1350);
    /* 9 Oct 2026 (site facts): the caption quotes the fare board's lowest
       pickup fare ("from"), not the direction default - pickups are priced
       one by one (Fare::board()). */
    $f = OfferCard::fareFrom('go');
    $cap = $o !== null ? OfferCard::caption($o, '+91 91570 01507', 'shreehariglobal.in') : '';
    check('  its caption shows the real fares after 10%', str_contains($cap, '₹' . number_format($f['min']) . ' → ₹' . number_format(round($f['min'] * 0.9))), $cap);
    $root = dirname(__DIR__);
    check('the site boot carries the offers (top ribbon)', str_contains((string) file_get_contents($root . '/index.php'), "'offers'   =>"));
    check('  the ribbon is in the page and rendered', str_contains((string) file_get_contents($root . '/app.template.html'), 'id="offerRibbon"')
        && str_contains((string) file_get_contents($root . '/assets/js/05-router.js'), 'function renderOfferRibbon'));
    check('  checkout charges what the server charges (offerFor mirror)', str_contains((string) file_get_contents($root . '/assets/js/07-checkout.js'), 'const offerFor = (amount)'));
    check('the WhatsApp hello carries the offer line', str_contains((string) file_get_contents($root . '/includes/wafaq.php'), 'Fare::runningOffers()[0]'));
    check('every quote path passes the departure', str_contains((string) file_get_contents($root . '/includes/booking.php'), 'Fare::departTs($schedule, $route)')
        && str_contains((string) file_get_contents($root . '/includes/quickticket.php'), 'Fare::departTs($schedule, $route)')
        && str_contains((string) file_get_contents($root . '/api/quote.php'), 'Fare::departTs('));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'advance-offer-test.php'));
} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Database::delete('coupons', 'code = :c', ['c' => AV_CODE]);
    Database::run('UPDATE coupons SET auto_apply = 1 WHERE auto_apply = 3');
}

echo "\nadvance-offer: $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
