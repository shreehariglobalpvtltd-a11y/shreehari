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
    return is_file($p) ? str_replace("\r\n", "\n", (string) file_get_contents($p)) : '';   // CRLF or LF, the checks read the same text
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
/* The tab list grew past the original eight (report 29 Oct, targets 29 Oct,
   messages 9 Oct when the office chat is installed). The guard is that every
   tab the page offers has its section and no section is orphaned, and that
   the original eight are still there. */
$tabKeys = [];
if (preg_match('/\$tabs = \[(.*?)\];/s', $a360, $tm) === 1) {
    preg_match_all("/'([a-z]+)'\s*=>/", $tm[1], $tk);
    $tabKeys = $tk[1];
}
if (str_contains($a360, "['messages' => ")) { $tabKeys[] = 'messages'; }
preg_match_all('/class="a360-tab" data-tab="([a-z]+)"/', $a360, $secM);
$secKeys = $secM[1];
sort($tabKeys); sort($secKeys);
check('every tab section is present', $tabKeys !== [] && $tabKeys === $secKeys
    && array_diff(['overview', 'statement', 'settlements', 'loans', 'deposits', 'requests', 'profile', 'activity'], $secKeys) === []);
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
/* 16 Nov 2026 (battery Y7). admin/agent.php is the AGENT COCKPIT now (29 Oct
   2026 redesign) and the supervisor's money desk is admin/agent-360.php. The
   17 Sep checks below that describe something which MOVED to agent-360 (the
   commission correction, the Correction / Advance-Loan ledger labels) or was
   re-designed (open payout requests are reserved inside AgentWallet::record()
   instead of being closed by a covering payout) now guard the same property
   where it lives. Four are kept as written (the WhatsApp one reads the
   payout branch instead of one helper name) because agent.php really lost
   them - they are reported to the lead as app bugs:
     - the agent's payout request does not reach agent_payout_requests, so
       accounting's "Open payout requests" and Agent 360 never see it
     - the Settle block still gives a bare advance outside the loans register
     - no ledger row links its receipt (an agent cannot reach their voucher:
       Agent 360, which links it, refuses a counter agent)
     - a payout recorded here does not tell the agent on WhatsApp
   A fifth, new check found admin/agents.php calling two AgentWallet methods
   that do not exist (its "Record Wallet Settlement" form dies with a 500). */
$ag = $src('admin/agent.php');
check("exactly one 'Request a payout' block (id=\"payoutRequest\")", substr_count($ag, 'id="payoutRequest"') === 1);
check('payout request goes through AgentWallet::requestPayout', str_contains($ag, 'AgentWallet::requestPayout($selfId, $amount, $reqNote)'));
check('the office WhatsApp alert on a payout request is kept', str_contains($ag, '💸 Payout request'));
check('office viewers still walled behind commissions.view', str_contains($ag, "if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }"));
// moved: the one commission correction is agent-360's comm_adjust (read inside that branch only)
$adjAt     = strpos($a360, "\$action === 'comm_adjust'");
$adjNext   = $adjAt === false ? false : strpos($a360, '} elseif (', $adjAt + 10);
$adjBranch = $adjAt === false ? '' : substr($a360, $adjAt, ($adjNext === false ? strlen($a360) : $adjNext) - $adjAt);
check("generic correction action ('adjustment') exists (Agent 360 comm_adjust)", str_contains($adjBranch, "AgentWallet::record(\$targetId, 'adjustment', \$amt, ["));
check('correction needs a note of at least 5 characters', str_contains($adjBranch, 'if (mb_strlen($note) < 5) {'));
check("correction is tagged ref 'ADJ'", str_contains($adjBranch, "'ref'       => 'ADJ commission',"));
check('  …and keeps the money wall: commissions.pay + never on your own account', str_contains($adjBranch, '$needPay();') && str_contains($adjBranch, '$notSelf();'));
check('advance form replaced by issueLoan / repayLoan', str_contains($ag, 'AgentWallet::issueLoan(') && str_contains($ag, 'AgentWallet::repayLoan(') && !str_contains($ag, "name=\"action\" value=\"advance\""));
// agent.php repays no loan itself; if it ever does, the ownership check must come with it
check('a repayment checks the loan belongs to the agent', !str_contains($ag, 'AgentWallet::repayLoan(') || str_contains($ag, "(int) \$loan['agent_admin_id'] !== \$targetId"));
// every money action on the cockpit's Settle block keeps the wall (counted per branch, not a fixed 6)
$moneyBranches = [];
foreach (["\$action === 'payout' || \$action === 'cash_handover'", "\$action === 'record_deposit'", "\$action === 'post_salary'", "\$action === 'advance'"] as $head) {
    $at = strpos($ag, $head);
    if ($at === false) { continue; }
    $next = strpos($ag, '} elseif (', $at + strlen($head));
    $moneyBranches[$head] = substr($ag, $at, ($next === false ? strlen($ag) : $next) - $at);
}
check('every money action keeps the self-settlement wall', count($moneyBranches) === 4
    && array_filter($moneyBranches, static fn(string $b): bool => !str_contains($b, '$targetId === $selfId && !Auth::isSuperadmin()')) === []);
check('loans register rendered compactly (Agent 360) - the cockpit shows the advance still owed', str_contains($a360, 'AgentWallet::syncLoanRecovery(') && str_contains($ag, '$advance    = AgentWallet::advanceSummary($viewId);'));
check("ledger_look labels 'ADJ' as Correction and 'ADVANCE L<n>' as Advance/Loan #n (Agent 360; the cockpit reads every ADVANCE ref as an Advance)",
    str_contains($a360, "return ['edit', 'Correction', 'st-muted'];") && str_contains($a360, "'Advance/Loan #' . (int) \$m[1]")
    && str_contains($ag, "if (\$type === 'adjustment' && stripos(\$ref, 'ADVANCE') === 0) {\n        return ['💸', 'Advance', '#8a5300'];"));
check('payout / handover rows link their receipt', str_contains($ag, 'agent-receipt.php?id=<?= (int) $l[\'id\']'));
check('supervisors get a Full 360 view button (links into Agent 360 for this agent)', str_contains($ag, '$ckCan360   = !$isOwn && Auth::can(\'customers.view\');') && str_contains($ag, 'href="/admin/agent-360.php?agent=<?= $viewId ?>'));
/* Re-designed: a payout can no longer eat into an open request - record()
   reserves every open agent_payout_requests amount and sends the office to
   Withdrawals, where the request is decided (decidePayoutRequest). */
$aw = $src('includes/agentwallet.php');
check('a payout that covers the open request settles it (open requests are reserved inside AgentWallet::record)',
    str_contains($aw, "FROM agent_payout_requests WHERE agent_admin_id=:a AND status='open' AND id<>:i") && str_contains($a360, "AgentWallet::decidePayoutRequest((int) \$req['id'], 'paid'"));
/* The 17 Sep helper was agent_settlement_notify(); Agent 360 names its own
   a360_notify_settlement(). Either way the property is the same: the cockpit's
   payout / cash-handover branch tells the agent, under agent_notify_settlement. */
$settleBranch = $moneyBranches["\$action === 'payout' || \$action === 'cash_handover'"] ?? '';
check('the agent is told on WhatsApp after a settlement',
    $settleBranch !== '' && (bool) preg_match('/(_notify_settlement|settlement_notify|Notify::whatsapp)\s*\(/i', $settleBranch)
    && str_contains($ag, "Settings::getBool('agent_notify_settlement', true)"));
check('design-system KPIs on the top cards (the cockpit: net position, cash, commission)', str_contains($ag, '$net         = AgentWallet::netPosition($viewId);') && str_contains($ag, "\$ckNetCls   = \$net['net'] > 0.009"));
check('the agent dashboard still links the QuickBot desk', str_contains($ag, '/admin/quick-ticket.php'));

/* Every AgentWallet method the money pages call must exist: a page that calls
   one that does not dies with a 500 the moment its form is posted. */
preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $aw, $awFn);
$awHave = array_flip($awFn[1]);
$awMissing = [];
foreach (['admin/agent.php', 'admin/agents.php', 'admin/agent-360.php', 'admin/accounting.php', 'admin/agent-receipt.php'] as $pg) {
    preg_match_all('/AgentWallet::([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src($pg), $calls);
    foreach (array_unique($calls[1]) as $fn) {
        if (!isset($awHave[$fn])) { $awMissing[] = basename($pg) . ' → AgentWallet::' . $fn . '()'; }
    }
}
check('every AgentWallet method the agent money pages call exists', $awMissing === []);
if ($awMissing !== []) { echo '        missing: ' . implode(', ', $awMissing) . "\n"; }

echo "\n=== admin/agents.php + admin/accounting.php — wired to the 360 view ===\n";
/* The register (5 Sep Excel-style, tiers 30 Oct) opens the agent's COCKPIT
   (admin/agent.php) from its Wallet button, and the cockpit carries what the
   17 Sep register columns showed: the KYC step, the net position and the
   overdue flag - with the supervisor's way on into Agent 360. */
$ags = $src('admin/agents.php');
check("register 'Wallet' button opens the agent's wallet cockpit", str_contains($ags, 'href="agent.php?agent=<?= (int) $r[\'id\'] ?>" title="Wallet, ledger, payouts">💼 Wallet</a>'));
check('register has a KYC column (the cockpit it opens shows the KYC step; Agent 360 verifies it)', str_contains($ag, "\$kycSt   = (string) (\$profile['kyc_status'] ?? 'none');") && str_contains($ag, '2 · KYC (ID) check') && str_contains($ag, '&amp;tab=profile">Verify KYC in Agent 360'));
check('register shows the net position (commission due + cash held per agent; net on the cockpit)', str_contains($ags, "\$r['comm_due'] = (float) (\$bal['commission'] ?? 0);") && str_contains($ags, "\$r['cash_bal'] = (float) (\$bal['cash'] ?? 0);") && str_contains($ag, 'AgentWallet::netPosition($viewId)'));
check("register flags 'Settlement overdue' (on the cockpit it opens)", str_contains($ag, '$overdueDays = AgentWallet::settlementOverdueDays($viewId);') && str_contains($ag, 'Overdue · ढिलो'));
check('register only selects kyc_status once the migration has run', !str_contains($ags, 'kyc_status') || str_contains($ags, 'AgentWallet::kycAvailable()'));
check('register still requires commissions.view', str_contains($ags, "Auth::requireAdmin('commissions.view')"));
// 16 Nov 2026 (F2): the register's "Record Wallet Settlement" writes the Agent 360 row with a reference the office types
check("register settlement goes through AgentWallet::record with a typed UTR / receipt no. (no auto 'SETTL-' ref) behind commissions.pay",
    str_contains($ags, 'AgentWallet::record($agentId, $entry, $amount,') && !str_contains($ags, "'SETTL-'")
    && str_contains($ags, 'name="settle_ref"') && str_contains($ags, "Auth::can('commissions.pay')"));
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
