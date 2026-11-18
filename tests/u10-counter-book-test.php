<?php
/**
 * 19 Nov 2026 (WA-6) — CounterBook::day(): per desk (or the seller's own line
 * when no desk) tickets, passengers, cash vs online (verified only), NPR,
 * pending, commission, net — the admin/counters.php rules.
 *
 *   php -c <ini> tests/u10-counter-book-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/u10-seed-lib.php';
require_once INCLUDE_PATH . '/counterbook.php';

echo "\n=== u10 · CounterBook (counter-wise day book) ===\n\n";
try {
    $s = u10_seed();
    $b = $s['b'];
    $book = CounterBook::day(U10_FD);
    $rows = $book['rows'];
    $ag   = 'AG:' . $s['agent'];
    $amt  = static fn(string $k): float => (float) $b[$k]['total_amount'];

    check('desk MSA: 3 tickets, 4 passengers', ($rows['MSA']['tickets'] ?? 0) === 3 && ($rows['MSA']['pax'] ?? 0) === 4, json_encode([$rows['MSA']['tickets'] ?? null, $rows['MSA']['pax'] ?? null]));
    check('desk MSA: cash = M1, online = M2 (verified only)', abs(($rows['MSA']['cash'] ?? -1) - $amt('M1')) < 0.01 && abs(($rows['MSA']['online'] ?? -1) - $amt('M2')) < 0.01);
    check('desk MSA: pending = P1 (pay at boarding still to collect)', abs(($rows['MSA']['pending'] ?? -1) - $amt('P1')) < 0.01 && ($rows['MSA']['pendingN'] ?? 0) === 1);
    check('desk NPJ: cash in INR + NPR physically taken', abs(($rows['NPJ']['cash'] ?? -1) - $amt('N1')) < 0.01 && abs(($rows['NPJ']['takenNpr'] ?? -1) - round($amt('N1') * 1.6)) < 0.01);
    check('desk NPJ: currency NPR, a rate', ($rows['NPJ']['currency'] ?? '') === 'NPR' && (float) ($rows['NPJ']['rate'] ?? 0) > 0);
    check('agent with no desk has its OWN line (not online)', isset($rows[$ag]) && ($rows[$ag]['kind'] ?? '') === 'seller' && ($rows[$ag]['tickets'] ?? 0) === 1);
    check('agent line: cash + ₹150 commission, net = cash - commission', abs(($rows[$ag]['commission'] ?? 0) - 150) < 0.01
        && abs(($rows[$ag]['net'] ?? 0) - ($amt('G1') - 150)) < 0.01, json_encode([$rows[$ag]['commission'] ?? null, $rows[$ag]['net'] ?? null]));
    check('agent line label carries the name', str_contains((string) ($rows[$ag]['label'] ?? ''), 'ZZTEST u10 Agent'));
    check('online line = O1 only', ($rows['']['tickets'] ?? 0) === 1 && abs(($rows['']['online'] ?? 0) - $amt('O1')) < 0.01 && (float) ($rows['']['cash'] ?? 0) === 0.0);
    $pay = (float) Database::scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified' AND verified_at >= :f AND verified_at < :t",
        ['f' => U10_FD, 't' => addDaysISO(U10_FD, 1)], 0);
    check('totals: cash + online = every verified payment of the day', abs($book['totals']['cash'] + $book['totals']['online'] - $pay) < 0.01, $book['totals']['cash'] . '+' . $book['totals']['online'] . ' vs ' . $pay);
    check('totals: 6 tickets, 7 passengers', $book['totals']['tickets'] === 6 && $book['totals']['pax'] === 7, json_encode([$book['totals']['tickets'], $book['totals']['pax']]));
    check('order: desks, then sellers, then online', array_key_last($rows) === '' && array_search($ag, array_keys($rows), true) > array_search('NPJ', array_keys($rows), true));
    check('line(): an unknown day is zeros', (int) CounterBook::line('2001-01-01', 'MSA')['tickets'] === 0 && CounterBook::line('2001-01-01', 'NPJ')['currency'] === 'NPR');
    check('range() swaps a reversed window', CounterBook::range(addDaysISO(U10_FD, 1), U10_FD)['from'] === U10_FD);
} catch (Throwable $e) {
    $GLOBALS['FAIL']++;
    echo '  ERROR  ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
u10_cleanup();
echo "\n  {$GLOBALS['PASS']} passed, {$GLOBALS['FAIL']} failed\n";
exit($GLOBALS['FAIL'] === 0 ? 0 : 1);
