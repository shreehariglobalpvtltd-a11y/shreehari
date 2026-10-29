<?php
/**
 * AgentTargets — monthly target, rank, badges and the manual bonus
 * (29 Oct 2026, management upgrade Phase 1.2).
 *
 *   php -c .claude/php-dev.ini tests/agent-targets-test.php
 *
 * Pins:
 *  - an agent's own row wins over the company default; clearing it falls back,
 *  - progress counts CONFIRMED sales by booking date (a cancelled sale counts
 *    toward "tickets" but not the money), % / remaining / bonus arithmetic,
 *  - rank among active agents and the gap to the next one up,
 *  - badges (Top Seller / 100% Target / Zero Cancellation),
 *  - the bonus is refused for a running month, credited once for an ended
 *    month as one 'adjustment' row (ref BONUS-YYYY-MM), refused a second time.
 *
 * Writes only throwaway rows (two test agents, their bookings, ledger and
 * target rows) and removes them at the end. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agenttargets.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

const T_A = 'testtgt-agent-a';
const T_B = 'testtgt-agent-b';

function tgt_cleanup(): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username IN ('" . T_A . "','" . T_B . "')") as $r) {
        $id = (int) $r['id'];
        Database::run("DELETE FROM bookings WHERE sold_by_admin_id = :a AND pnr LIKE 'SHG-TGTTEST-%'", ['a' => $id]);
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]);
        if (AgentTargets::ensure()) {
            Database::delete('agent_targets', 'agent_admin_id = :a', ['a' => $id]);
        }
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]);
        Database::delete('admins', 'id = :a', ['a' => $id]);
    }
}

$thisYm = date('Y-m');
$lastYm = date('Y-m', (int) strtotime(date('Y-m-01') . ' -1 month'));
$lastMid = $lastYm . '-15 12:00:00';
$nowTs  = date('Y-m-d H:i:s');

try {
    tgt_cleanup();
    check('agent_targets table exists (self-created)', AgentTargets::ensure());

    $ids = [];
    foreach ([T_A, T_B] as $u) {
        $ids[$u] = Database::insert('admins', [
            'username' => $u, 'password_hash' => password_hash('x' . random_int(1000, 9999), PASSWORD_DEFAULT),
            'full_name' => 'Target Test ' . strtoupper(substr($u, -1)), 'role' => 'agent',
            'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    $a = $ids[T_A]; $b = $ids[T_B];

    $n = 0;
    $sale = static function (int $seller, float $amt, string $status, string $at) use (&$n): void {
        $n++;
        Database::insert('bookings', [
            'pnr' => 'SHG-TGTTEST-' . $seller . '-' . $n, 'status' => $status, 'sold_by_admin_id' => $seller,
            'contact_phone' => '9999900000', 'contact_email' => 'tgt@example.test',
            'total_amount' => $amt, 'currency' => 'INR', 'source' => 'agent',
            'confirmed_at' => $at, 'created_at' => $at,
        ]);
    };
    // Last month: A sells 60,000 confirmed (6 tickets, none cancelled).
    for ($i = 0; $i < 6; $i++) { $sale($a, 10000, 'confirmed', $lastMid); }
    // This month: A 30,000 confirmed + 1 cancelled; B 45,000 confirmed.
    for ($i = 0; $i < 3; $i++) { $sale($a, 10000, 'confirmed', $nowTs); }
    $sale($a, 10000, 'cancelled', $nowTs);
    for ($i = 0; $i < 3; $i++) { $sale($b, 15000, 'confirmed', $nowTs); }

    // ---- Target source ---------------------------------------------------
    $def = Settings::getFloat('agent_target_monthly', 0.0);
    $t0  = AgentTargets::targetFor($a, $thisYm);
    check('no own row → company default (or none)', $t0['source'] === ($def > 0 ? 'default' : 'none'));

    AgentTargets::set($a, $thisYm, 40000, 5, 10, 'test', 1);
    $t1 = AgentTargets::targetFor($a, $thisYm);
    check('own row wins', $t1['source'] === 'agent' && abs($t1['amount'] - 40000) < 0.01 && $t1['bookings'] === 5 && abs($t1['bonus_pct'] - 10) < 0.01);

    $refused = false;
    try { AgentTargets::set($a, $thisYm, 1000, 0, 80, '', 1); } catch (RuntimeException $e) { $refused = true; }
    check('bonus above 50% is refused', $refused);
    $refused = false;
    try { AgentTargets::set($a, '2026-13', 1000, 0, 0, '', 1); } catch (RuntimeException $e) { $refused = true; }
    check('a bad month is refused', $refused);

    // ---- Progress ----------------------------------------------------------
    $p = AgentTargets::progress($a, $thisYm);
    check('money counts confirmed only (30,000)', abs($p['sales']['money'] - 30000) < 0.01);
    check('confirmed 3 · cancelled 1 · tickets 4', $p['sales']['confirmed'] === 3 && $p['sales']['cancelled'] === 1 && $p['sales']['tickets'] === 4);
    check('75% of 40,000, 10,000 remaining', $p['pct'] === 75 && abs($p['remaining'] - 10000) < 0.01);
    check('no bonus below the target', $p['bonus'] == 0.0);
    check('running month is not past', $p['is_current'] && !$p['is_past'] && $p['days_left'] >= 1);
    check('per-day need = remaining / days left', abs($p['per_day'] - round(10000 / $p['days_left'], 2)) < 0.01);

    // ---- Rank ------------------------------------------------------------
    $rA = AgentTargets::rankFor($a, $thisYm);
    $rB = AgentTargets::rankFor($b, $thisYm);
    check('B ranks above A', $rB['rank'] < $rA['rank']);
    check('A\'s gap to the next agent up is at most 15,000', $rA['gap'] > 0 && $rA['gap'] <= 15000.01);

    // ---- Badges ----------------------------------------------------------
    $labels = array_column(AgentTargets::badges($a, $p), 'label');
    check('A (1 cancelled, below target) has no Zero-Cancellation or 100% badge',
        !in_array('Zero Cancellation', $labels, true) && !in_array('100% Target', $labels, true));

    // ---- Last month: target 50,000 + 10% bonus on 60,000 → 1,000 ------------
    AgentTargets::set($a, $lastYm, 50000, 0, 10, '', 1);
    $pl = AgentTargets::progress($a, $lastYm);
    check('last month is past, 120%', $pl['is_past'] && $pl['pct'] === 120);
    check('bonus 10% of 10,000 above = 1,000', abs($pl['bonus'] - 1000) < 0.01 && abs($pl['excess'] - 10000) < 0.01);
    $lb = array_column(AgentTargets::badges($a, $pl), 'label');
    check('last month: 100% Target + Zero Cancellation badges', in_array('100% Target', $lb, true) && in_array('Zero Cancellation', $lb, true));

    $refused = false;
    try { AgentTargets::postBonus($a, $thisYm, 1); } catch (RuntimeException $e) { $refused = true; }
    check('bonus for the running month is refused', $refused);

    $before = AgentWallet::balances($a)['commission'];
    $lid = AgentTargets::postBonus($a, $lastYm, 1);
    $row = Database::fetch('SELECT entry_type, account, amount, ref FROM agent_ledger WHERE id = :i', ['i' => $lid]);
    check('bonus credited as one commission adjustment', $row !== null && $row['entry_type'] === 'adjustment'
        && $row['account'] === 'commission' && abs((float) $row['amount'] - 1000) < 0.01 && $row['ref'] === 'BONUS-' . $lastYm);
    check('commission balance rose by 1,000', abs(AgentWallet::balances($a)['commission'] - $before - 1000) < 0.01);
    check('bonusPosted() now true', AgentTargets::bonusPosted($a, $lastYm) && AgentTargets::progress($a, $lastYm)['bonus_posted']);

    $refused = false;
    try { AgentTargets::postBonus($a, $lastYm, 1); } catch (RuntimeException $e) { $refused = true; }
    check('a second bonus for the same month is refused', $refused);
    check('still exactly one BONUS row', (int) Database::scalar(
        "SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND ref = :r", ['a' => $a, 'r' => 'BONUS-' . $lastYm], 0) === 1);

    // ---- Clearing --------------------------------------------------------
    AgentTargets::set($a, $thisYm, 0, 0, 0, '', 1);
    check('amount 0 + tickets 0 removes the row', AgentTargets::targetFor($a, $thisYm)['source'] !== 'agent');
    check('audit rows written', (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action IN ('agent.target_set','agent.target_clear') AND entity_id = :a", ['a' => (string) $a], 0) >= 3);
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    tgt_cleanup();
    foreach ($ids ?? [] as $id) {
        Database::run("DELETE FROM audit_logs WHERE action IN ('agent.target_set','agent.target_clear','agent.adjustment') AND entity_type = 'admin' AND entity_id = :a", ['a' => (string) $id]);
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
