<?php
/**
 * =====================================================================
 *  counter-shift-test.php — the drawer adds up (19 Sep 2026).
 *
 *  includes/countershift.php reads what a cashier took from the payments
 *  they verified and compares it with the cash they count. A wrong sum
 *  here accuses a person of being short, so this suite pins:
 *
 *    - one open shift per person (a second tab cannot open a second drawer)
 *    - cash + pay-on-boarding land in the drawer; UPI does not
 *    - money verified by a COLLEAGUE, before the shift, or still pending
 *      is not mine
 *    - the note pad IS the count (the typed figure cannot disagree with it)
 *    - expected = opening + cash − paid out; variance = counted − expected
 *    - a closed shift is frozen and closing twice changes nothing
 *    - it never writes to the register
 *
 *  Every sum is a DELTA against what the register already held for the
 *  test admins, so existing rows on the test database cannot fail it.
 *
 *    php -c .claude/php-dev.ini tests/counter-shift-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/countershift.php';

const SH_DATE = '2099-11-24';
const SH_TAG  = 'SHG-SHIFT-';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$madeShifts = [];
$cleanup = static function () use (&$madeShifts): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . SH_TAG . "%'") as $b) {
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);   // legs / payments cascade
    }
    foreach ($madeShifts as $id) {
        Database::delete('counter_shifts', 'id = :i', ['i' => (int) $id]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => SH_DATE]);
};

$n = 0;
/** One raw ticket of $seats seats whose payment $adminId verified at $when. */
function sale(int $sid, int $adminId, float $amount, string $method, int $seats, string $status = 'verified', ?string $when = null): int {
    global $n; $n++;
    $bid = Database::insert('bookings', [
        'pnr' => SH_TAG . $n . '-' . substr(md5((string) microtime(true)), 0, 6), 'contact_phone' => '90000010' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        'total_amount' => $amount, 'status' => 'confirmed', 'source' => 'counter', 'sold_by_admin_id' => $adminId,
    ]);
    Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => SH_DATE, 'seat_count' => $seats, 'leg_total' => $amount]);
    Database::insert('payments', [
        'booking_id' => $bid, 'payment_ref' => 'PAY-SHIFT-' . $n . '-' . substr(md5((string) microtime(true)), 0, 6),
        'method' => $method, 'mode' => 'offline', 'amount' => $amount, 'status' => $status,
        'verified_by' => $status === 'verified' ? $adminId : null,
        'verified_at' => $status === 'verified' ? ($when ?? date('Y-m-d H:i:s')) : null,
    ]);
    return $bid;
}

$hasTable = Database::exists("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'counter_shifts'");
$route    = Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
$admins   = Database::fetchAll('SELECT id FROM admins ORDER BY id LIMIT 2');

if (!$hasTable) {
    check('counter_shifts exists (apply database/upgrade-2026-09-counter-shifts.sql)', false);
} elseif ($route === null || count($admins) < 2) {
    echo "  \033[33mSKIP\033[0m  needs an active route and two admins on this DB\n";
} else {
    $A = (int) $admins[0]['id'];
    $B = (int) $admins[1]['id'];

    /* This suite is written in rupees: the pad has a 200 and no 1000. Since
       26 Sep a drawer counts the money of the DESK its cashier sits at, so if
       $A happens to be assigned to Nepalgunj on this database the pad is NPR
       and half of these assertions are wrong for the right reason. Park both
       cashiers at no desk for the duration, and put them back afterwards.
       The NPR drawer has its own coverage in counter-desk-test.php. */
    $deskWas = [];
    foreach ([$A, $B] as $who) {
        $deskWas[$who] = Database::fetch('SELECT counter_code, counter_name FROM admin_profiles WHERE admin_id = :a', ['a' => $who]);
        if ($deskWas[$who] !== null) {
            Database::update('admin_profiles', ['counter_code' => null], 'admin_id = :a', ['a' => $who]);
        }
    }
    CounterDesk::flush();
    $cleanup();
    try {
        $sid = (int) Seats::schedule((int) $route['id'], SH_DATE)['id'];
        $preA = CounterShift::current($A);
        $preB = CounterShift::current($B);
        if ($preA !== null || $preB !== null) {
            throw new RuntimeException('a test admin already has an OPEN shift on this database - close it first');
        }

        echo "-- opening --\n";
        $s1 = CounterShift::open($A, 200.0, 'test');
        $madeShifts[] = (int) $s1['id'];
        check('a shift opens with its float', (float) $s1['opening_cash'] === 200.0 && (int) $s1['is_open'] === 1);
        $s2 = CounterShift::open($A, 999.0);
        check('a second open returns the SAME drawer, float untouched',
            (int) $s2['id'] === (int) $s1['id'] && (float) $s2['opening_cash'] === 200.0);
        check('one open row for this person',
            (int) Database::scalar('SELECT COUNT(*) FROM counter_shifts WHERE admin_id = :a AND is_open = 1', ['a' => $A], 0) === 1);
        $refused = false;
        try { CounterShift::open($B, -5); } catch (RuntimeException $e) { $refused = true; }
        check('a negative float is refused', $refused && CounterShift::current($B) === null);

        /* The window starts a minute back so rows stamped "now" are inside it
           whatever the second boundary does. */
        $from = date('Y-m-d H:i:s', strtotime('-1 minute'));
        Database::update('counter_shifts', ['opened_at' => $from], 'id = :i', ['i' => (int) $s1['id']]);
        $s1['opened_at'] = $from;

        echo "-- what lands in the drawer --\n";
        $t0 = CounterShift::totals($A, $from);
        sale($sid, $A, 1000, 'cash', 1);
        sale($sid, $A, 500,  'cod',  1);
        sale($sid, $A, 2000, 'upi',  2);
        $t1 = CounterShift::totals($A, $from);
        check('cash + pay-on-boarding are drawer cash', $t1['cash'] - $t0['cash'] === 1500.0, (string) ($t1['cash'] - $t0['cash']));
        check('UPI is counted, but not in the drawer',  $t1['upi'] - $t0['upi'] === 2000.0);
        check('3 tickets, 4 seats', $t1['tickets'] - $t0['tickets'] === 3 && $t1['seats'] - $t0['seats'] === 4,
            ($t1['tickets'] - $t0['tickets']) . ' / ' . ($t1['seats'] - $t0['seats']));

        sale($sid, $B, 700, 'cash', 1);
        sale($sid, $A, 300, 'cash', 1, 'pending');
        sale($sid, $A, 900, 'cash', 1, 'verified', date('Y-m-d H:i:s', strtotime('-3 hours')));
        $t2 = CounterShift::totals($A, $from);
        check('a colleague\'s cash, a pending payment and money from before the shift are not mine',
            $t2['cash'] === $t1['cash'] && $t2['tickets'] === $t1['tickets'], $t1['cash'] . ' -> ' . $t2['cash']);

        $bidC = sale($sid, $A, 400, 'cash', 1);
        Database::update('bookings', ['status' => 'cancelled'], 'id = :b', ['b' => $bidC]);
        $t3 = CounterShift::totals($A, $from);
        check('cash taken on a ticket cancelled later STILL counts (giving it back is "paid out")',
            $t3['cash'] - $t2['cash'] === 400.0 && $t3['cancelled'] - $t2['cancelled'] === 1);

        $live = CounterShift::live($s1);
        check('live expected = float + cash so far', $live['expected'] === round(200.0 + $t3['cash'], 2), (string) $live['expected']);

        echo "-- the note pad --\n";
        check('500x3 + 100x2 = 1700', CounterShift::sumDenominations([500 => 3, 100 => 2]) === 1700.0);
        $bad = 0;
        foreach ([[7 => 1], [500 => -1]] as $c) { try { CounterShift::sumDenominations($c); } catch (RuntimeException $e) { $bad++; } }
        check('a 7-rupee note and a negative count are refused, not ignored', $bad === 2);

        echo "-- closing --\n";
        $expected = round(200.0 + $t3['cash'] - 100.0, 2);
        $padTotal = $expected - 50;                       // fifty short, built only from real notes
        $pad = []; $left = (int) $padTotal;
        foreach (CounterShift::DENOMINATIONS as $d) { $pad[$d] = intdiv($left, $d); $left -= $pad[$d] * $d; }
        $c1 = CounterShift::close((int) $s1['id'], $B, 9999.0, 100.0, $pad, 'closing note');
        check('the note pad IS the count - the typed 9999 is ignored', (float) $c1['counted_cash'] === (float) (int) $padTotal, (string) $c1['counted_cash']);
        check('expected = float + cash - paid out', (float) $c1['expected_cash'] === $expected, $c1['expected_cash'] . ' vs ' . $expected);
        check('variance = counted - expected (negative = short)', (float) $c1['variance'] === round((int) $padTotal - $expected, 2), (string) $c1['variance']);
        check('...and the verdict says short', CounterShift::verdict((float) $c1['variance']) === 'short');
        check('closed: no longer open, closer recorded, pad stored',
            $c1['is_open'] === null && (int) $c1['closed_by'] === $B && CounterShift::current($A) === null
            && is_array(json_decode((string) $c1['denominations'], true)));

        sale($sid, $A, 5000, 'cash', 1);
        $c2 = CounterShift::close((int) $s1['id'], $A, 1.0);
        check('a closed shift is frozen: closing again and a later sale change nothing',
            (float) $c2['counted_cash'] === (float) $c1['counted_cash'] && (float) $c2['cash_sales'] === (float) $c1['cash_sales']
            && (string) $c2['closed_at'] === (string) $c1['closed_at']);

        $s3 = CounterShift::open($A, 0);
        $madeShifts[] = (int) $s3['id'];
        check('the next shift opens cleanly after a close', (int) $s3['id'] !== (int) $s1['id']);
        CounterShift::close((int) $s3['id'], $A, CounterShift::live($s3)['expected']);

        echo "-- scope and words --\n";
        $mine = CounterShift::recent($A, 50);
        check('a cashier\'s list holds only their own shifts',
            $mine !== [] && array_filter($mine, static fn($r) => (int) $r['admin_id'] !== $A) === []);
        check('verdict: 0 = ok, +5 = over', CounterShift::verdict(0.0) === 'ok' && CounterShift::verdict(5.0) === 'over');
        $txt = CounterShift::summaryText($c1, 'Test Counter');
        check('the office summary names the person, the count and the shortfall - and no phone',
            str_contains($txt, 'Test Counter') && str_contains($txt, 'कम') && !preg_match('/\d{10}/', $txt));

        echo "-- not on the sale path --\n";
        $src = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(INCLUDE_PATH . '/countershift.php'));
        check('countershift.php writes to counter_shifts and nothing else',
            preg_match_all('/Database::(insert|insertIgnore|update|delete)\(\s*\'([a-z_]+)\'/', (string) $src, $m) > 0
            && array_values(array_unique($m[2])) === ['counter_shifts']);
        check('booking.php / seats.php do not know the shift exists (selling cannot be blocked by it)',
            !str_contains((string) file_get_contents(INCLUDE_PATH . '/booking.php'), 'CounterShift')
            && !str_contains((string) file_get_contents(INCLUDE_PATH . '/seats.php'), 'CounterShift'));
    } catch (Throwable $e) {
        check('unexpected error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
        foreach ($deskWas as $who => $row) {
            if ($row !== null) {
                Database::update('admin_profiles',
                    ['counter_code' => ($row['counter_code'] ?? '') !== '' ? $row['counter_code'] : null],
                    'admin_id = :a', ['a' => (int) $who]);
            }
        }
        CounterDesk::flush();
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
