<?php
/**
 * tests/customer-book-test.php — Customer master / ledger / 360 / duplicates
 * (3 Oct 2026). Seeds its own bookings on a TEST database only, then checks:
 *  - ledger rows + totals agree (Excel rule: one change moves every total)
 *  - last-4/6-digit customer search, name search, transactions search
 *  - merge / unmerge / changePhone never touch a booking row
 *  - duplicate finder: spelling variants, one-digit typo pair, shared number,
 *    placeholder number
 *  - GemVault keeps an office-locked name
 * Run: php tests/customer-book-test.php   (refuses a non-test DB)
 */
declare(strict_types=1);
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/customerbook.php';

if (!str_contains(DB_NAME, 'test')) { fwrite(STDERR, "Refusing: DB_NAME is not a test database\n"); exit(2); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what\n"; }
};

/* ---------- seed ---------- */
$tag = 'CBT' . substr((string) time(), -5);
$sched = Database::fetch('SELECT id, route_id, travel_date FROM schedules WHERE bus_id IS NOT NULL ORDER BY id DESC LIMIT 1');
if ($sched === null) { echo "no schedule to hang bookings on\n"; exit(1); }
$base = '98' . (string) random_int(1000, 9999);   // 6 digits, unique per run
$P1 = $base . '1234'; $P1typo = $base . '1243'; $P2 = $base . '5678'; $SHARED = $base . '9999';
$seq = 0;
$mk = static function (string $phone, string $lead, string $status, float $amt, array $extra = [], string $payStatus = 'verified') use ($sched, $tag, &$seq): int {
    $n = ++$seq;
    $id = Database::insert('bookings', [
        'pnr' => $tag . '-' . $n, 'contact_phone' => $phone, 'status' => $status, 'total_amount' => $amt,
        'fare_per_seat' => $amt, 'base_total' => $amt, 'source' => $extra['source'] ?? 'counter',
        'refund_amount' => $extra['refund'] ?? 0, 'refund_status' => isset($extra['refund']) ? 'processed' : 'none',
    ]);
    $leg = Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => (int) $sched['id'], 'leg_type' => 'outbound',
        'travel_date' => $extra['travel'] ?? $sched['travel_date'], 'boarding_stop' => $extra['pick'] ?? 'Surat', 'drop_stop' => 'Rupaidiha',
        'seat_count' => 1, 'fare_per_seat' => $amt, 'leg_total' => $amt]);
    $pax = $extra['pax'] ?? [$lead];
    foreach ($pax as $i => $nm) {
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => $tag . 'P' . $n . '-' . $i,
            'seat_no' => 'L' . (10 + $i), 'full_name' => $nm, 'is_primary' => $i === 0 ? 1 : 0]);
        Database::insert('booking_seats', ['booking_id' => $id, 'leg_id' => $leg, 'schedule_id' => (int) $sched['id'], 'seat_no' => 'Z' . substr($tag, -3) . $n . $i]);
    }
    if ($payStatus !== '') {
        Database::insert('payments', ['booking_id' => $id, 'payment_ref' => $tag . 'PAY' . $n, 'method' => 'cash', 'mode' => 'offline',
            'amount' => $amt, 'status' => $payStatus, 'utr_number' => $tag . 'UTR' . $n]);
    }
    return $id;
};
try {
    $b1 = $mk($P1, 'Ram Bahadur', 'confirmed', 4000, ['pax' => ['Ram Bahadur', 'Sita Kumari'], 'travel' => '2026-10-01']);
    $b2 = $mk($P1, 'RAM BAHADUR', 'confirmed', 2200, ['travel' => '2026-09-15']);
    $b3 = $mk($P1, 'Ram', 'cancelled', 2000, ['refund' => 1500, 'travel' => '2026-09-20']);
    $b4 = $mk($P1typo, 'Ram Bahadur', 'confirmed', 2000, ['travel' => '2026-08-10']);
    $b5 = $mk($P2, 'Hukkum Bhuda', 'confirmed', 1800, ['travel' => '2026-09-01'], 'pending');
    foreach (['Amar Shahi', 'Binod Thapa', 'Kiran Rana', 'Dipa Soni'] as $nm) { $mk($SHARED, $nm, 'confirmed', 2000); }
    $mk('0000000000', 'Walk In ' . $tag, 'confirmed', 2000);
} catch (Throwable $e) {
    echo 'seed failed: ' . $e->getMessage() . "\n"; exit(1);
}

echo "Ledger + totals\n";
$f = CustomerBook::filtersFrom(['phone' => $P1, 'date_mode' => 'booking']);
$rows = CustomerBook::ledger($f, 100);
$t = CustomerBook::totals($f);
$ok(count($rows) === 3 && $t['bookings'] === 3, 'customer ledger has the 3 bookings on +' . $P1);
$ok($t['pax'] === 4, 'passengers counted live (2+1+1) = ' . $t['pax']);
$ok(abs($t['fare'] - 6200) < 0.01, 'fare counts confirmed only (4000+2200) = ' . $t['fare']);
$ok(abs($t['refunds'] - 1500) < 0.01 && $t['cancelled'] === 1, 'cancelled + refund counted');
$ok(($rows[0]['seller_label'] ?? '') === 'Counter' && ($rows[0]['pay_label'] ?? '') === 'Paid · CASH', 'seller + payment labels: ' . ($rows[0]['seller_label'] ?? '') . ' / ' . ($rows[0]['pay_label'] ?? ''));
$fj = CustomerBook::filtersFrom(['mobile' => substr($P1, -6), 'from' => '2026-09-14', 'to' => '2026-09-16']);
$ok(count(CustomerBook::ledger($fj, 100)) === 1, 'journey-date range + partial mobile finds the 15 Sep trip');
$fp = CustomerBook::filtersFrom(['phone' => $P2, 'pay' => 'pending']);
$ok(CustomerBook::totals($fp)['bookings'] === 1, 'payment-status filter (pending)');
// Excel rule: cancel a booking -> every figure moves on the next read.
Database::update('bookings', ['status' => 'cancelled'], 'id = :i', ['i' => $b2]);
$t2 = CustomerBook::totals($f);
$ok(abs($t2['fare'] - 4000) < 0.01 && $t2['cancelled'] === 2, 'a cancellation moves fare + cancelled totals at once');
Database::update('bookings', ['status' => 'confirmed'], 'id = :i', ['i' => $b2]);

echo "Search\n";
$s = CustomerBook::searchCustomers(substr($P1, -4));
$ok(in_array($P1, array_column($s, 'phone'), true), 'last 4 digits find the customer');
$s6 = CustomerBook::searchCustomers(substr($P1, -6));
$ok(($s6[0]['phone'] ?? '') === $P1, 'last 6 digits rank the customer first');
$ok(!in_array('0000000000', array_column(CustomerBook::searchCustomers('0000'), 'phone'), true), 'placeholder number never listed as a customer');
$sn = CustomerBook::searchCustomers('Hukkum');
$ok(in_array($P2, array_column($sn, 'phone'), true), 'name search finds the customer');
$tx = CustomerBook::searchTransactions($tag . 'UTR5');
$ok(count($tx) === 1 && ($tx[0]['pnr'] ?? '') === $tag . '-5', 'transaction search by UTR');

echo "360 + edits never touch bookings\n";
$before = Database::fetchAll('SELECT id, contact_phone, status FROM bookings WHERE pnr LIKE :t ORDER BY id', ['t' => $tag . '%']);
$beforePax = Database::fetchAll('SELECT id, full_name FROM booking_passengers WHERE passenger_ref LIKE :t ORDER BY id', ['t' => $tag . '%']);
$c = CustomerBook::profile360($P1);
$ok($c['sum']['sold'] === 2 && $c['sum']['pax'] === 3, '360 totals: 2 trips, 3 passengers');
$ok($c['customer_id'] !== '', 'customer id issued: ' . $c['customer_id']);
$ok(count($c['passengers']) === 3, 'passenger history folds "Ram Bahadur / RAM BAHADUR" into one traveller (+ Ram, Sita) = ' . count($c['passengers']));
$ok(CustomerBook::saveProfile($P1, ['full_name' => 'Ram Bahadur Thapa', 'alt_phone' => $P2, 'notes' => 'VIP']) === '', 'profile saved');
GemVault::upsert($P1, 'RAM', '', 'counter');
$nm = (string) Database::scalar('SELECT full_name FROM user_profiles WHERE phone = :p', ['p' => $P1]);
$ok($nm === 'Ram Bahadur Thapa', 'office-locked name survives a later sale typed "RAM" (got "' . $nm . '")');
$ok(CustomerBook::merge($P1typo, $P1) === '', 'typo number linked');
$c2 = CustomerBook::profile360($P1typo);
$ok($c2['phone'] === $P1 && $c2['sum']['bookings'] === 4, 'opening the typo number shows the main customer with all 4 bookings');
$ok(CustomerBook::merge('0000000000', $P1) !== '', 'placeholder can never be linked');
$ok(CustomerBook::unmerge($P1typo) === '' && CustomerBook::profile360($P1)['sum']['bookings'] === 3, 'separate again');
$NEW = $base . '4321';
$ok(CustomerBook::changePhone($P1, $NEW) === '', 'change primary number');
$c3 = CustomerBook::profile360($P1);
$ok($c3['phone'] === $NEW && in_array($P1, $c3['phones'], true) && $c3['sum']['bookings'] === 3, 'new number is main, old linked, history intact');
$ok($c3['name'] === 'Ram Bahadur Thapa', 'name carried to the new main record (got "' . $c3['name'] . '")');
$after = Database::fetchAll('SELECT id, contact_phone, status FROM bookings WHERE pnr LIKE :t ORDER BY id', ['t' => $tag . '%']);
$afterPax = Database::fetchAll('SELECT id, full_name FROM booking_passengers WHERE passenger_ref LIKE :t ORDER BY id', ['t' => $tag . '%']);
$ok($before === $after && $beforePax === $afterPax, 'no booking or ticket name was rewritten by any edit');
$audit = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'customer' AND entity_id IN (:a, :b)", ['a' => $P1, 'b' => $NEW], 0);
$ok($audit >= 4, 'every edit audited (' . $audit . ' rows)');
CustomerBook::unmerge($P1);   // leave +P1 standalone for the duplicate test

echo "Duplicates\n";
$d = CustomerBook::duplicates();
$var = array_values(array_filter($d['variants'], static fn($v) => $v['phone'] === $P1));
$ok($var !== [] && count($var[0]['names']) === 3, 'spelling variants on one number: ' . ($var ? implode(' / ', array_column($var[0]['names'], 'name')) : 'none'));
$pair = array_values(array_filter($d['pairs'], static fn($p) => in_array($P1typo, [$p['a'], $p['b']], true)));
$ok($pair !== [] && $pair[0]['level'] === 'high', 'two-digits-swapped number with the same name = likely typo');
$ok(in_array($SHARED, array_column($d['shared'], 'phone'), true), 'number booking 4 different people flagged as shared');
$ok(in_array('0000000000', array_column($d['nonCustomer'], 'phone'), true), 'placeholder number listed separately');
$ok(!in_array($SHARED, array_merge(array_column($d['pairs'], 'a'), array_column($d['pairs'], 'b')), true), 'shared number never offered for merging');
CustomerBook::dismissPair($P1, $P1typo, 1);
$d2 = CustomerBook::duplicates();
$left = array_values(array_filter($d2['pairs'], static fn($p) => in_array($P1typo, [$p['a'], $p['b']], true) && in_array($P1, [$p['a'], $p['b']], true)));
$ok($left === [], '"different people" hides the pair' . ($left ? ' (still: ' . json_encode($left) . ')' : ''));

/* ---------- clean up (SHG_KEEP=1 leaves the demo rows for a visual check) ---------- */
if (getenv('SHG_KEEP')) { echo "\nkept demo rows ($tag)\n$pass passed, $fail failed\n"; exit($fail === 0 ? 0 : 1); }
$ids = array_column(Database::fetchAll('SELECT id FROM bookings WHERE pnr LIKE :t', ['t' => $tag . '%']), 'id');
foreach ($ids as $id) {
    foreach (['payments', 'booking_seats', 'booking_passengers', 'booking_legs'] as $tb) { Database::delete($tb, 'booking_id = :b', ['b' => (int) $id]); }
    Database::delete('bookings', 'id = :b', ['b' => (int) $id]);
}
foreach ([$P1, $P1typo, $P2, $SHARED, $NEW] as $ph) { Database::delete('user_profiles', 'phone = :p', ['p' => $ph]); }
Database::query('DELETE FROM customer_dup_dismissed WHERE phone_a IN (:a, :b)', ['a' => $P1, 'b' => $P1typo]);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
