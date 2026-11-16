<?php
/**
 * 16 Nov 2026 (lead, after unit X1): an agent's commission is never worked on
 * the desk extras (extra-luggage fee, infant fee). AgentWallet::commissionBase()
 * = total_amount - luggage_fee - infant_fee; cash_due keeps the full total.
 *
 *   php -c <ini> tests/u9-lead-commission-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $what\n"; } else { $fail++; echo "  FAIL  $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}

echo "\n=== Commission base: desk extras never earn commission ===\n\n";
check('the helper exists and is public', method_exists('AgentWallet', 'commissionBase'));
check('total minus luggage and infant fees', AgentWallet::commissionBase(['total_amount' => 2500, 'luggage_fee' => 200, 'infant_fee' => 100]) === 2200.0);
check('no extras: the whole total', AgentWallet::commissionBase(['total_amount' => 2200, 'luggage_fee' => 0, 'infant_fee' => 0]) === 2200.0);
check('a row that did not select the columns and has no id: the whole total', AgentWallet::commissionBase(['total_amount' => 1999.5]) === 1999.5);
check('an unknown booking id: the whole total (no row, no extras)', AgentWallet::commissionBase(['total_amount' => 1800], 987654321) === 1800.0);
check('never below zero', AgentWallet::commissionBase(['total_amount' => 100, 'luggage_fee' => 500]) === 0.0);
check('negative fees are ignored', AgentWallet::commissionBase(['total_amount' => 1000, 'luggage_fee' => -50]) === 1000.0);

$src = (string) file_get_contents(INCLUDE_PATH . '/agentwallet.php');
check('accrue() works commission on commissionBase()', str_contains($src, '$commBase = self::commissionBase($booking, $bookingId);'));
check('cash_due still records the full total', str_contains($src, "'amount'         => \$total,"));
$lc = (string) file_get_contents(INCLUDE_PATH . '/ledgercheck.php');
check('the ledger check expects commission on the same base', str_contains($lc, 'AgentWallet::commissionBase($r, $bid)'));

echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
