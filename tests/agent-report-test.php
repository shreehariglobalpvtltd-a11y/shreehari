<?php
/**
 * tests/agent-report-test.php — Agent 360 Report tab (3 Oct 2026).
 *
 * Real counter sales through BookingService::counterSale, then:
 *   - AgentReport::build totals == the ledger, per ticket and in total;
 *   - a rate change (per-agent flat override) moves FUTURE sales only;
 *   - journey vs sale basis put a ticket on the right day;
 *   - window() presets / custom swap.
 *
 *   php tests/agent-report-test.php          (SHG_KEEP=1 keeps the demo rows)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/agentreport.php';

if (!str_contains((string) DB_NAME, 'test')) { echo "refusing: not a test database\n"; exit(2); }

$pass = 0; $fail = 0;
function check(string $l, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

$keep = getenv('SHG_KEEP') === '1';
$U    = 'agentreport-demo';
$D1   = addDaysISO(todayISO(), 1);
$D2   = addDaysISO(todayISO(), 2);

function ar_cleanup(int $a): void {
    foreach (Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $a]) as $r) {
        Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]);
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $a]);
    AgentWallet::setCommissionOverride($a, null, null, 0);
    try { AgentWallet::setAgentCode($a, null, 0); } catch (Throwable $e) {}
    Database::delete('admin_profiles', 'admin_id = :a', ['a' => $a]);
    Database::delete('admins', 'id = :a', ['a' => $a]);
}

echo "\n=== Agent report ===\n\n";
$A = 0;
try {
    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $U]);
    if ($old !== null) { ar_cleanup((int) $old['id']); }
    $A = Database::insert('admins', ['username' => $U, 'password_hash' => password_hash('x-not-a-login', PASSWORD_BCRYPT),
        'full_name' => 'Ram Report Demo', 'phone' => '9800099001', 'role' => 'agent', 'is_active' => 1]);

    Settings::set('agent_commission_mode', 'flat_per_seat', 'string', 'agent');
    Settings::set('agent_flat_direct', '200', 'float', 'agent');
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent');
    Settings::flush();

    $route = Database::fetch('SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    $s1 = Seats::schedule((int) $route['id'], $D1);
    $s2 = Seats::schedule((int) $route['id'], $D2);
    $f1 = array_values(Seats::availability((int) $route['id'], $D1)['available'] ?? []);
    $f2 = array_values(Seats::availability((int) $route['id'], $D2)['available'] ?? []);
    if (count($f1) < 4 || count($f2) < 4) { echo "not enough free seats\n"; exit(1); }

    $sell = static function (int $sid, string $d, array $seats, float $amt, string $phone) use ($route, $A): array {
        return BookingService::counterSale($route, $sid, $d, $seats, [
            'name' => 'Report Test', 'phone' => $phone, 'amount' => $amt, 'paymentMethod' => 'cash',
        ], $A, 'agent');
    };

    $b1 = $sell((int) $s1['id'], $D1, [$f1[0], $f1[1]], 4000.0, '9800099101');   // 2 pax @ tier 200
    check('tier sale: 2 pax earn Rs 400', money_is((float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission'", ['b' => (int) $b1['id']], 0), 400.0));

    // Rate up to Rs 300 — only the NEXT sale moves.
    AgentWallet::setCommissionOverride($A, 'flat', 300.0, 1);
    $rc = AgentReport::rateCard($A);
    check('rate card shows the override (Rs 300 / pax)', $rc['mode'] === 'flat' && money_is($rc['flat'], 300.0));
    $b2 = $sell((int) $s2['id'], $D2, [$f2[0], $f2[1], $f2[2]], 6000.0, '9800099102');   // 3 pax @ 300
    check('after +100: 3 pax earn Rs 900', money_is((float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission'", ['b' => (int) $b2['id']], 0), 900.0));
    check('the first ticket still earns Rs 400 (sold tickets keep their commission)', money_is((float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission'", ['b' => (int) $b1['id']], 0), 400.0));

    // Journey basis, a window over both travel days.
    $win = AgentReport::window('custom', $D1, $D2, 'journey');
    $r   = AgentReport::build($A, $win);
    check('journey window: 5 pax', (int) $r['totals']['pax'] === 5);
    check('journey window: sales Rs 10,000', money_is((float) $r['totals']['sale'], 10000.0));
    check('journey window: commission Rs 1,300 = ledger', money_is((float) $r['totals']['commission'], 1300.0));
    check('company keeps sales − commission', money_is((float) $r['totals']['company'], 8700.0));
    check('two days, newest first', count($r['days']) === 2 && $r['days'][0]['d'] === $D2);
    check('day rows add up to the total', money_is(array_sum(array_column($r['days'], 'commission')), 1300.0));
    check('two buses listed', count($r['buses']) === 2);
    check('ticket rows carry their own commission', money_is(array_sum(array_column($r['tickets'], 'commission')), 1300.0));

    // Only day 1.
    $r1 = AgentReport::build($A, AgentReport::window('custom', $D1, $D1, 'journey'));
    check('one travel day: 2 pax / Rs 400', (int) $r1['totals']['pax'] === 2 && money_is((float) $r1['totals']['commission'], 400.0));

    // Sale basis: both were cut today.
    $rs = AgentReport::build($A, AgentReport::window('today', '', '', 'sale'));
    check('sale basis today: both tickets', (int) $rs['totals']['tickets'] === 2);
    $rj = AgentReport::build($A, AgentReport::window('today', '', '', 'journey'));
    check('journey basis today: none (they travel tomorrow+)', (int) $rj['totals']['tickets'] === 0);

    // A seat cancel nets the commission on the report too.
    BookingService::cancelSeat((int) $b2['id'], $f2[0], 1, 'report test');
    $r = AgentReport::build($A, $win);
    check('after a seat cancel: 4 pax, commission Rs 1,000', (int) $r['totals']['pax'] === 4 && money_is((float) $r['totals']['commission'], 1000.0));

    // A manual correction shows as a window figure, not on a ticket.
    AgentWallet::record($A, 'adjustment', 150.0, ['account' => 'commission', 'direction' => 'credit', 'note' => 'report test bonus', 'ref' => 'ADJ commission', 'by' => 1]);
    $r = AgentReport::build($A, AgentReport::window('today', '', '', 'sale'));
    check('correction listed in the period (+Rs 150)', money_is((float) $r['totals']['adjust'], 150.0));

    // Back to the company tier.
    AgentWallet::setCommissionOverride($A, null, null, 1);
    check('reset: rate card back on the tier (Rs 200)', AgentReport::rateCard($A)['mode'] === 'tier' && money_is(AgentReport::rateCard($A)['flat'], 200.0));

    // Code change.
    $free = AgentWallet::AGENT_CODE_MAX - 3;
    AgentWallet::setAgentCode($A, $free, 1);
    check('agent code set', AgentWallet::agentCodeFor($A) === $free);

    // Windows.
    $w = AgentReport::window('custom', $D2, $D1, 'sale');
    check('custom window: backwards dates are swapped', $w['from'] === $D1 && $w['last'] === $D2);
    $w = AgentReport::window('yesterday', '', '', 'journey');
    check('yesterday: one day', $w['from'] === addDaysISO(todayISO(), -1) && $w['to'] === todayISO());
    $w = AgentReport::window('nonsense', '', '', 'x');
    check('unknown preset -> this month, journey', $w['preset'] === 'month' && $w['basis'] === 'journey');
    $w = AgentReport::window('lastmonth');
    check('last month ends on the 1st of this month', $w['to'] === date('Y-m-01'));
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if ($A > 0 && !$keep) { ar_cleanup($A); }
    if ($keep) { echo "  (kept demo agent #$A)\n"; }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
