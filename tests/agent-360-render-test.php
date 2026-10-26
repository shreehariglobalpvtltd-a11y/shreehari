<?php
/**
 * Agent 360 / settlement receipts / KYC streaming — source-level regression
 * test (17 Sep 2026).
 *
 * The finance UI added on 17 Sep 2026 is mostly gates and wiring: a
 * supervisor-only 360 page, a printable receipt that only its agent or the
 * office may open, a KYC document that is streamed through a gated script
 * and never linked under /uploads, and the agent panel's payout request
 * routed through AgentWallet::requestPayout. None of that needs a database
 * to check — a gate that disappears from the source is the bug — so this
 * suite greps the pages the way tests/counter-role-test.php does, and runs
 * with no config at all.
 *
 *   php -c .claude/php-dev.ini tests/agent-360-render-test.php
 *
 * CLI only. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}
$root = dirname(__DIR__);
$src  = static function (string $rel) use ($root): string {
    $p = $root . '/' . $rel;
    return is_file($p) ? (string) file_get_contents($p) : '';
};

echo "\n=== admin/agent-360.php — supervisor-only, every money action walled ===\n";
$a360 = $src('admin/agent-360.php');
check('agent-360.php exists', $a360 !== '');
check('boots through _guard.php', str_contains($a360, "require __DIR__ . '/_guard.php';") && str_contains($a360, 'admin_boot('));
check("gated on customers.view like the register", str_contains($a360, "admin_boot('customers.view')"));
check("requires commissions.view", str_contains($a360, "Auth::requireAdmin('commissions.view')"));
check('refuses a counter agent with 403', preg_match('/if \(Auth::isCounterAgent\(\)\) \{\s*http_response_code\(403\);/s', $a360) === 1);
check('every POST checks the CSRF token', str_contains($a360, 'Security::verifyCsrf()'));
check('money actions need commissions.pay', str_contains($a360, "Auth::can('commissions.pay')"));
check('KYC actions need staff.manage', str_contains($a360, "Auth::can('staff.manage')"));
check('self-settlement wall (superadmin only)', str_contains($a360, '$targetId === $selfId && !Auth::isSuperadmin()'));
check('redirects after every POST (no re-post on refresh)', str_contains($a360, 'Response::redirect(') && substr_count($a360, 'a360_go(') >= 10);
check('links the printable receipt', str_contains($a360, 'agent-receipt.php?id='));
check('KYC documents go through the gated viewer', str_contains($a360, 'agent-kyc-file.php?agent='));
check('KYC documents are never linked under /uploads', !str_contains($a360, '/uploads/agents-kyc'));
check('loans register is synced before it is read', strpos($a360, 'AgentWallet::syncLoanRecovery(') < strpos($a360, 'AgentWallet::loans('));
check('issue / repay / write off go through AgentWallet', str_contains($a360, 'AgentWallet::issueLoan(') && str_contains($a360, 'AgentWallet::repayLoan(') && str_contains($a360, 'AgentWallet::writeOffLoan('));
check('a posted loan id is checked against the agent', str_contains($a360, "(int) \$loan['agent_admin_id'] !== \$agentId"));
check('payout requests are decided through decidePayoutRequest', str_contains($a360, "AgentWallet::decidePayoutRequest(") && str_contains($a360, "'paid'") && str_contains($a360, "'declined'"));
check("'Mark paid' records the payout first, then decides the request", preg_match("/AgentWallet::record\(\\\$targetId, 'payout', \\\$amount,.*?AgentWallet::decidePayoutRequest\(\(int\) \\\$req\['id'\], 'paid'/s", $a360) === 1);
check('deposits go through recordDeposit with note + ref', str_contains($a360, 'AgentWallet::recordDeposit($targetId, $amount, $selfId,'));
check('KYC upload goes through saveKycDoc, verdict through kycVerify', str_contains($a360, 'AgentWallet::saveKycDoc(') && str_contains($a360, 'AgentWallet::kycVerify('));
check('tells the office to migrate when KYC columns are missing', str_contains($a360, 'AgentWallet::kycAvailable()') && str_contains($a360, 'upgrade-2026-09-agent-kyc.sql'));
check('settlement WhatsApp honours agent_notify_settlement', str_contains($a360, "Settings::getBool('agent_notify_settlement', true)") && str_contains($a360, 'Notify::whatsapp('));
check('statement uses opening / running / closing from AgentWallet::statement', str_contains($a360, 'AgentWallet::statement(') && str_contains($a360, 'AgentWallet::openingBalance('));
check('12-month commission bars come from commissionByMonth', str_contains($a360, 'AgentWallet::commissionByMonth('));
check('net position comes from netPosition()', str_contains($a360, 'AgentWallet::netPosition('));
check('every tab section is present', substr_count($a360, 'class="a360-tab" data-tab="') === 8);
check('output is escaped (Security::e used throughout)', substr_count($a360, 'Security::e(') >= 5 && substr_count($a360, '$e(') >= 40);
check('face photo is served from /uploads/agents/ only', str_contains($a360, "str_starts_with((string) \$profile['photo_path'], 'agents/')"));

echo "\n=== admin/agent-kyc-file.php — gated streaming, never a public URL ===\n";
$kyc = $src('admin/agent-kyc-file.php');
check('agent-kyc-file.php exists', $kyc !== '');
check('boots through _guard.php', str_contains($kyc, "require __DIR__ . '/_guard.php';") && str_contains($kyc, 'admin_boot('));
check('gate: staff.manage OR commissions.view OR own profile', str_contains($kyc, "Auth::can('staff.manage') || Auth::can('commissions.view') || \$own") && str_contains($kyc, 'Auth::bookingScopeAdminId() === $agentId'));
check("the stored path must start with 'agents-kyc/'", str_contains($kyc, "str_starts_with(\$rel, 'agents-kyc/')"));
check('traversal is refused', str_contains($kyc, "str_contains(\$rel, '..')"));
check('the resolved file must sit inside uploads/agents-kyc', str_contains($kyc, 'realpath(') && str_contains($kyc, 'str_starts_with($abs, $dir . DIRECTORY_SEPARATOR)'));
check('real MIME type read with finfo', str_contains($kyc, 'new finfo(FILEINFO_MIME_TYPE)'));
check('streams with readfile', str_contains($kyc, 'readfile($abs)'));
check('no /uploads/ link anywhere in the streamer', !str_contains($kyc, "'/uploads/") && !str_contains($kyc, '"/uploads/'));
check('no caching of an ID document', str_contains($kyc, 'no-store'));
check('every view is audited', str_contains($kyc, "Logger::audit('agent.kyc_viewed'"));

echo "\n=== admin/agent-receipt.php — the agent's own row or commissions.view ===\n";
$rc = $src('admin/agent-receipt.php');
check('agent-receipt.php exists', $rc !== '');
check('boots through _guard.php', str_contains($rc, "require __DIR__ . '/_guard.php';") && str_contains($rc, 'admin_boot('));
check('gate: commissions.view OR the row belongs to the signed-in agent', str_contains($rc, "Auth::can('commissions.view') && !\$own") && str_contains($rc, 'Auth::bookingScopeAdminId() === $agentId'));
check('404 text when the row is missing', str_contains($rc, 'http_response_code(404)'));
check('letterhead from Settings::company()', str_contains($rc, 'Settings::company()'));
check('has a Print button and print CSS', str_contains($rc, 'window.print()') && str_contains($rc, '@media print'));
check('amount in words on the voucher', str_contains($rc, 'receipt_words('));
check('standalone page (no admin chrome)', !str_contains($rc, 'admin_header(') && str_contains($rc, '<!doctype html>'));

echo "\n=== admin/agent.php — one payout-request block, wallet routes through AgentWallet ===\n";
$ag = $src('admin/agent.php');
check("exactly one 'Request a payout' block (id=\"payoutRequest\")", substr_count($ag, 'id="payoutRequest"') === 1);
check('payout request goes through AgentWallet::requestPayout', str_contains($ag, 'AgentWallet::requestPayout($selfId, $amount, $reqNote)'));
check('the office WhatsApp alert on a payout request is kept', str_contains($ag, '💸 Payout request'));
check('office viewers still walled behind commissions.view', str_contains($ag, "if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }"));
check("generic correction action ('adjustment') exists", str_contains($ag, "\$action === 'adjustment'") && str_contains($ag, "AgentWallet::record(\$targetId, 'adjustment', \$adjAmt, ["));
check('correction needs a note of at least 5 characters', str_contains($ag, 'mb_strlen($adjNote) < 5'));
check("correction is tagged ref 'ADJ'", str_contains($ag, "'ADJ' . (\$adjRef !== '' ? ' ' . \$adjRef : '')"));
check('advance form replaced by issueLoan / repayLoan', str_contains($ag, 'AgentWallet::issueLoan(') && str_contains($ag, 'AgentWallet::repayLoan(') && !str_contains($ag, "name=\"action\" value=\"advance\""));
check('a repayment checks the loan belongs to the agent', str_contains($ag, "(int) \$loan['agent_admin_id'] !== \$targetId"));
check('every money action keeps the self-settlement wall', substr_count($ag, '$targetId === $selfId && !Auth::isSuperadmin()') >= 6);
check('loans register rendered compactly', str_contains($ag, 'AgentWallet::syncLoanRecovery($viewId)') && str_contains($ag, 'Loans &amp; advances register'));
check("ledger_look labels 'ADJ' as Correction and 'ADVANCE L<n>' as Advance/Loan #n", str_contains($ag, "'Correction'") && str_contains($ag, "'Advance/Loan #' . (int) \$m[1]"));
check('payout / handover rows link their receipt', str_contains($ag, 'agent-receipt.php?id=<?= (int) $l[\'id\']'));
check('supervisors get a Full 360 view button', str_contains($ag, 'agent-360.php?agent=') && str_contains($ag, 'Full 360 view'));
check('a payout that covers the open request settles it', str_contains($ag, "AgentWallet::decidePayoutRequest((int) \$oreq['id'], 'paid'"));
check('the agent is told on WhatsApp after a settlement', str_contains($ag, 'agent_settlement_notify(') && str_contains($ag, "Settings::getBool('agent_notify_settlement', true)"));
check('design-system KPIs on the top cards', str_contains($ag, 'admin_kpi(') && str_contains($ag, 'admin_page_head('));
check('the agent dashboard still links the QuickBot desk', str_contains($ag, '/admin/quick-ticket.php'));

echo "\n=== admin/agents.php + admin/accounting.php — wired to the 360 view ===\n";
$ags = $src('admin/agents.php');
check("register 'Wallet' button opens agent-360", str_contains($ags, 'href="agent-360.php?agent=<?= (int) $r[\'id\']'));
check('register has a KYC column', str_contains($ags, '<th>KYC</th>') && str_contains($ags, "data-kyc="));
check('register shows the net position', str_contains($ags, 'Net position') && str_contains($ags, "\$r['net']"));
check("register flags 'Settlement overdue'", str_contains($ags, 'AgentWallet::settlementOverdueDays(') && str_contains($ags, 'Settlement overdue'));
check('register only selects kyc_status once the migration has run', str_contains($ags, 'AgentWallet::kycAvailable()'));
check('register still requires commissions.view', str_contains($ags, "Auth::requireAdmin('commissions.view')"));
$acc = $src('admin/accounting.php');
check("accounting 'Settle →' opens agent-360 settlements", str_contains($acc, 'agent-360.php?agent=<?= (int) $c[\'agent_admin_id\']') && str_contains($acc, '&amp;tab=settlements'));
check('accounting lists open payout requests', str_contains($acc, 'AgentWallet::openPayoutRequests()') && str_contains($acc, 'Open payout requests'));
check('each open request links to the decision screen', str_contains($acc, '&amp;tab=requests'));

echo "\n=== nothing links a KYC document under /uploads ===\n";
foreach (['admin/agent-360.php', 'admin/agent.php', 'admin/agents.php', 'admin/agent-receipt.php', 'admin/agent-kyc-file.php', 'admin/accounting.php'] as $p) {
    check("$p never links /uploads/agents-kyc", !str_contains($src($p), '/uploads/agents-kyc'));
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
