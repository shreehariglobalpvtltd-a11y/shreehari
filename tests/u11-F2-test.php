<?php
/**
 * u11-F2 — agent money doors (16 Nov 2026, unit F2-agentmoney).
 *
 * Over real HTTP, the five agent-money bugs tests/agent-360-render-test.php
 * found (and the fixes in admin/agents.php + admin/agent.php):
 *
 *   1 agents.php "Record Wallet Settlement" called two AgentWallet methods
 *     that never existed (a 500 on every submit) and stamped its own
 *     'SETTL-<time>' reference. Now AgentWallet::record() 'payout' (needs the
 *     real UTR / receipt no.) or 'cash_handover' (blank = SV voucher), with
 *     commissions.pay, an agent target and the audit row.
 *   2 agent.php payout_request only wrote an audit row: now an
 *     agent_payout_requests row through AgentWallet::requestPayout() (one
 *     open at a time, reserved inside record(), seen by accounting / Agent
 *     360); the office WhatsApp is kept; a declined request stops "waiting".
 *   3 agent.php advance was a bare recordAdvance(): now issueLoan() (same
 *     commission debit, ref 'ADVANCE L<id>', plus the loans register row) and
 *     repayLoan() against one of THIS agent's open register rows.
 *   4 the wallet history links each non-sale row's printable receipt
 *     (agent-receipt.php: the agent's own rows, the office any).
 *   5 a payout / cash handover on agent.php WhatsApps the agent - the Agent
 *     360 message - only while agent_notify_settlement is on.
 *
 * F2 refix (same day), three review findings:
 *   6 an advance given before the loans register (bare 'ADVANCE' debit, no
 *     agent_loans row - the old Settle block, still live) could no longer be
 *     repaid on agent.php: the repayment form now offers it as loan_id 'pre'
 *     (and an old panel's "repaid" without a loan_id lands there), written as
 *     the old bare 'ADVANCE' credit but capped at what is still out on those
 *     advances - never at a balance pushed negative by something else.
 *   7 a whole-balance payout request (₹X due, ₹X asked) made every payout on
 *     the Settle block fail "Funds are reserved": the payout now names the
 *     request it covers (request_id) and settles it in the same transaction;
 *     a smaller payout says the money is held; the WhatsApp "Settle:" link
 *     opens the Wallet tab's Settle block.
 *   8 agent.php redirects after every post (a refresh re-sent the form: a
 *     second handover, a second advance, a second WhatsApp to the agent).
 *
 * Starts nothing: point it at a dev server on this worktree.
 *   SHG_TEST_BASE=http://localhost:9012 php -c <ini> tests/u11-F2-test.php
 * Every setting it turns is put back exactly as found; every row it makes is
 * removed (agents / ledger / loans / requests / messages / audit rows).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/quickunlock.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:9012'), '/'));
const LIKE      = '91000126';         // every phone below carries this
const PH_A      = '9100012601';
const PH_B      = '9100012602';
const PH_BOSS   = '9100012603';
const PH_C      = '9100012604';
const OFFICE_WA = '919100012609';     // admin_whatsapp while the suite runs
const AG_A      = 'u11_f2_agent_a';
const AG_B      = 'u11_f2_agent_b';
const AG_C      = 'u11_f2_agent_c';
const AG_D      = 'u11_f2_agent_d';
const PH_D      = '9100012605';
const BOSS      = 'u11_f2_boss';
const PW        = 'U11F2Money77x';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function http(string $jar, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function signIn(string $jar, string $user): array {
    @unlink($jar);
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
    $login = http($jar, '/admin/login.php?classic=1&other=1');
    return http($jar, '/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => $user, 'password' => PW]]);
}
/** The page's result flash: [kind, text incl. any link markup], or ['', '']. */
function flashOf(string $html): array {
    return preg_match('/<div class="flash (ok|bad)">(.*?)<\/div>/s', $html, $m) === 1 ? [$m[1], html_entity_decode(strip_tags($m[2], '<a>'))] : ['', ''];
}
function served(array $r): bool { return $r['code'] === 200 && !str_contains($r['body'], 'Application error'); }
function ledgerRows(int $agentId, string $type): array {
    return Database::fetchAll('SELECT * FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = :t ORDER BY id', ['a' => $agentId, 't' => $type]);
}
function waCount(string $to, string $like): int {
    return (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE channel = 'whatsapp' AND to_number = :t AND body LIKE :b", ['t' => $to, 'b' => $like], 0);
}
/** Every agent.php post: did it redirect (302) to exactly $back? [label => bool] */
$AG_REDIRECTS = [];
/**
 * Post a form to agent.php and follow its redirect-after-post (F2 refix): the
 * page the browser lands on - where the flash now shows - or the raw response
 * when the post did not redirect to $back.
 */
function postBack(string $jar, string $url, array $form, string $back): array {
    global $AG_REDIRECTS;
    $p  = http($jar, $url, ['form' => $form]);
    $ok = $p['code'] === 302 && preg_match('/^Location:\s*(\S+)\s*$/mi', $p['headers'], $m) === 1 && $m[1] === $back;
    $AG_REDIRECTS[] = $ok;
    return $ok ? http($jar, $back) : $p;
}

/* ---- settings this test turns, put back exactly as found ---------------- */
$keys = ['agent_notify_settlement', 'whatsapp_driver', 'admin_whatsapp', 'agent_payout_min', 'agent_advance_max', 'agent_wallet_enabled', 'wa_global_daily_cap'];
$keep = [];
foreach ($keys as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
};
$sw = static function (string $k, mixed $v, string $type = 'bool', string $group = 'agent'): void {
    Settings::set($k, $v, $type, $group, false);
    Settings::flush();
};

$cleanup = static function (): void {
    $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM admins WHERE username IN (:a, :b, :c, :d, :e)', ['a' => AG_A, 'b' => AG_B, 'c' => BOSS, 'd' => AG_C, 'e' => AG_D]), 'id'));
    foreach ($ids as $aid) {
        foreach (['agent_ledger', 'agent_loans', 'agent_payout_requests'] as $t) {
            try { Database::delete($t, 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        }
        try { Database::delete('audit_logs', "entity_type = 'admin' AND entity_id = :a AND action LIKE 'agent.%'", ['a' => (string) $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $aid]);
    }
    try { Database::delete('message_logs', "to_number LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
};

$jars = [];
foreach (['boss', 'a', 'b'] as $n) {
    $jars[$n] = sys_get_temp_dir() . '/shg_u11f2_' . $n . '_' . getmypid() . '.txt';
}

echo "\n=== u11-F2: agent money doors (agents.php settlement, agent.php request / advance / receipts / WhatsApp) ===\n";
$cleanup();
try {
    /* ---- fixtures ---------------------------------------------------------- */
    $sw('whatsapp_driver', 'click_to_chat', 'string', 'notify');   // message_logs + a wa.me link, no network
    $sw('admin_whatsapp', OFFICE_WA, 'string', 'notify');
    $sw('agent_notify_settlement', '1');
    $sw('agent_payout_min', '0', 'float');
    $sw('agent_advance_max', '0', 'float');
    $sw('agent_wallet_enabled', '1');
    $sw('wa_global_daily_cap', '0', 'int', 'notify');

    $mkAdmin = static function (string $user, string $name, string $role, string $phone): int {
        $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
        QuickUnlock::set($id, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
        return $id;
    };
    $A    = $mkAdmin(AG_A, 'U11 F2 Agent A', 'agent', PH_A);
    $B    = $mkAdmin(AG_B, 'U11 F2 Agent B', 'agent', PH_B);
    $boss = $mkAdmin(BOSS, 'U11 F2 Boss', 'superadmin', PH_BOSS);

    // A earned 1,000 commission and holds 600 cash. Dated last year, so the loans
    // register's recovery sync (commission earned since a loan's issue day) leaves
    // today's advances open for the repayment checks below.
    AgentWallet::record($A, 'commission', 1000.0, ['note' => 'u11 F2 seed', 'by' => 0]);
    AgentWallet::record($A, 'cash_due', 600.0, ['note' => 'u11 F2 seed', 'by' => 0]);
    Database::run("UPDATE agent_ledger SET created_at = '2025-11-01 10:00:00' WHERE agent_admin_id = :a", ['a' => $A]);
    $loanB = AgentWallet::issueLoan($B, 'advance', 100.0, 'full', 0.0, 'u11 F2 B loan', $boss);
    check('fixtures: A owes nothing and is owed ₹1,000 commission, holds ₹600 cash; B has an open advance',
        AgentWallet::balances($A)['commission'] === 1000.0 && AgentWallet::balances($A)['cash'] === 600.0 && $loanB > 0);

    $r = signIn($jars['boss'], BOSS);
    check('the office (superadmin) signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php') && !str_contains($r['headers'], 'pin-setup'), 'HTTP ' . $r['code']);

    /* ---- 1. agents.php: Record Wallet Settlement ----------------------------- */
    echo "\n== 1. agents.php Record Wallet Settlement → AgentWallet::record ==\n";
    $pg = http($jars['boss'], '/admin/agents.php');
    check('the register renders', served($pg), 'HTTP ' . $pg['code']);
    check('the settlement modal asks for the payment reference', str_contains($pg['body'], 'value="record_settlement"') && str_contains($pg['body'], 'name="settle_ref"'));
    check('…and the Record Wallet Settlement button is offered to the office', str_contains($pg['body'], 'openSettleModal(' . $A . ','));
    $csrf = csrfFrom($pg['body']);
    // The settlement redirects after the post (a refresh must not record it twice); the flash rides the session.
    $redirects = [];
    $post = static function (array $f) use ($jars, &$csrf, &$redirects): array {
        $p = http($jars['boss'], '/admin/agents.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'record_settlement'] + $f]);
        $redirects[] = $p['code'] === 302 && preg_match('/^Location:\s*\/admin\/agents\.php\s*$/mi', $p['headers']) === 1;
        return http($jars['boss'], '/admin/agents.php');
    };

    $r = $post(['agent_id' => $A, 'account_type' => 'commission', 'amount' => '300', 'settle_ref' => 'abc', 'settle_note' => 'u11']);
    [$fk, $ft] = flashOf($r['body']);
    check('a payout with a 3-character reference is refused (no 500)', served($r) && $fk === 'bad' && str_contains($ft, 'UTR'), "HTTP {$r['code']} $fk: " . mb_substr($ft, 0, 90));
    check('…and nothing reached the ledger', ledgerRows($A, 'payout') === []);

    $r = $post(['agent_id' => $A, 'account_type' => 'commission', 'amount' => '300', 'settle_ref' => 'UTRF2A0001', 'settle_note' => 'u11 bank']);
    [$fk, $ft] = flashOf($r['body']);
    $pay = ledgerRows($A, 'payout');
    check('a payout with the real UTR is recorded (was a fatal 500)', served($r) && $fk === 'ok' && str_contains($ft, 'Commission payout of') && str_contains($ft, 'UTRF2A0001'), "HTTP {$r['code']} $fk: " . mb_substr($ft, 0, 90));
    check('ledger: one payout row, −₹300 on commission, the typed reference, recorded by the office',
        count($pay) === 1 && (float) $pay[0]['amount'] === -300.0 && $pay[0]['account'] === 'commission' && $pay[0]['ref'] === 'UTRF2A0001' && (int) $pay[0]['created_by'] === $boss && $pay[0]['note'] === 'u11 bank');
    $payoutLedgerId = (int) ($pay[0]['id'] ?? 0);
    check('the audit row is written (agent.payout)', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agent.payout' AND entity_type = 'admin' AND entity_id = :a", ['a' => (string) $A], 0) === 1);

    $r = $post(['agent_id' => $A, 'account_type' => 'commission', 'amount' => '100', 'settle_ref' => 'UTRF2A0001']);
    [$fk, $ft] = flashOf($r['body']);
    check('the same UTR twice is refused (a reference pays once)', served($r) && $fk === 'bad' && str_contains($ft, 'already recorded') && count(ledgerRows($A, 'payout')) === 1, "$fk: " . mb_substr($ft, 0, 90));

    $r = $post(['agent_id' => $A, 'account_type' => 'cash', 'amount' => '200', 'settle_ref' => '', 'settle_note' => 'u11 cash']);
    [$fk, $ft] = flashOf($r['body']);
    $ho = ledgerRows($A, 'cash_handover');
    check('a cash handover with no reference is recorded with an SV voucher', served($r) && $fk === 'ok' && count($ho) === 1
        && (float) $ho[0]['amount'] === -200.0 && $ho[0]['account'] === 'cash' && str_starts_with((string) $ho[0]['ref'], 'SV-'), "$fk: " . mb_substr($ft, 0, 90));
    $r = $post(['agent_id' => $A, 'account_type' => 'cash', 'amount' => '5000', 'settle_ref' => 'RCPT-9']);
    [$fk, $ft] = flashOf($r['body']);
    check('more cash than the agent holds is refused', served($r) && $fk === 'bad' && str_contains($ft, 'more than') && count(ledgerRows($A, 'cash_handover')) === 1, "$fk: " . mb_substr($ft, 0, 90));
    $r = $post(['agent_id' => $boss, 'account_type' => 'cash', 'amount' => '10', 'settle_ref' => 'RCPT-10']);
    [$fk, $ft] = flashOf($r['body']);
    check('a non-agent account is refused', served($r) && $fk === 'bad' && str_contains($ft, 'Agent not found'), "$fk: " . mb_substr($ft, 0, 90));
    check('balances after the register: commission ₹700, cash ₹400', AgentWallet::balances($A)['commission'] === 700.0 && AgentWallet::balances($A)['cash'] === 400.0);
    check('every settlement post redirected back to the register (refresh-safe)', count($redirects) === 6 && !in_array(false, $redirects, true), json_encode($redirects));
    $again = http($jars['boss'], '/admin/agents.php');
    check('the flash is shown once, not again on the next visit', flashOf($again['body'])[0] === '');

    /* ---- 2. agent.php payout_request → AgentWallet::requestPayout ------------ */
    echo "\n== 2. agent.php payout request → agent_payout_requests ==\n";
    $r = signIn($jars['a'], AG_A);
    check('agent A signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php') && !str_contains($r['headers'], 'pin-setup'), 'HTTP ' . $r['code']);
    $pg = http($jars['a'], '/admin/agent.php');
    check('the agent panel offers "Request a payout"', served($pg) && str_contains($pg['body'], 'name="action" value="payout_request"'), 'HTTP ' . $pg['code']);
    $csrfA = csrfFrom($pg['body']);
    // The agent's own panel: every post comes back to /admin/agent.php (no ?agent=).
    $ask = static fn(array $f): array => postBack($jars['a'], '/admin/agent.php', [CSRF_TOKEN_NAME => $csrfA, 'action' => 'payout_request'] + $f, '/admin/agent.php');
    $r = $ask(['amount' => '100', 'note' => 'u11 f2 ask']);
    [$fk, $ft] = flashOf($r['body']);
    $reqs = Database::fetchAll('SELECT * FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $A]);
    check('the request is accepted', served($r) && $fk === 'ok' && str_contains($ft, 'sent to the office'), "$fk: " . mb_substr($ft, 0, 90));
    check('…and is an OPEN agent_payout_requests row (was only an audit row)', count($reqs) === 1 && $reqs[0]['status'] === 'open' && (float) $reqs[0]['amount'] === 100.0 && $reqs[0]['note'] === 'u11 f2 ask');
    $reqId = (int) ($reqs[0]['id'] ?? 0);
    $aud = Database::fetchAll("SELECT new_value FROM audit_logs WHERE action = 'agent.payout_request' AND entity_type = 'admin' AND entity_id = :a", ['a' => (string) $A]);
    check('exactly one audit row, carrying the request id', count($aud) === 1 && (int) ((json_decode((string) $aud[0]['new_value'], true) ?: [])['request_id'] ?? 0) === $reqId);
    check('the office WhatsApp "💸 Payout request" is kept', waCount(OFFICE_WA, '%Payout request%U11 F2 Agent A%') === 1);
    check('accounting / Agent 360 see it (AgentWallet::openPayoutRequests)', in_array($reqId, array_map('intval', array_column(AgentWallet::openPayoutRequests(), 'id')), true));
    check('the panel shows it as waiting', str_contains($r['body'], 'waiting for the office'));
    check('the office WhatsApp "Settle:" link opens the Wallet tab\'s Settle block (F2 refix)',
        waCount(OFFICE_WA, '%Settle: %/admin/agent.php?agent=' . $A . '&tab=wallet#settleBox%') === 1);
    $r = $ask(['amount' => '50']);
    [$fk, $ft] = flashOf($r['body']);
    check('a second request is refused while one is open', $fk === 'bad' && str_contains($ft, 'still with the office')
        && (int) Database::scalar('SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a', ['a' => $A], 0) === 1, mb_substr($ft, 0, 90));

    $pg = http($jars['boss'], '/admin/agent.php?agent=' . $A);
    $csrfB = csrfFrom($pg['body']);
    check('the office sees the pending request, pointed to Agent 360 → Requests', served($pg) && str_contains($pg['body'], 'Pending payout request') && str_contains($pg['body'], 'agent-360.php?agent=' . $A . '&amp;tab=requests'));
    check('…and the Settle payout box is pre-filled with the requested amount', str_contains($pg['body'], 'value="100.00"') && str_contains($pg['body'], 'paying at least that settles the request'));
    // The office posts from the Wallet tab (where the Settle block is): the redirect keeps the agent AND the tab.
    $settleBack = '/admin/agent.php?agent=' . $A . '&tab=wallet';
    $settle = static fn(array $f): array => postBack($jars['boss'], $settleBack, [CSRF_TOKEN_NAME => $csrfB, 'agent_id' => $A] + $f, $settleBack);
    $r = $settle(['action' => 'payout', 'amount' => '50', 'ref' => 'UTRF2A0002']);
    [$fk, $ft] = flashOf($r['body']);
    check('a payout smaller than the open ₹100 request (₹600 free besides it) is a separate payout - the request stays open',
        $fk === 'ok' && count(ledgerRows($A, 'payout')) === 2
        && (string) Database::scalar('SELECT status FROM agent_payout_requests WHERE id = :i', ['i' => $reqId], '') === 'open', "$fk: " . mb_substr($ft, 0, 90));

    AgentWallet::decidePayoutRequest($reqId, 'declined', $boss, 'u11 F2 decline');
    $pg = http($jars['a'], '/admin/agent.php');
    check('once declined the panel stops saying "waiting" and offers the form again (was audit-inferred, waiting forever)',
        served($pg) && !str_contains($pg['body'], 'waiting for the office') && str_contains($pg['body'], 'name="action" value="payout_request"'));
    $r = $ask(['amount' => '99999']);
    [$fk, $ft] = flashOf($r['body']);
    check('more than the commission due is refused (requestPayout checks)', $fk === 'bad' && str_contains($ft, 'You can request up to')
        && (int) Database::scalar("SELECT COUNT(*) FROM agent_payout_requests WHERE agent_admin_id = :a AND status = 'open'", ['a' => $A], 0) === 0, mb_substr($ft, 0, 90));

    /* ---- 3. agent.php settlement tells the agent (agent_notify_settlement) --- */
    echo "\n== 3. agent.php payout / cash handover WhatsApp the agent ==\n";
    $agentWa = '91' . PH_A;
    $r = $settle(['action' => 'payout', 'amount' => '50', 'ref' => 'UTRF2A0003']);
    [$fk, $ft] = flashOf($r['body']);
    check('payout recorded with its voucher', served($r) && $fk === 'ok' && str_contains($ft, 'Commission payout of') && str_contains($ft, 'UTRF2A0003'), "$fk: " . mb_substr($ft, 0, 120));
    check('switch ON: the agent gets the Agent 360 payout message', waCount($agentWa, '%₹50%paid out to you on%Voucher UTRF2A0003%Commission balance now%') === 1);
    check('…and the flash offers the click-to-chat send', str_contains($ft, 'https://wa.me/' . $agentWa));
    $r = $settle(['action' => 'cash_handover', 'amount' => '40', 'ref' => '']);
    [$fk, $ft] = flashOf($r['body']);
    check('cash handover recorded with an SV voucher', $fk === 'ok' && str_contains($ft, 'Cash handover of') && str_contains($ft, 'SV-'), mb_substr($ft, 0, 120));
    check('switch ON: the agent gets the cash-handover receipt message', waCount($agentWa, '%cash handover of ₹40 received from you%Receipt SV-%Cash in hand now%') === 1);
    // F2 refix: the handover came back as a redirect, so a browser refresh re-loads the page - it never re-posts.
    $hoN = count(ledgerRows($A, 'cash_handover'));
    $waN = waCount($agentWa, '%');
    $re  = http($jars['boss'], $settleBack);
    check('a refresh after the handover records nothing again and sends no second WhatsApp (redirect-after-post)',
        served($re) && flashOf($re['body'])[0] === '' && count(ledgerRows($A, 'cash_handover')) === $hoN && waCount($agentWa, '%') === $waN);
    $r = $ask(['amount' => '30']);
    $req2 = (int) Database::scalar("SELECT id FROM agent_payout_requests WHERE agent_admin_id = :a AND status = 'open'", ['a' => $A], 0);
    $r = $settle(['action' => 'payout', 'amount' => '30', 'ref' => 'UTRF2A0004']);
    [$fk, $ft] = flashOf($r['body']);
    $p30 = Database::fetch("SELECT id FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'payout' AND ref = 'UTRF2A0004'", ['a' => $A]);
    $rq2 = Database::fetch('SELECT status, ledger_id FROM agent_payout_requests WHERE id = :i', ['i' => $req2]);
    check('a payout here that covers the open request settles it (paid, tied to that payout - as Agent 360 does)',
        $req2 > 0 && $fk === 'ok' && $p30 !== null && $rq2 !== null && $rq2['status'] === 'paid' && (int) $rq2['ledger_id'] === (int) $p30['id'], json_encode($rq2) . ' ' . mb_substr($ft, 0, 80));
    $pg = http($jars['a'], '/admin/agent.php');
    check('…so the agent is no longer "waiting"', served($pg) && !str_contains($pg['body'], 'waiting for the office'));

    /* F2 refix (finding 2): the agent's form pre-fills the WHOLE commission, so the
       usual request reserves every rupee due. A payout naming nothing then failed
       "Funds are reserved" on this Settle block - the page the office's WhatsApp
       "Settle:" link opens. */
    $dueNow = AgentWallet::balances($A)['commission'];
    $r = $ask(['amount' => '']);   // blank = the whole balance
    $reqAll = Database::fetch("SELECT id, amount FROM agent_payout_requests WHERE agent_admin_id = :a AND status = 'open'", ['a' => $A]);
    check('the agent asks for the whole balance', $dueNow > 0 && $reqAll !== null && abs((float) $reqAll['amount'] - $dueNow) < 0.001, json_encode($reqAll) . ' due ' . $dueNow);
    $nPay = count(ledgerRows($A, 'payout'));
    $r = $settle(['action' => 'payout', 'amount' => '300', 'ref' => 'UTRF2A0005']);
    [$fk, $ft] = flashOf($r['body']);
    check('a payout below the whole-balance request is refused, saying the money is held for it (and how to pay it here)',
        $fk === 'bad' && str_contains($ft, 'held for this agent’s open payout request') && str_contains($ft, 'pay at least') && count(ledgerRows($A, 'payout')) === $nPay, mb_substr($ft, 0, 140));
    $r = $settle(['action' => 'payout', 'amount' => number_format($dueNow, 2, '.', ''), 'ref' => 'UTRF2A0006']);
    [$fk, $ft] = flashOf($r['body']);
    $pAll = Database::fetch("SELECT id, amount FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'payout' AND ref = 'UTRF2A0006'", ['a' => $A]);
    $rqAll = Database::fetch('SELECT status, ledger_id FROM agent_payout_requests WHERE id = :i', ['i' => (int) ($reqAll['id'] ?? 0)]);
    check('the whole-balance payout on the Settle block is accepted (was "Funds are reserved for an open withdrawal")',
        $fk === 'ok' && str_contains($ft, 'Commission payout of') && $pAll !== null && abs((float) $pAll['amount'] + $dueNow) < 0.001, "$fk: " . mb_substr($ft, 0, 120));
    check('…and settles the request in the same step (paid, tied to that payout)',
        $rqAll !== null && $rqAll['status'] === 'paid' && (int) $rqAll['ledger_id'] === (int) ($pAll['id'] ?? -1) && abs(AgentWallet::balances($A)['commission']) < 0.001, json_encode($rqAll));

    $sw('agent_notify_settlement', '0');
    $before = waCount($agentWa, '%');
    $r = $settle(['action' => 'cash_handover', 'amount' => '10', 'ref' => 'RCPT-F2-11']);
    [$fk, $ft] = flashOf($r['body']);
    check('switch OFF: still recorded, nothing sent, no send link', $fk === 'ok' && waCount($agentWa, '%') === $before && !str_contains($ft, 'wa.me'), mb_substr($ft, 0, 120));
    $sw('agent_notify_settlement', '1');

    /* ---- 4. agent.php advance → loans register ------------------------------ */
    echo "\n== 4. agent.php advance → issueLoan / repayLoan ==\n";
    $pg = http($jars['boss'], '/admin/agent.php?agent=' . $A);
    check('the Settle block gives an advance through the register (loan_issue), no bare advance form',
        served($pg) && str_contains($pg['body'], 'name="action" value="loan_issue"') && !str_contains($pg['body'], 'name="action" value="advance"'));
    check('no repayment form while the agent has no open advance', !str_contains($pg['body'], 'name="action" value="loan_repay"'));
    $commBefore = AgentWallet::balances($A)['commission'];
    $r = $settle(['action' => 'loan_issue', 'amount' => '500', 'ref' => 'V-F2-1']);
    [$fk, $ft] = flashOf($r['body']);
    $loans = AgentWallet::loans($A);
    $l1 = $loans[0] ?? [];
    check('advance recorded', $fk === 'ok' && str_contains($ft, 'Advance #'), "$fk: " . mb_substr($ft, 0, 120));
    check('…on the loans register: advance, ₹500, recovery full, open', count($loans) === 1 && $l1['kind'] === 'advance' && (float) $l1['principal'] === 500.0
        && $l1['recover_mode'] === 'full' && $l1['status'] === 'open');
    $adv1 = Database::fetch("SELECT * FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'adjustment' AND ref = :r", ['a' => $A, 'r' => 'ADVANCE L' . (int) ($l1['id'] ?? 0)]);
    check('…the same money as the bare advance: −₹500 on commission, ref ADVANCE L<id>, the typed ref kept in the note',
        $adv1 !== null && (float) $adv1['amount'] === -500.0 && $adv1['account'] === 'commission' && str_contains((string) $adv1['note'], 'ref V-F2-1')
        && abs(AgentWallet::balances($A)['commission'] - ($commBefore - 500.0)) < 0.001);
    check('advanceSummary still counts it (ADVANCE prefix)', AgentWallet::advanceSummary($A)['given'] === 500.0);

    $r = $settle(['action' => 'advance', 'advance_kind' => 'given', 'amount' => '200', 'ref' => '']);
    [$fk, $ft] = flashOf($r['body']);
    check('a post from an old open panel (action=advance) also goes through the register', $fk === 'ok' && count(AgentWallet::loans($A)) === 2, "$fk: " . mb_substr($ft, 0, 120));
    check('no bare advance ever reached the ledger (every ADVANCE ref is ADVANCE L<id>)',
        (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND ref LIKE 'ADVANCE%' AND ref NOT LIKE 'ADVANCE L%'", ['a' => $A], 0) === 0);

    $pg = http($jars['boss'], '/admin/agent.php?agent=' . $A);
    check('with open advances the repayment form lists them', str_contains($pg['body'], 'name="action" value="loan_repay"') && str_contains($pg['body'], 'value="' . (int) $l1['id'] . '"'));
    $ledgerCount = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $A], 0);
    $n = $ledgerCount();
    $r = $settle(['action' => 'loan_repay', 'loan_id' => $loanB, 'amount' => '50']);
    [$fk, $ft] = flashOf($r['body']);
    check("another agent's advance is refused (the loan must be this agent's)", $fk === 'bad' && $ledgerCount() === $n
        && (float) (AgentWallet::loan($loanB)['recovered'] ?? -1) === 0.0, mb_substr($ft, 0, 120));
    $r = $settle(['action' => 'loan_repay', 'loan_id' => (int) $l1['id'], 'amount' => '9999']);
    [$fk, $ft] = flashOf($r['body']);
    check('more than is still out on the advance is refused', $fk === 'bad' && str_contains($ft, 'more than') && $ledgerCount() === $n, mb_substr($ft, 0, 120));
    $r = $settle(['action' => 'loan_repay', 'loan_id' => (int) $l1['id'], 'amount' => '100', 'ref' => 'RCPT-F2-12']);
    [$fk, $ft] = flashOf($r['body']);
    $l1b = AgentWallet::loan((int) $l1['id']);
    $rep = Database::fetch("SELECT * FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'adjustment' AND ref = :r AND amount > 0", ['a' => $A, 'r' => 'ADVANCE L' . (int) $l1['id']]);
    check('a cash repayment is recorded on that advance', $fk === 'ok' && $l1b !== null && (float) $l1b['recovered'] === 100.0 && $rep !== null && (float) $rep['amount'] === 100.0, "$fk: " . mb_substr($ft, 0, 120));
    $r = $settle(['action' => 'advance', 'advance_kind' => 'repaid', 'amount' => '20']);
    [$fk, $ft] = flashOf($r['body']);
    check('an old-panel "repaid" without an advance picked is refused, not written bare', $fk === 'bad' && str_contains($ft, 'Choose which'), mb_substr($ft, 0, 120));

    /* ---- 4b. F2 refix: an advance from BEFORE the register ------------------ */
    echo "\n== 4b. agent.php repays an advance given before the loans register ==\n";
    // C earned ₹1,000 (last year) and got a ₹3,000 advance from the OLD Settle block - a bare
    // recordAdvance() debit, ref 'ADVANCE V-OLD-1', no agent_loans row - so ₹2,000 is still out.
    $C = $mkAdmin(AG_C, 'U11 F2 Agent C', 'agent', PH_C);
    AgentWallet::record($C, 'commission', 1000.0, ['note' => 'u11 F2 seed C', 'by' => 0]);
    Database::run("UPDATE agent_ledger SET created_at = '2025-11-01 10:00:00' WHERE agent_admin_id = :a", ['a' => $C]);
    AgentWallet::recordAdvance($C, 3000.0, ['note' => 'u11 F2 old-panel advance', 'ref' => 'V-OLD-1', 'by' => $boss]);
    $cLoan = AgentWallet::issueLoan($C, 'advance', 500.0, 'full', 0.0, 'u11 F2 C register advance', $boss);   // + ₹500 on the register
    check('fixtures: C owes ₹2,500 on the ledger - ₹2,000 pre-register + ₹500 on Advance #' . $cLoan,
        AgentWallet::advanceSummary($C)['outstanding'] === 2500.0 && AgentWallet::loans($C, 'open') !== []);
    $cBack   = '/admin/agent.php?agent=' . $C . '&tab=wallet';
    $pg      = http($jars['boss'], $cBack);
    $csrfC   = csrfFrom($pg['body']);
    $settleC = static fn(array $f): array => postBack($jars['boss'], $cBack, [CSRF_TOKEN_NAME => $csrfC, 'agent_id' => $C] + $f, $cBack);
    check('the repayment form offers the pre-register advance with what is still out on it (₹2,000 - not the ₹2,500 ledger figure)',
        served($pg) && str_contains($pg['body'], 'name="action" value="loan_repay"') && str_contains($pg['body'], '<option value="pre">')
        && str_contains($pg['body'], 'Advance from before the register · ' . inr(2000.0) . ' out') && str_contains($pg['body'], 'value="' . $cLoan . '"'));
    $preRows = static fn(): array => Database::fetchAll("SELECT * FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'adjustment' AND ref = 'ADVANCE' AND amount > 0 ORDER BY id", ['a' => $C]);
    $r = $settleC(['action' => 'loan_repay', 'loan_id' => 'pre', 'amount' => '2500', 'ref' => 'RCPT-OLD-0']);
    [$fk, $ft] = flashOf($r['body']);
    check('more than the ₹2,000 still out on it is refused (capped - the old "repaid" had no cap)', $fk === 'bad' && str_contains($ft, 'more than the ' . inr(2000.0)) && $preRows() === [], mb_substr($ft, 0, 120));
    $r = $settleC(['action' => 'loan_repay', 'loan_id' => 'pre', 'amount' => '1500', 'ref' => 'RCPT-OLD-1']);
    [$fk, $ft] = flashOf($r['body']);
    $pr = $preRows();
    check('₹1,500 cash back on the pre-register advance is recorded', $fk === 'ok' && str_contains($ft, 'before the register'), "$fk: " . mb_substr($ft, 0, 120));
    check('…as the bare ADVANCE credit the old form wrote (+₹1,500 on commission, by the office, receipt no. in the note)',
        count($pr) === 1 && (float) $pr[0]['amount'] === 1500.0 && $pr[0]['account'] === 'commission' && (int) $pr[0]['created_by'] === $boss
        && str_contains((string) $pr[0]['note'], 'ref RCPT-OLD-1'));
    check('…counted as an advance repayment (advanceSummary repaid), not a generic credit; the register row is untouched',
        AgentWallet::advanceSummary($C)['repaid'] === 1500.0 && AgentWallet::advanceSummary($C)['outstanding'] === 1000.0
        && (float) (AgentWallet::loan($cLoan)['recovered'] ?? -1) === 0.0);
    $r = $settleC(['action' => 'advance', 'advance_kind' => 'repaid', 'amount' => '600']);
    [$fk, $ft] = flashOf($r['body']);
    check('an old panel\'s "repaid" (no loan_id) reaches the pre-register advance too - still capped (₹600 > ₹500 left)', $fk === 'bad' && str_contains($ft, 'more than the ' . inr(500.0)), mb_substr($ft, 0, 120));
    $r = $settleC(['action' => 'advance', 'advance_kind' => 'repaid', 'amount' => '500']);
    [$fk, $ft] = flashOf($r['body']);
    check('…and records the last ₹500', $fk === 'ok' && count($preRows()) === 2 && AgentWallet::advanceSummary($C)['outstanding'] === 500.0, "$fk: " . mb_substr($ft, 0, 120));
    $pg = http($jars['boss'], $cBack);
    check('once it is all back, only the register advance is offered', str_contains($pg['body'], 'value="' . $cLoan . '"') && !str_contains($pg['body'], '<option value="pre">'));
    $r = $settleC(['action' => 'loan_repay', 'loan_id' => 'pre', 'amount' => '10']);
    [$fk, $ft] = flashOf($r['body']);
    check('a stale "pre" pick is refused once nothing is out on it', $fk === 'bad' && str_contains($ft, 'Nothing is still out') && count($preRows()) === 2, mb_substr($ft, 0, 120));
    // B's ledger is ₹300 further below zero from a commission correction, not an advance: nothing pre-register to offer.
    AgentWallet::record($B, 'adjustment', 300.0, ['direction' => 'debit', 'ref' => 'ADJ commission', 'note' => 'u11 F2 correction', 'by' => $boss]);
    $pg = http($jars['boss'], '/admin/agent.php?agent=' . $B . '&tab=wallet');
    check('a balance pushed negative by something else is never offered as a pre-register advance',
        AgentWallet::advanceSummary($B)['outstanding'] === 400.0 && str_contains($pg['body'], 'value="' . $loanB . '"') && !str_contains($pg['body'], '<option value="pre">'));

    /* ---- 4c. F2 refix: redirect after every post ----------------------------- */
    check('every agent.php post redirected back to the same agent and tab (a refresh cannot post it again)',
        count($AG_REDIRECTS) >= 20 && !in_array(false, $AG_REDIRECTS, true), count($AG_REDIRECTS) . ' posts: ' . json_encode($AG_REDIRECTS));
    $p = http($jars['boss'], '/admin/agent.php?agent=' . $C . '&tab=wallet', ['form' => [CSRF_TOKEN_NAME => 'stale-token', 'agent_id' => $C, 'action' => 'cash_handover', 'amount' => '1']]);
    check('…a refused post (expired session) redirects too, its flash shown once',
        $p['code'] === 302 && flashOf(http($jars['boss'], $cBack)['body'])[0] === 'bad' && flashOf(http($jars['boss'], $cBack)['body'])[0] === '');

    /* ---- 5. wallet history links the printable receipt ---------------------- */
    echo "\n== 5. wallet history → agent-receipt.php ==\n";
    $pg = http($jars['a'], '/admin/agent.php?tab=wallet');
    check("the agent's own payout row links its receipt", served($pg) && str_contains($pg['body'], 'agent-receipt.php?id=' . $payoutLedgerId . '"'));
    $rc = http($jars['a'], '/admin/agent-receipt.php?id=' . $payoutLedgerId);
    check('…and the agent can open it', $rc['code'] === 200 && str_contains($rc['body'], 'Commission payout voucher') && str_contains($rc['body'], 'UTRF2A0001'), 'HTTP ' . $rc['code']);
    $r = signIn($jars['b'], AG_B);
    $rcB = http($jars['b'], '/admin/agent-receipt.php?id=' . $payoutLedgerId);
    check("another agent cannot open it (403)", $rcB['code'] === 403, 'HTTP ' . $rcB['code']);

    /* ---- 5b. Agent 360: an open request for the whole balance can be paid (mv5) ---- */
    echo "\n== 5b. Agent 360 → Requests 'Paid' and a payout that covers the open request ==\n";
    /* Before mv5, AgentWallet::record() reserved the very request being paid, so a request
       above half the balance (the usual whole-balance ask) was refused "Funds are reserved". */
    $D = $mkAdmin(AG_D, 'U11 F2 Agent D', 'agent', PH_D);
    AgentWallet::record($D, 'commission', 700.0, ['note' => 'u11 F2 D seed', 'by' => 0]);
    Database::run("UPDATE agent_ledger SET created_at = '2025-11-01 10:00:00' WHERE agent_admin_id = :a", ['a' => $D]);
    $reqD = (int) Database::insert('agent_payout_requests', ['agent_admin_id' => $D, 'amount' => 700.0, 'note' => 'u11 whole balance', 'status' => 'open']);
    $a3 = http($jars['boss'], '/admin/agent-360.php?agent=' . $D . '&tab=requests');
    check('Agent 360 Requests renders the open whole-balance request', served($a3) && str_contains($a3['body'], 'value="request_paid"'), 'HTTP ' . $a3['code']);
    $c3 = csrfFrom($a3['body']);
    $p3 = http($jars['boss'], '/admin/agent-360.php?agent=' . $D . '&tab=requests', ['form' => [CSRF_TOKEN_NAME => $c3, 'agent_id' => $D, 'tab' => 'requests',
        'action' => 'request_paid', 'request_id' => $reqD, 'ref' => 'UTRF2D0001']]);
    $st = (string) Database::scalar('SELECT status FROM agent_payout_requests WHERE id = :i', ['i' => $reqD], '');
    $pd = ledgerRows($D, 'payout');
    check("'Paid' on a ₹700 request with ₹700 due records the payout (was 'Funds are reserved')",
        count($pd) === 1 && (float) $pd[0]['amount'] === -700.0 && $pd[0]['ref'] === 'UTRF2D0001', 'HTTP ' . $p3['code'] . ' rows ' . count($pd));
    check('…the request is marked paid against that payout', $st === 'paid'
        && (int) Database::scalar('SELECT ledger_id FROM agent_payout_requests WHERE id = :i', ['i' => $reqD], 0) === (int) ($pd[0]['id'] ?? -1));
    check('…and the balance is ₹0', AgentWallet::balances($D)['commission'] === 0.0);

    // a Settle payout (tab Settlements) that covers a fresh open request settles it in the same step
    AgentWallet::record($D, 'commission', 500.0, ['note' => 'u11 F2 D seed 2', 'by' => 0]);
    $reqD2 = (int) Database::insert('agent_payout_requests', ['agent_admin_id' => $D, 'amount' => 500.0, 'note' => 'u11 second', 'status' => 'open']);
    $a3 = http($jars['boss'], '/admin/agent-360.php?agent=' . $D . '&tab=settlements');
    $c3 = csrfFrom($a3['body']);
    $p3 = http($jars['boss'], '/admin/agent-360.php?agent=' . $D . '&tab=settlements', ['form' => [CSRF_TOKEN_NAME => $c3, 'agent_id' => $D, 'tab' => 'settlements',
        'action' => 'payout', 'amount' => '500', 'ref' => 'UTRF2D0002', 'note' => 'u11 settle']]);
    $st2 = (string) Database::scalar('SELECT status FROM agent_payout_requests WHERE id = :i', ['i' => $reqD2], '');
    check('a ₹500 Settle payout with a ₹500 open request is recorded and settles the request',
        count(ledgerRows($D, 'payout')) === 2 && $st2 === 'paid' && AgentWallet::balances($D)['commission'] === 0.0, 'HTTP ' . $p3['code'] . ' status ' . $st2);
    check('…a payout that does not cover the request still refuses past the held amount (₹100 due, ₹100 held)',
        (static function () use ($D): bool {
            AgentWallet::record($D, 'commission', 100.0, ['note' => 'u11 F2 D seed 3', 'by' => 0]);
            Database::insert('agent_payout_requests', ['agent_admin_id' => $D, 'amount' => 100.0, 'note' => 'u11 third', 'status' => 'open']);
            try { AgentWallet::record($D, 'payout', 50.0, ['ref' => 'UTRF2D0003', 'by' => 0]); return false; }
            catch (RuntimeException $e) { return str_contains($e->getMessage(), 'reserved'); }
        })());

    /* ---- 6. source guards ---------------------------------------------------- */
    echo "\n== 6. source guards ==\n";
    $ag  = (string) file_get_contents(dirname(__DIR__) . '/admin/agent.php');
    $ags = (string) file_get_contents(dirname(__DIR__) . '/admin/agents.php');
    check('agent.php gives no bare advance: its one recordAdvance() is the capped pre-register repayment (a credit)',
        substr_count($ag, 'AgentWallet::recordAdvance(') === 1 && str_contains($ag, 'AgentWallet::recordAdvance($targetId, -$advAmt, [')
        && str_contains($ag, "'ref'  => '',   // bare 'ADVANCE'"));
    check('agent.php redirects after the post (Response::redirect, flash in the session)', str_contains($ag, "\$_SESSION['shg_agent_flash'] = \$flash;") && str_contains($ag, "Response::redirect('admin/agent.php'"));
    check('the Settle payout names the request it covers (request_id) and settles it in the same transaction',
        str_contains($ag, "\$setMeta + ['request_id' => (int) \$oreq['id']]") && str_contains($ag, "AgentWallet::decidePayoutRequest((int) \$oreq['id'], 'paid'"));
    check('agent.php no longer infers a pending request from audit rows', !str_contains($ag, "WHERE action = 'agent.payout_request'"));
    check('agents.php stamps no SETTL- reference and settles through AgentWallet::record', !str_contains($ags, "'SETTL-'") && str_contains($ags, 'AgentWallet::record($agentId, $entry, $amount,'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception — ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
    $restore();
    foreach ($jars as $j) { @unlink($j); }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
