<?php
/**
 * =====================================================================
 *  op5-credit-test.php — agent credit limit + "Stop selling" hold (25 Nov 2026).
 *
 *  A throw-away agent (and a counter clerk) is created, signed in by hand
 *  ($_SESSION, the way tests/one-door-test.php does), given cash dues through
 *  AgentWallet::record(), and sent at the real sale doors:
 *
 *   A pure rule (AgentCredit::decide): below / at / over the limit, hold, switch off
 *   B no limit anywhere                      → never refused, however much is owed
 *   C below the limit                        → assertMaySell passes, create() goes past the guard
 *   D at / over the limit                    → refused with the Nepali ₹X (सीमा ₹Y) message on
 *                                              assertMaySell, create() seller (SmartBook / Quick
 *                                              ticket / bulk / WA desk) and counterSale() (register)
 *   E company default limit applies when the agent has none; own 0 = no limit
 *   F settlement entry (cash_handover)       → lowers outstanding, unblocks, shows on the ledger
 *   G "Stop selling" hold                    → refused; resume → allowed; "Application rejected" is no hold
 *   H a counter clerk is never refused; the switch off lets everything through
 *   I 80% banner on the agent desk; Agent 360 + settings + migration are wired
 *
 *    php -c .claude/php-dev.ini tests/op5-credit-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/agentcredit.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** The message a callable throws, or '' when it does not throw. */
function thrown(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
}
function isCredit(string $msg): bool {
    return str_contains($msg, 'पहिले अफिसमा हिसाब मिलाउनुहोस्');
}

/* ---- settings this suite touches: only its own keys, restored at the end ---- */
$keys = ['agent_credit_block_on' => 'bool', 'agent_credit_limit_default' => 'float', 'agent_credit_warn_pct' => 'int'];
$saved = [];
foreach ($keys as $sk => $_) {
    $saved[$sk] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $sk]);
}
$set = static function (string $k, string $v) use ($keys): void { Settings::set($k, $v, $keys[$k], 'agent'); };

$tag  = substr(bin2hex(random_bytes(4)), 0, 8);
$made = [];
$cleanup = static function () use (&$made, $saved, $keys): void {
    foreach ($made as $id) {
        try {
            Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]);
            Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]);
            Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :a AND action LIKE 'agent.%'", ['a' => (string) $id]);
            Database::run('DELETE FROM admins WHERE id = :a', ['a' => $id]);
        } catch (Throwable $e) {
            echo "  (cleanup) " . $e->getMessage() . "\n";
        }
    }
    $made = [];
    foreach ($saved as $sk => $row) {
        try {
            if ($row === null) {
                Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $sk]);
            } else {
                Settings::set($sk, (string) $row['svalue'], (string) $row['stype'], 'agent');
            }
        } catch (Throwable $e) {
            echo "  (cleanup) " . $e->getMessage() . "\n";
        }
    }
};
register_shutdown_function($cleanup);

$mk = static function (string $role) use ($tag, &$made): int {
    $id = (int) Database::insert('admins', ['username' => 'op5c-' . $role . '-' . $tag, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'OP5 Credit ' . ucfirst($role), 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $made[] = $id;
    return $id;
};
$signIn = static function (int $id): void {
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
};
$signOut = static function (): void { unset($_SESSION[ADMIN_SESSION_KEY]); };

try {
    /* ---------------- A pure rule ---------------- */
    echo "\n-- A rule\n";
    check('A below the limit sells', AgentCredit::decide(4000, 5000, false) === null);
    $m = (string) AgentCredit::decide(5000, 5000, false);
    check('A at the limit refused, Nepali message with ₹X and सीमा ₹Y',
        str_contains($m, 'तपाईंको बाँकी हिसाब ₹5,000 (सीमा ₹5,000)') && isCredit($m), $m);
    check('A over the limit refused', AgentCredit::decide(7200, 5000, false) !== null);
    check('A limit 0 = no limit', AgentCredit::decide(999999, 0, false) === null);
    check('A hold refuses even with nothing owed', isCredit((string) AgentCredit::decide(0, 0, true, 'dues')));
    check('A switch off lets everything through', AgentCredit::decide(9000, 5000, true, 'x', false) === null);

    $set('agent_credit_block_on', '1');
    $set('agent_credit_limit_default', '0');
    $set('agent_credit_warn_pct', '80');

    $agent   = $mk('agent');
    $counter = $mk('counter');
    $route   = Database::fetch('SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    $sched   = $route ? Database::fetch('SELECT id, travel_date FROM schedules WHERE route_id = :r ORDER BY travel_date DESC LIMIT 1', ['r' => (int) $route['id']]) : null;
    check('setup: an active route exists in shari_test', $route !== null);
    $rid = (int) ($route['id'] ?? 0);

    $owe = static function (float $amt) use ($agent): void {
        AgentWallet::record($agent, 'cash_due', $amt, ['note' => 'op5-credit test dues', 'by' => 0]);
    };
    $saleReq = static function () use ($rid): array {
        return [
            'routeId' => $rid, 'travelDate' => todayISO(), 'seats' => ['ZZ99'],
            'passengers' => [['seat' => 'ZZ99', 'name' => 'Op Five', 'gender' => 'Male', 'age' => 30]],
            'contact' => ['name' => 'Op Five', 'phone' => '9000017799'],
        ];
    };
    $sellerCtx = static fn(int $id): array => ['adminId' => $id, 'source' => 'agent', 'paymentMethod' => 'cash'];
    $doors = [
        'assertMaySell'           => static fn() => AgentWallet::assertMaySell($agent, $rid),
        'create() seller (SmartBook / Quick / bulk / WA desk)' => static fn() => BookingService::create($saleReq(), $sellerCtx($agent)),
        'counterSale() (register / paper ticket)' => static fn() => BookingService::counterSale($route, (int) ($sched['id'] ?? 0), todayISO(), ['ZZ99'],
            ['name' => 'Op Five', 'phone' => '9000017799', 'paymentMethod' => 'cash'], $agent, 'agent'),
    ];

    /* ---------------- B no limit ---------------- */
    echo "\n-- B no limit\n";
    $signIn($agent);
    $owe(25000);
    check('B no limit anywhere: owes ₹25,000, not refused', AgentCredit::refusal($agent) === null);
    check('B assertMaySell passes', thrown($doors['assertMaySell']) === '');
    check('B no limit: no banner', AgentCredit::bannerHtml($agent) === '');

    /* ---------------- C below the limit ---------------- */
    echo "\n-- C below the limit\n";
    AgentCredit::setLimit($agent, 40000, 0);
    check('C own limit stored', AgentCredit::ownLimit($agent) === 40000.0 && AgentCredit::limitFor($agent) === 40000.0);
    check('C outstanding = net position (₹25,000)', abs(AgentCredit::outstanding($agent) - 25000) < 0.01, (string) AgentCredit::outstanding($agent));
    check('C assertMaySell passes below the limit', thrown($doors['assertMaySell']) === '');
    $msg = thrown($doors['create() seller (SmartBook / Quick / bulk / WA desk)']);
    check('C create() goes past the credit guard (stops later on the fake seat)', !isCredit($msg), $msg);
    check('C 62% used: no banner yet', AgentCredit::bannerHtml($agent) === '');

    /* ---------------- I 80% banner ---------------- */
    $owe(8000);   // 33,000 of 40,000 = 82%
    $b = AgentCredit::bannerHtml($agent);
    check('I 82% used: warning banner on the desk', str_contains($b, 'data-credit="warn"') && str_contains($b, '82%'), $b);

    /* ---------------- D at / over the limit, every door ---------------- */
    echo "\n-- D at / over the limit\n";
    $owe(7000);   // 40,000 = at the limit
    foreach ($doors as $door => $fn) {
        $m = thrown($fn);
        check('D at the limit refused: ' . $door, isCredit($m) && str_contains($m, '₹40,000 (सीमा ₹40,000)'), $m);
    }
    check('D blocked banner on the desk', str_contains(AgentCredit::bannerHtml($agent), 'data-credit="blocked"'));
    $st = AgentCredit::status($agent);
    check('D status: blocked, 100%', $st['blocked'] && $st['pct'] === 100 && !$st['held']);
    // the agent session's own pay-at-boarding through the public flow is refused too
    check('D agent COD credit sale refused (create guard)', str_contains((string) file_get_contents(INCLUDE_PATH . '/booking.php'), "if (\$isCod && Auth::isCounterAgent()) {")
        && isCredit(thrown(static fn() => AgentCredit::assertRoom($agent))));

    /* ---------------- H counter + switch ---------------- */
    echo "\n-- H never the counter\n";
    $signIn($counter);
    check('H a counter clerk session selling under the agent passes assertMaySell', thrown(static fn() => AgentWallet::assertMaySell($agent, $rid)) === '');
    check('H a counter clerk own sale passes', thrown(static fn() => AgentWallet::assertMaySell($counter, $rid)) === '');
    $signOut();
    check('H a customer (no session, CLI) is never bound', thrown(static fn() => AgentWallet::assertMaySell($agent, $rid)) === '');
    $signIn($agent);
    $set('agent_credit_block_on', '0');
    check('H switch off: the agent at the limit sells', thrown($doors['assertMaySell']) === '' && AgentCredit::bannerHtml($agent) === '');
    $set('agent_credit_block_on', '1');

    /* ---------------- F settlement entry ---------------- */
    echo "\n-- F settlement received\n";
    $before = (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'cash_handover'", ['a' => $agent], 0);
    check('F bank settlement without a reference is refused', str_contains(thrown(static fn() => AgentCredit::recordSettlement($agent, 1000, 'bank', '', '', todayISO(), 0)), 'reference'));
    check('F a future date is refused', thrown(static fn() => AgentCredit::recordSettlement($agent, 1000, 'cash', '', '', date('Y-m-d', strtotime('+2 days')), 0)) !== '');
    check('F more than the cash held is refused by the ledger', thrown(static fn() => AgentCredit::recordSettlement($agent, 99999, 'cash', '', '', todayISO(), 0)) !== '');
    $lid = AgentCredit::recordSettlement($agent, 15000, 'online', 'UTR998877', 'op5 test', date('Y-m-d', strtotime('-1 day')), 0);
    $row = Database::fetch('SELECT * FROM agent_ledger WHERE id = :i', ['i' => $lid]);
    check('F written as a cash_handover (statement + aging read it), voucher kept', $row !== null && $row['entry_type'] === 'cash_handover'
        && (float) $row['amount'] === -15000.0 && (string) $row['ref'] === 'UTR998877' && str_contains((string) $row['note'], 'online'), json_encode($row));
    check('F one handover row added', (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'cash_handover'", ['a' => $agent], 0) === $before + 1);
    check('F outstanding lowered to ₹25,000', abs(AgentCredit::outstanding($agent) - 25000) < 0.01);
    check('F unblocked: every door passes the credit guard again', !isCredit(thrown($doors['assertMaySell'])) && !isCredit(thrown($doors['create() seller (SmartBook / Quick / bulk / WA desk)'])));
    check('F audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agent.credit_settlement' AND entity_id = :a", ['a' => (string) $agent]));
    check('F nothing marked paid: no payout row', !Database::exists("SELECT 1 FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'payout'", ['a' => $agent]));

    /* ---------------- G hold ---------------- */
    echo "\n-- G stop selling / resume\n";
    check('G a hold needs a reason', thrown(static fn() => AgentCredit::setHold($agent, true, '', 0)) !== '');
    AgentCredit::setHold($agent, true, 'dues not settled', 0);
    $m = thrown($doors['assertMaySell']);
    check('G on hold: refused below the limit, reason shown', isCredit($m) && str_contains($m, 'dues not settled'), $m);
    check('G on hold: create() refused', isCredit(thrown($doors['create() seller (SmartBook / Quick / bulk / WA desk)'])));
    check('G hold audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agent.credit_hold' AND entity_id = :a", ['a' => (string) $agent]));
    check('G the account stays active (sign-in, statement, handover still work)', (int) Database::scalar('SELECT is_active FROM admins WHERE id = :a', ['a' => $agent], 0) === 1);
    AgentCredit::setHold($agent, false, 'dues cleared', 0);
    check('G resume: allowed again', thrown($doors['assertMaySell']) === '' && !AgentCredit::hold($agent)['held']);
    check('G release audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agent.credit_release' AND entity_id = :a", ['a' => (string) $agent]));
    AgentWallet::saveProfile($agent, ['suspended_reason' => 'Application rejected', 'suspended_at' => date('Y-m-d H:i:s')]);
    check('G "Application rejected" (staff.php) is not a selling hold', !AgentCredit::hold($agent)['held'] && thrown($doors['assertMaySell']) === '');
    AgentWallet::saveProfile($agent, ['suspended_reason' => '', 'suspended_at' => null]);

    /* ---------------- E company default ---------------- */
    echo "\n-- E company default\n";
    AgentCredit::setLimit($agent, null, 0);
    check('E no own limit + default 0 → no limit', AgentCredit::ownLimit($agent) === null && AgentCredit::refusal($agent) === null);
    $set('agent_credit_limit_default', '20000');
    check('E default ₹20,000 applies (owes ₹25,000) → refused', isCredit(thrown($doors['assertMaySell'])));
    AgentCredit::setLimit($agent, 0, 0);
    check('E own limit 0 = no limit even with a default', AgentCredit::ownLimit($agent) === 0.0 && thrown($doors['assertMaySell']) === '');
    check('E limit change audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agent.credit_limit' AND entity_id = :a", ['a' => (string) $agent]));
    $set('agent_credit_limit_default', '0');

    /* ---------------- I wiring ---------------- */
    echo "\n-- I wiring\n";
    $a360 = (string) file_get_contents($ROOT . '/admin/agent-360.php');
    check('I Agent 360: limit / hold / resume / settlement actions behind settler permission + CSRF form head',
        str_contains($a360, "['credit_limit', 'credit_hold', 'credit_release', 'credit_settle']") && str_contains($a360, "a360_form_head('overview', 'credit_settle'")
        && preg_match("/credit_settle'\\], true\\)\\) \\{.*?\\\$needPay\\(\\);\\s*\\\$notSelf\\(\\);/s", $a360) === 1);
    check('I Agent 360: outstanding vs limit bar', str_contains($a360, 'role="progressbar"') && str_contains($a360, 'हिसाब क्लियर · Settlement received'));
    check('I agents list: On hold pill', str_contains((string) file_get_contents($ROOT . '/admin/agents.php'), 'On hold · रोकिएको'));
    check('I agent desk banner in the staff head', str_contains((string) file_get_contents($ROOT . '/admin/_guard.php'), 'AgentCredit::bannerHtml('));
    $sp = (string) file_get_contents($ROOT . '/admin/settings.php');
    check('I settings: the three keys on the Agent rules panel', str_contains($sp, "\$arBool('agent_credit_block_on'") && str_contains($sp, "\$arNum('agent_credit_limit_default'") && str_contains($sp, "\$arNum('agent_credit_warn_pct'"));
    check('I not in the boot settings list', !str_contains((string) file_get_contents(INCLUDE_PATH . '/settings.php'), 'agent_credit_'));
    check('I migration file adds credit_limit', str_contains((string) @file_get_contents($ROOT . '/database/upgrade-2026-11-25-op5-credit.sql'), 'credit_limit DECIMAL(12,2) NULL'));
    foreach (['includes/agentcredit.php', 'admin/agent-360.php', 'admin/agents.php', 'admin/settings.php', 'admin/_guard.php', 'includes/booking.php', 'includes/agentwallet.php'] as $f) {
        $out = []; $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
        check('I lint ' . $f, $rc === 0, implode(' ', $out));
    }
} catch (Throwable $e) {
    check('no uncaught error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

$signOut();
$cleanup();
$left = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username LIKE :u', ['u' => 'op5c-%-' . $tag], 0);
check('cleanup: no test accounts left', $left === 0);

echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
