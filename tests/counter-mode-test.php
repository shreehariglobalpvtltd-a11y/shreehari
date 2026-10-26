<?php
/**
 * =====================================================================
 *  counter-mode-test.php — Module 3 (3 Sep 2026): ONE booking path for
 *  customer, agent and admin. BookingService::create() with a $seller
 *  context is the counter; without it, it is byte-for-byte the customer.
 *
 *    • 5-seat counter sale → confirmed, payment verified/offline/cash by
 *      the seller, ticket issued, ledger commission accrued once
 *    • pricing PARITY: the same seats cost the same online and at the
 *      counter (group discount for 5+ applies to both)
 *    • counter discount clamped to counter_max_discount_pct
 *    • seat cap applies to the counter too (max+1 → refused)
 *    • route restriction refuses before anything is written
 *    • a typed agent code is ignored when a seller is present
 *    • the anonymous path is unchanged (pending, source web, no seller)
 *
 *  Creates real bookings on a far-future date and cleans up after itself.
 *    php -c .claude/php-dev.ini tests/counter-mode-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const CM_PHONE = '910000772';   // prefix: every test booking's phone starts with this

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected a refusal)', false); }
    catch (Throwable $e) { check($l, $needle === '' || stripos($e->getMessage(), $needle) !== false, $e->getMessage()); }
}

echo "\n=== Counter mode — one booking path (Module 3) ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 27);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
$rid = (int) $route['id'];
$other = Database::fetch("SELECT id FROM routes WHERE id <> :r AND is_active = 1 ORDER BY id LIMIT 1", ['r' => $rid]);

Settings::set('counter_max_discount_pct', 15, 'float', 'booking', true);
Settings::set('max_seats_per_booking', 6, 'int', 'booking', true);
Settings::set('counter_max_seats_per_booking', 20, 'int', 'booking', false);

/* Throwaway counter agent (role agent, unrestricted, direct tier). */
$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'cm-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'cm-agent', 'password_hash' => password_hash('Cm@12345', PASSWORD_BCRYPT),
        'full_name' => 'Counter Mode Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
} else {
    Database::update('admins', ['role' => 'agent', 'is_active' => 1], 'id = :i', ['i' => $agentId]);
}

$cleanup = function () use ($D, $agentId): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . CM_PHONE . "%' OR (contact_phone = '0000000000' AND sold_by_admin_id = " . (int) $agentId . ")") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
    try { AgentWallet::setRoutePermissions($agentId, [], 0); } catch (Throwable $e) {}
};
$cleanup();

$req = function (array $seats, string $phoneSuffix, array $extra = []) use ($rid, $D): array {
    $pax = [];
    foreach ($seats as $i => $s) { $pax[] = ['seat' => $s, 'name' => 'Counter Pax ' . ($i + 1), 'age' => 30 + $i, 'gender' => 'Male']; }
    return array_merge([
        'routeId' => $rid, 'travelDate' => $D, 'seats' => $seats, 'passengers' => $pax,
        'contact' => ['phone' => CM_PHONE . $phoneSuffix], 'bookingMode' => 'sharing',
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '', 'referralCode' => '',
    ], $extra);
};
$seller = function (array $over = []) use ($agentId): array {
    return array_merge(['adminId' => $agentId, 'source' => 'agent', 'paymentMethod' => 'cash'], $over);
};

try {
    Seats::schedule($rid, $D);   // materialise the day

    echo "-- 1. Five seats at the counter --\n";
    $b1 = BookingService::create($req(['L11', 'L12', 'L13', 'L14', 'L15'], '1'), $seller(['note' => 'walk-in family']));
    check('booking created', !empty($b1['pnr']), (string) ($b1['pnr'] ?? ''));
    check('status confirmed immediately', ($b1['status'] ?? '') === 'confirmed', (string) ($b1['status'] ?? ''));
    check('source = agent', ($b1['source'] ?? '') === 'agent', (string) ($b1['source'] ?? ''));
    check('attributed to the seller', (int) ($b1['sold_by_admin_id'] ?? 0) === $agentId);
    check('not a COD hold, no expiry', empty($b1['is_cod']) && empty($b1['expires_at']));
    $pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b1['id']]);
    check('payment verified / offline / cash by the seller',
        $pay !== null && $pay['status'] === 'verified' && $pay['mode'] === 'offline' && $pay['method'] === 'cash' && (int) $pay['verified_by'] === $agentId,
        json_encode(['status' => $pay['status'] ?? null, 'mode' => $pay['mode'] ?? null, 'method' => $pay['method'] ?? null, 'by' => $pay['verified_by'] ?? null]));
    check('note stored on the payment', ($pay['admin_note'] ?? '') === 'walk-in family');
    check('5 passengers on the booking', (int) Database::scalar('SELECT COUNT(*) FROM booking_passengers WHERE booking_id = :b', ['b' => (int) $b1['id']], 0) === 5);
    check('5 seats claimed', (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => (int) $b1['id']], 0) === 5);
    $led = Database::fetch("SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => (int) $b1['id']]);
    check('commission accrued once in the agent ledger', $led !== null && (float) $led['amount'] > 0, 'amount=' . ($led['amount'] ?? 'none'));
    check('commission = flat rate × 5 seats', $led !== null && abs((float) $led['amount'] - 5 * (float) Settings::getFloat('agent_flat_direct', 200.0)) < 0.01, 'amount=' . ($led['amount'] ?? 'none'));
    $tk = 0;
    try { $tk = (int) Database::scalar('SELECT COUNT(*) FROM tickets WHERE booking_id = :b', ['b' => (int) $b1['id']], 0); } catch (Throwable $e) { $tk = -1; }
    if ($tk < 0) { $tk = !empty(Database::scalar('SELECT pdf_path FROM bookings WHERE id = :b', ['b' => (int) $b1['id']], '')) ? 1 : 0; }
    check('ticket issued', $tk > 0, 'tickets=' . $tk);
    check('audit row booking.counter', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.counter' AND entity_id = :p", ['p' => (string) $b1['pnr']], 0) === 1);

    echo "-- 2. Pricing parity with the online flow --\n";
    $b2 = BookingService::create($req(['L21', 'L22', 'L23', 'L24', 'L25'], '2'));   // anonymous, same seat count
    check('anonymous booking is pending / web / unattributed', ($b2['status'] ?? '') === 'pending' && ($b2['source'] ?? '') === 'web' && empty($b2['sold_by_admin_id']),
        json_encode(['status' => $b2['status'] ?? null, 'source' => $b2['source'] ?? null]));
    check('same base_total online and at the counter', (float) $b1['base_total'] === (float) $b2['base_total'], $b1['base_total'] . ' vs ' . $b2['base_total']);
    check('same group discount for 5 seats', (float) $b1['group_discount'] === (float) $b2['group_discount'], $b1['group_discount'] . ' vs ' . $b2['group_discount']);
    check('same total (no counter discount given)', (float) $b1['total_amount'] === (float) $b2['total_amount'], $b1['total_amount'] . ' vs ' . $b2['total_amount']);
    $pay2 = Database::fetch('SELECT status, mode FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b2['id']]);
    check('anonymous payment still pending / utr', $pay2 !== null && $pay2['status'] === 'pending' && $pay2['mode'] === 'utr');

    echo "-- 3. Counter discount is clamped --\n";
    $b3 = BookingService::create($req(['L31', 'L32'], '3'), $seller(['discountType' => 'percent', 'discountValue' => 50, 'paymentMethod' => 'upi']));
    $cap = round((float) $b3['base_total'] * 0.15, 2);
    check('50% asked → capped at 15% of base', abs((float) $b3['coupon_discount'] - $cap) < 0.01, 'discount=' . $b3['coupon_discount'] . ' cap=' . $cap);
    check('total = base − group discount − capped discount', abs((float) $b3['total_amount'] - ((float) $b3['base_total'] - (float) $b3['group_discount'] - $cap)) < 0.01, 'total=' . $b3['total_amount']);
    $pay3 = Database::fetch('SELECT method, amount FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b3['id']]);
    check('payment method UPI received, amount = discounted total', $pay3 !== null && $pay3['method'] === 'upi' && (float) $pay3['amount'] === (float) $b3['total_amount']);
    $b3b = BookingService::create($req(['L33'], '4'), $seller(['discountType' => 'flat', 'discountValue' => 100]));
    check('flat ₹100 under the cap applies as-is', (float) $b3b['coupon_discount'] === 100.0, 'discount=' . $b3b['coupon_discount']);

    echo "-- 4. Split seat caps: customer 6, counter 20 (bulk booking, 5 Sep 2026) --\n";
    $b4 = BookingService::create($req(['L34', 'L35', 'L36', 'U1', 'U2', 'U3', 'U4'], '5'), $seller());
    check('7 seats at the counter now succeed (staff cap 20)', ($b4['status'] ?? '') === 'confirmed', (string) ($b4['status'] ?? ''));
    check('7 passengers on the bulk booking', (int) Database::scalar('SELECT COUNT(*) FROM booking_passengers WHERE booking_id = :b', ['b' => (int) $b4['id']], 0) === 7);
    throws('7 seats WITHOUT a seller is still refused (customer cap 6)',
        fn() => BookingService::create($req(['U8', 'U9', 'U10', 'U11', 'U12', 'U13', 'U14'], '9')), 'at most 6');
    $twentyOne = array_merge(
        array_map(static fn(int $i): string => 'U' . $i, range(15, 34)),   // 20 seats
        ['U35']
    );
    throws('21 seats at the counter is refused (staff cap 20)',
        fn() => BookingService::create($req($twentyOne, '9'), $seller()), 'at most 20');

    echo "-- 5. Route restriction refuses before writing --\n";
    if ($other !== null) {
        // assertMaySell() only restricts a signed-in COUNTER AGENT (Auth::isCounterAgent()
        // reads the session), which is what the HTTP path always has — fake it here.
        $_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'cm-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time()];
        AgentWallet::setRoutePermissions($agentId, [(int) $other['id']], 0);
        $before = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . CM_PHONE . "%'", [], 0);
        throws('agent limited to another route cannot sell this one', fn() => BookingService::create($req(['U5'], '6'), $seller()));
        $after = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . CM_PHONE . "%'", [], 0);
        check('nothing was written', $before === $after);
        AgentWallet::setRoutePermissions($agentId, [], 0);
        $b5 = BookingService::create($req(['U5'], '6'), $seller());
        check('restriction cleared → the same sale goes through', ($b5['status'] ?? '') === 'confirmed');
        unset($_SESSION[ADMIN_SESSION_KEY]);
    } else { echo "  SKIP  only one active route\n"; }

    echo "-- 6. Seller wins over a typed agent code; bad seller refused --\n";
    $b6 = BookingService::create($req(['U6'], '7', ['referralCode' => 'SHG-999']), $seller(['source' => 'counter']));
    check('sold_by is the seller, typed code ignored', (int) $b6['sold_by_admin_id'] === $agentId && empty($b6['referral_code']));
    check('source counter accepted', ($b6['source'] ?? '') === 'counter');
    throws('seller without an admin id is refused', fn() => BookingService::create($req(['U7'], '8'), ['adminId' => 0, 'paymentMethod' => 'cash']), 'staff');

    echo "-- 6b. Seat-map walk-in without a phone + paper register cap --
";
    $b6b = BookingService::create($req(['U9'], '', ['contact' => ['phone' => '']]), $seller());
    check('walk-in with no phone is accepted for a seller (placeholder stored)', ($b6b['contact_phone'] ?? '') === '0000000000' && ($b6b['status'] ?? '') === 'confirmed', (string) ($b6b['contact_phone'] ?? ''));
    throws('anonymous customer still needs a valid phone', fn() => BookingService::create($req(['U10'], '', ['contact' => ['phone' => '']])), 'mobile');
    $sidCap = (int) Seats::schedule($rid, $D)['id'];
    $paper21 = array_map(static fn(int $i): string => 'U' . $i, range(11, 31));   // 21 seats
    throws('paper-ticket register (counterSale) shares the staff cap — 21 seats refused', fn() => BookingService::counterSale($route, $sidCap, $D, $paper21, ['name' => 'Paper', 'phone' => CM_PHONE . '9'], $agentId, 'agent'), 'at most 20');

    echo "-- 7. Departed-bus grace for a seller --\n";
    $sid = (int) Seats::schedule($rid, $D)['id'];
    Database::update('schedules', ['status' => 'departed'], 'id = :i', ['i' => $sid]);
    throws('anonymous customer cannot book a departed bus', fn() => BookingService::create($req(['U8'], '9')), 'departed');
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);
    check('schedule restored', (string) Database::scalar('SELECT status FROM schedules WHERE id = :i', ['i' => $sid], '') === 'scheduled');
} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage(), false);
} finally {
    $cleanup();
    try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :i', ['i' => $agentId]); } catch (Throwable $e) {}
}

/* 6 Sep 2026 — the desk sells without interruptions (static). */
$js = static fn(string $p): string => (string) file_get_contents(dirname(__DIR__) . '/assets/js/' . $p);
check('counter skips the duplicate-number confirm', str_contains($js('07-checkout.js'), 'if (dup && !staffSale && !confirm('));
check('the countdown reads as a seat hold at the desk, not a payment deadline', str_contains($js('05-router.js'), "staffHold ? '⏱️ Seat hold '"));
/* The desk sells to a NEW stranger every sale: the repeat-customer prefills
   (remembered contact, PaxMemory chips, welcome-back, the signed-in
   account's name/phone) are off for staff, and the contact/ID boxes are
   blanked per draft — the previous passenger must never ride into the next
   ticket or its WhatsApp. */
check('repeat-customer conveniences are gated behind isCounterSale()', str_contains($js('07-checkout.js'), 'function isCounterSale()') && substr_count($js('07-checkout.js'), 'isCounterSale()') >= 8);
check('PaxMemory never stores the desk\'s customers', str_contains($js('07-checkout.js'), '|| isCounterSale()) return;   // never keep'));
check('contact/ID boxes are blanked for every new draft at the counter', str_contains($js('14-counter.js'), 'draft !== CTR.lastDraft'));
check('an abandoned checkout draft is never restored at the counter', str_contains($js('07-checkout.js'), 'if (!isCounterSale()) CheckoutDraft.restore();'));
check('the counter tab never signs itself in as the passenger', str_contains($js('08-signin.js'), 'staff.canSell) return false;'));
/* The second sale in one tab found a dead submit button: submitBooking()
   spins + disables it and only its FAILURE paths re-enable it (success
   navigates away). renderCheckout now resets it on every render. */
check('counter start drops a stale customer sign-in', str_contains($js('14-counter.js'), "USER !== 'undefined' && USER) setUser(null);"));
check('a fresh checkout re-arms the submit button', str_contains($js('07-checkout.js'), "sbtn.classList.remove('loading');") && str_contains($js('14-counter.js'), "sb.classList.remove('loading'); }"));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
