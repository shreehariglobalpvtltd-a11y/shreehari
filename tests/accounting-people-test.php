<?php
/**
 * tests/accounting-people-test.php — salaries, payouts and loans reach the
 * day-book and the agent's account statement (3 Oct 2026).
 *
 * Uses the real AgentWallet paths (setSalary/postSalary, issueLoan, record
 * payout/adjustment/cash_handover) on a throwaway agent, renders
 * admin/accounting.php through the CLI harness and the agent report PDF,
 * then cleans up. SHG_KEEP=1 leaves the demo agent for a visual check.
 *
 *   php -c .claude/php-dev.ini tests/accounting-people-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/reportpdf.php';
if (!str_contains(DB_NAME, 'test')) { fwrite(STDERR, "Refusing: DB_NAME is not a test database\n"); exit(2); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  ok   $l\n"; } else { $FAIL++; echo "  FAIL $l\n"; } }

$u = 'acc-people-demo';
$old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $u]);
if ($old !== null) { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => (int) $old['id']]); Database::delete('agent_loans', 'agent_admin_id = :a', ['a' => (int) $old['id']]); Database::delete('admins', 'id = :a', ['a' => (int) $old['id']]); }
$A = Database::insert('admins', ['username' => $u, 'password_hash' => password_hash('x-no-login', PASSWORD_BCRYPT), 'full_name' => 'Accounting Demo Agent', 'role' => 'agent', 'is_active' => 1]);

try {
    AgentWallet::record($A, 'adjustment', 3000, ['by' => 1, 'note' => 'opening commission (test)', 'ref' => 'ADJ TEST']);
    AgentWallet::setSalary($A, 5000, 1);
    AgentWallet::postSalary($A, date('Y-m'), 1);
    $loan = AgentWallet::issueLoan($A, 'loan', 2000, 'fixed', 500, 'test loan', 1);
    AgentWallet::record($A, 'payout', 1500, ['by' => 1, 'note' => 'test payout']);
    $bal = AgentWallet::balances($A);
    check('wallet: 3000 + 5000 salary - 2000 loan - 1500 payout = 4500', abs($bal['commission'] - 4500) < 0.01);

    // The day-book, rendered as the office sees it.
    $_GET = ['from' => date('Y-m-d'), 'to' => date('Y-m-d')];
    $html = (string) shell_exec(escapeshellarg(PHP_BINARY) . (php_ini_loaded_file() ? ' -c ' . escapeshellarg((string) php_ini_loaded_file()) : '')
        . ' ' . escapeshellarg(__DIR__ . '/render-admin.php') . ' accounting.php from=' . date('Y-m-d') . ' to=' . date('Y-m-d') . ' 2>&1');
    check('accounting renders', str_contains($html, 'Salaries, payouts') && !preg_match('/Fatal error|Uncaught/', $html));
    check('  agent salary counted (Rs 5,000)', (bool) preg_match('/Agent salary credited<\/th><td class="acc-num">₹5,000/u', $html));
    check('  payout counted (Rs 1,500)', (bool) preg_match('/Commission paid out to agents<\/th><td class="acc-num">₹1,500/u', $html));
    check('  loan given counted (Rs 2,000)', (bool) preg_match('/Advances \/ loans given — agents<\/th><td class="acc-num">₹2,000/u', $html));
    check('  open loan listed with what is still out', str_contains($html, 'Accounting Demo Agent') && str_contains($html, 'Loans &amp; advances still out'));
    check('  net subtracts salaries', str_contains($html, 'Net after expenses &amp; salaries'));

    // The agent's account statement PDF now carries the wallet.
    $b = ReportPdf::agentReportBytes($A, date('Y-m-01'), date('Y-m-d'));
    check('account statement PDF builds (' . strlen($b['bytes']) . ' bytes)', strlen($b['bytes']) > 1500 && str_starts_with($b['bytes'], '%PDF'));
    $st = AgentWallet::statement($A, date('Y-m-01'), date('Y-m-d'), 'commission');
    check('statement closing balance = wallet balance', abs((float) $st['closing'] - $bal['commission']) < 0.01);
} catch (Throwable $e) {
    $FAIL++; echo '  FAIL exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    if (!getenv('SHG_KEEP')) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $A]);
        Database::delete('agent_loans', 'agent_admin_id = :a', ['a' => $A]);
        try { Settings::set('agent_salaries', json_encode(array_diff_key(jsonColumn(Settings::get('agent_salaries', '{}')), [(string) $A => 1])) ?: '{}', 'json', 'agent'); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $A]);
    }
}
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
