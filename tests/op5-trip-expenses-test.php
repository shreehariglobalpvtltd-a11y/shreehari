<?php
/**
 * =====================================================================
 *  op5-trip-expenses-test.php — ट्रिप खर्च per trip, exact bus profit (25 Nov 2026).
 *
 *  Drives TripExpense (includes/tripexpense.php) on its own throw-away route,
 *  bus, two trips (slot 1 + slot 2 of one route and day), two tickets and
 *  synthetic staff arrays; every row it makes is removed at the end.
 *
 *   A per-trip entries land on their own trip and sum per trip
 *   B NPR is turned into INR at npr_per_inr (total + old column in INR)
 *   C the old shape is filled too (route_id, expense_date, column, total) so
 *     admin/pnl.php / accounting.php keep counting it
 *   D trip P&L = ticket income (confirmed only) − expenses; bus roll-up
 *   E permission: agent / support / viewer / inactive refused, counter + office allowed
 *   F edit / delete own same day (counter), any (office), audited
 *   G guards: bad kind, zero, too large, litres only on diesel
 *   H migration absent -> old route + day insert, still read back
 *
 *    php -c .claude/php-dev.ini tests/op5-trip-expenses-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/tripexpense.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): bool {
    try { $fn(); return false; } catch (RuntimeException $e) { return true; }
}

if (!TripExpense::hasSchema()) {
    echo "  SKIP  run database/upgrade-2026-11-25-op5-trip-expenses.sql first\n";
    exit(0);
}

$tag   = 'OP5X' . substr(bin2hex(random_bytes(3)), 0, 5);
$today = todayISO();
$ids   = ['route' => 0, 'bus' => 0, 'sA' => 0, 'sB' => 0, 'bk' => [], 'exp' => []];

$office  = ['id' => 990000101, 'role' => 'manager',    'is_active' => 1];
$account = ['id' => 990000102, 'role' => 'accountant', 'is_active' => 1];
$counter = ['id' => 990000103, 'role' => 'counter',    'is_active' => 1];
$counter2 = ['id' => 990000104, 'role' => 'counter',   'is_active' => 1];
$agent   = ['id' => 990000105, 'role' => 'agent',      'is_active' => 1];
$support = ['id' => 990000106, 'role' => 'support',    'is_active' => 1];
$viewer  = ['id' => 990000107, 'role' => 'viewer',     'is_active' => 1];
$offMgr  = ['id' => 990000108, 'role' => 'manager',    'is_active' => 0];

try {
    /* ---- fixtures: an inactive route, a bus, two trips on one day ---- */
    $ids['bus'] = Database::insert('buses', ['bus_number' => $tag . '-BUS', 'bus_name' => 'Op5 test bus ' . $tag, 'is_active' => 0]);
    $ids['route'] = Database::insert('routes', ['route_code' => $tag, 'from_city' => 'Op5From', 'to_city' => 'Op5To',
        'dep_time' => '08:00:00', 'arr_time' => '20:00:00', 'is_active' => 0, 'bus_id' => $ids['bus']]);
    $ids['sA'] = Database::insert('schedules', ['route_id' => $ids['route'], 'travel_date' => $today, 'slot' => 1, 'bus_id' => $ids['bus'], 'status' => 'scheduled', 'is_blocked' => 1]);
    $ids['sB'] = Database::insert('schedules', ['route_id' => $ids['route'], 'travel_date' => $today, 'slot' => 2, 'bus_id' => $ids['bus'], 'status' => 'scheduled', 'is_blocked' => 1]);
    $sale = static function (string $status, float $amt, int $sid) use (&$ids, $tag, $today): void {
        $b = Database::insert('bookings', ['pnr' => 'SHG-' . $tag . '-' . count($ids['bk']), 'status' => $status,
            'contact_phone' => '9000017799', 'total_amount' => $amt, 'currency' => 'INR', 'source' => 'counter']);
        $ids['bk'][] = $b;
        Database::insert('booking_legs', ['booking_id' => $b, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $today,
            'boarding_stop' => 'Op5From', 'drop_stop' => 'Op5To', 'fare_per_seat' => $amt, 'seat_count' => 1, 'leg_total' => $amt]);
    };
    $sale('confirmed', 3000.0, $ids['sA']);
    $sale('confirmed', 2500.0, $ids['sA']);
    $sale('cancelled', 1000.0, $ids['sA']);   // never income
    $sale('confirmed', 4000.0, $ids['sB']);

    $rate = TripExpense::rate();
    check('npr_per_inr rate is positive', $rate > 0, (string) $rate);

    /* ---- A + B + C: per-trip entries, NPR conversion, old shape ---- */
    $d1 = TripExpense::add($office, $ids['sA'], 'diesel', 5000, 'INR', 100, 'pump 1');
    $d2 = TripExpense::add($counter, $ids['sA'], 'toll', 1600, 'NPR', 50, 'border toll');   // litres ignored off diesel
    $d3 = TripExpense::add($account, $ids['sB'], 'driver_allowance', 800, 'INR');
    $d4 = TripExpense::add($office, $ids['sA'], 'repair', 1200, 'INR', null, 'tyre');
    $ids['exp'] = [$d1, $d2, $d3, $d4];
    $nprInr = round(1600 / $rate, 2);
    check('NPR helper: 1600 NPR = 1600 / rate INR', TripExpense::toInr(1600, 'NPR') === $nprInr, (string) TripExpense::toInr(1600, 'NPR'));
    check('INR stays INR', TripExpense::toInr(1234.567, 'INR') === 1234.57);

    $r2 = TripExpense::row($d2);
    check('NPR row: total is INR', abs((float) $r2['total'] - $nprInr) < 0.005, (string) $r2['total']);
    check('NPR row: typed amount + currency + rate kept', (float) $r2['amount'] === 1600.0 && $r2['currency'] === 'NPR' && abs((float) $r2['fx_rate'] - $rate) < 0.0001);
    check('NPR row: old toll column in INR', abs((float) $r2['toll'] - $nprInr) < 0.005);
    check('litres dropped on a non-diesel entry', $r2['litres'] === null);
    $r1 = TripExpense::row($d1);
    check('diesel row: litres kept, diesel column = total', (float) $r1['litres'] === 100.0 && (float) $r1['diesel'] === 5000.0 && (float) $r1['total'] === 5000.0);
    check('row carries the trip, route, date and bus', (int) $r1['schedule_id'] === $ids['sA'] && (int) $r1['route_id'] === $ids['route']
        && (string) $r1['expense_date'] === $today && (int) $r1['bus_id'] === $ids['bus']);
    $r3 = TripExpense::row($d3);
    check('driver allowance goes in driver_pay', (float) $r3['driver_pay'] === 800.0 && $r3['category'] === 'driver_allowance');
    check('repair goes in misc', (float) TripExpense::row($d4)['misc'] === 1200.0);

    $rowsA = TripExpense::forSchedule($ids['sA']);
    $rowsB = TripExpense::forSchedule($ids['sB']);
    check('trip A has its 3 entries, trip B its 1 (same route + day)', count($rowsA) === 3 && count($rowsB) === 1, count($rowsA) . '/' . count($rowsB));
    $sumA = round(5000 + $nprInr + 1200, 2);
    check('entries sum per trip', abs(array_sum(array_map(static fn($r) => (float) $r['total'], $rowsA)) - $sumA) < 0.01);

    /* ---- D: P&L ---- */
    $pA = TripExpense::tripPnl($ids['sA']);
    check('income = confirmed tickets only (3000 + 2500)', $pA['income'] === 5500.0 && $pA['tickets'] === 2, json_encode($pA));
    check('trip expenses = sum of its entries', abs($pA['expenses'] - $sumA) < 0.01);
    check('P&L = income − expenses', abs($pA['profit'] - round(5500 - $sumA, 2)) < 0.01, (string) $pA['profit']);
    check('by-kind split', isset($pA['by_cat']['diesel'], $pA['by_cat']['toll'], $pA['by_cat']['repair']) && $pA['by_cat']['diesel'] === 5000.0);
    $pB = TripExpense::tripPnl($ids['sB']);
    check('trip B profit = 4000 − 800', $pB['profit'] === 3200.0, (string) $pB['profit']);

    $range = TripExpense::range($today, $today);
    $bus = null;
    foreach ($range['buses'] as $b) { if ((int) $b['bus'] === $ids['bus']) { $bus = $b; } }
    check('bus roll-up: 2 trips', $bus !== null && $bus['trips'] === 2);
    check('bus roll-up: income, expenses, profit add up', $bus !== null && $bus['income'] === 9500.0
        && abs($bus['expenses'] - round($sumA + 800, 2)) < 0.01 && abs($bus['profit'] - round(9500 - $sumA - 800, 2)) < 0.01, json_encode($bus));
    $pnlRoute = (float) Database::scalar('SELECT COALESCE(SUM(total), 0) FROM trip_expenses WHERE route_id = :r AND expense_date = :d',
        ['r' => $ids['route'], 'd' => $today], 0);
    check('pnl.php route sum sees every per-trip entry', abs($pnlRoute - round($sumA + 800, 2)) < 0.01);

    /* ---- E: who may enter ---- */
    check('agent may not enter', !TripExpense::mayEnter($agent));
    check('support / viewer may not enter', !TripExpense::mayEnter($support) && !TripExpense::mayEnter($viewer));
    check('inactive manager may not enter', !TripExpense::mayEnter($offMgr));
    check('nobody signed in may not enter', !TripExpense::mayEnter(null));
    check('counter, manager, accountant, superadmin may enter', TripExpense::mayEnter($counter) && TripExpense::mayEnter($office)
        && TripExpense::mayEnter($account) && TripExpense::mayEnter(['id' => 1, 'role' => 'superadmin']));
    $before = (int) Database::scalar('SELECT COUNT(*) FROM trip_expenses WHERE route_id = :r', ['r' => $ids['route']], 0);
    check('agent add() is refused', throws(static fn() => TripExpense::add($agent, $ids['sA'], 'diesel', 100)));
    check('...and nothing was written', (int) Database::scalar('SELECT COUNT(*) FROM trip_expenses WHERE route_id = :r', ['r' => $ids['route']], 0) === $before);
    check('counter may not file a trip a month old', !TripExpense::mayFileDate($counter, addDaysISO($today, -30)) && TripExpense::mayFileDate($office, addDaysISO($today, -30)));

    /* ---- F: edit / delete ---- */
    check('counter may change own entry today', TripExpense::mayChange($counter, $r2));
    check('another counter may not', !TripExpense::mayChange($counter2, $r2));
    check('counter may not change own entry from yesterday', !TripExpense::mayChange($counter, ['created_by' => $counter['id'], 'created_at' => addDaysISO($today, -1) . ' 10:00:00']));
    check('office may change anyone\'s', TripExpense::mayChange($office, $r2) && TripExpense::mayChange($account, $r1));
    check('counter may not edit the manager\'s diesel', throws(static fn() => TripExpense::update($counter, $d1, 'diesel', 1, 'INR')));
    TripExpense::update($counter, $d2, 'toll', 2000, 'INR', null, 'corrected');
    $r2b = TripExpense::row($d2);
    check('edit recomputes total + column in INR', (float) $r2b['total'] === 2000.0 && (float) $r2b['toll'] === 2000.0 && $r2b['currency'] === 'INR' && $r2b['fx_rate'] === null);
    TripExpense::update($office, $d4, 'parking', 320, 'NPR');
    $r4b = TripExpense::row($d4);
    check('edit to another kind moves the money column', (float) $r4b['misc'] === round(320 / $rate, 2) && $r4b['category'] === 'parking' && (int) $r4b['updated_by'] === $office['id']);
    check('edit is audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'trip_expense.edit' AND entity_type = 'trip_expenses' AND entity_id = :i", ['i' => (string) $d2]));
    check('agent delete is refused', throws(static fn() => TripExpense::remove($agent, $d3)));
    TripExpense::remove($office, $d3);
    check('office delete removes the row', TripExpense::row($d3) === null);
    check('delete is audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'trip_expense.delete' AND entity_id = :i", ['i' => (string) $d3]));
    check('add is audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'trip_expense.add' AND entity_id = :i", ['i' => (string) $d1]));

    /* ---- G: guards ---- */
    check('unknown kind refused', throws(static fn() => TripExpense::add($office, $ids['sA'], 'salary', 100)));
    check('zero amount refused', throws(static fn() => TripExpense::add($office, $ids['sA'], 'diesel', 0)));
    check('negative amount refused', throws(static fn() => TripExpense::add($office, $ids['sA'], 'diesel', -5)));
    check('more than the cap refused', throws(static fn() => TripExpense::add($office, $ids['sA'], 'repair', TripExpense::MAX_INR + 1)));
    check('unknown trip refused', throws(static fn() => TripExpense::add($office, 0, 'diesel', 100)));
    check('a receipt path that is not ours is never served', TripExpense::receiptFile('../config/config.local.php') === null
        && TripExpense::receiptFile('trip-receipts/x.php') === null);

    /* ---- H: migration absent -> old route + day shape ---- */
    TripExpense::$forceLegacy = true;
    check('legacy: schema reported missing', !TripExpense::hasSchema());
    $d5 = TripExpense::add($office, $ids['sB'], 'repair', 450, 'NPR', null, 'bulb');
    $ids['exp'][] = $d5;
    TripExpense::$forceLegacy = false;
    $r5 = TripExpense::row($d5);
    check('legacy: route + date from the trip (not the first route)', (int) $r5['route_id'] === $ids['route'] && (string) $r5['expense_date'] === $today);
    check('legacy: no trip id, kind + NPR kept in the note', $r5['schedule_id'] === null && str_starts_with((string) $r5['note'], '[repair] bulb (NPR 450.00'));
    check('legacy: total in INR, in misc', (float) $r5['total'] === round(450 / $rate, 2) && (float) $r5['misc'] === (float) $r5['total']);
    check('legacy row reads back as repair', TripExpense::categoryOf($r5) === 'repair');
    TripExpense::$forceLegacy = true;
    $legacyRows = TripExpense::forSchedule($ids['sB']);
    TripExpense::$forceLegacy = false;
    check('legacy: trip list falls back to route + day', count($legacyRows) >= 4, (string) count($legacyRows));
    $range2 = TripExpense::range($today, $today);
    check('an entry with no trip is reported apart, totals still match', $range2['unlinked'] >= (float) $r5['total'] - 0.005);
} catch (Throwable $ex) {
    check('no exception', false, get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
} finally {
    TripExpense::$forceLegacy = false;
    try {
        $expIds = $ids['route'] > 0 ? array_map('intval', array_column(Database::fetchAll('SELECT id FROM trip_expenses WHERE route_id = :r', ['r' => $ids['route']]), 'id')) : [];
        foreach ($expIds as $x) {
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'trip_expenses' AND entity_id = :i", ['i' => (string) $x]);
        }
        foreach ($ids['exp'] as $x) {
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'trip_expenses' AND entity_id = :i", ['i' => (string) $x]);
        }
        if ($ids['route'] > 0) { Database::run('DELETE FROM trip_expenses WHERE route_id = :r', ['r' => $ids['route']]); }
        foreach ($ids['bk'] as $b) {
            Database::run('DELETE FROM booking_legs WHERE booking_id = :b', ['b' => $b]);
            Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $b]);
        }
        foreach (['sA', 'sB'] as $k) { if ($ids[$k] > 0) { Database::run('DELETE FROM schedules WHERE id = :i', ['i' => $ids[$k]]); } }
        if ($ids['route'] > 0) { Database::run('DELETE FROM routes WHERE id = :i', ['i' => $ids['route']]); }
        if ($ids['bus'] > 0) { Database::run('DELETE FROM buses WHERE id = :i', ['i' => $ids['bus']]); }
        $left = $ids['route'] > 0 ? (int) Database::scalar('SELECT COUNT(*) FROM trip_expenses WHERE route_id = :r', ['r' => $ids['route']], 0) : 0;
        check('clean-up left no rows', $left === 0 && !Database::exists('SELECT 1 FROM routes WHERE route_code = :c', ['c' => $tag]));
    } catch (Throwable $ex) {
        check('clean-up', false, $ex->getMessage());
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
