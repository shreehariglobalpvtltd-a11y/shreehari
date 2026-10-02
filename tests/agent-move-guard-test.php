<?php
/**
 * Bulk agent assignment never quietly takes another agent's tickets
 * (2 Oct 2026).
 *
 * On 1 Oct a seat-map pick "Select seats → assign agent → SHG-0006" carried
 * three berths that had belonged to SHG-0007 since the minute they were sold;
 * their commission went with them and the owner's count for 07 fell from 16
 * to 13 with nothing on screen to say why.
 *
 *   1. AgentWallet::heldByOtherAgent(): free for no seller, office staff, the
 *      company "Other" code and the same agent; the code for another agent.
 *   2. Seat map bulk assign (HTTP): another agent's ticket is SKIPPED (and
 *      named in the flash) unless move_owned=1; an unassigned one moves.
 *   3. Assign Agents page: the same for ticked rows; its search box works
 *      (the old triple :q placeholder threw under native prepares).
 *   4. The agent panel lists the ticket that was moved AWAY.
 *
 *   php -c .claude/php-dev.ini tests/agent-move-guard-test.php   (HTTP part needs SHG_TEST_BASE)
 *
 * Throwaway agents + bookings on a far-future date; cleans up. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'notify', 'booking', 'agentwallet'] as $inc) {
    require_once INCLUDE_PATH . '/' . $inc . '.php';
}

define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
const TD      = '2099-10-22';
const AGENT_A = 'amg-agent-a';
const AGENT_B = 'amg-agent-b';
const BOSS    = 'amg-boss';
const DESK    = 'amg-desk';
const PHONES  = ['9100007861', '9100007862', '9100007863', '9100007864'];
$PW = 'MoveGuard@12345';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function req(string $jar, string $method, string $url, array $form = []): array {
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($form !== []) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hsz)];
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_amg_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }
function signIn(string $user, string $pw): string {
    $j  = jar($user);
    $lp = req($j, 'GET', '/admin/login.php');
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
    req($j, 'POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
    return $j;
}
function csrfFrom(string $html): string {
    return preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}
function soldBy(int $bid): int { return (int) Database::scalar('SELECT COALESCE(sold_by_admin_id, 0) FROM bookings WHERE id = :i', ['i' => $bid], 0); }
function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules',    'travel_date = :d', ['d' => TD]);
    foreach ([AGENT_A, AGENT_B, BOSS, DESK] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
}

echo "\n=== Bulk agent assignment guard ===\n\n";
cleanup();
$codesBefore = Database::fetch("SELECT svalue FROM settings WHERE skey = 'agent_codes'");
try {
    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
    $rid  = (int) $route['id'];
    $sid  = (int) Seats::schedule($rid, TD)['id'];
    $free = Seats::availability($rid, TD)['available'] ?? [];
    if (count($free) < 6) { echo "  SKIP  not enough free seats\n"; exit(0); }

    $mk = static fn(string $u, string $n, string $role): int => (int) Database::insert('admins', [
        'username' => $u, 'password_hash' => password_hash($GLOBALS['PW'], PASSWORD_BCRYPT), 'full_name' => $n,
        'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    $aId    = $mk(AGENT_A, 'Guard Agent A', 'agent');
    $bId    = $mk(AGENT_B, 'Guard Agent B', 'agent');
    $bossId = $mk(BOSS, 'Guard Boss', 'superadmin');
    $deskId = $mk(DESK, 'Guard Desk', 'counter');
    // Give both agents SHG codes (the seat map and Assign Agents only offer coded agents).
    $map = Settings::getArray('agent_codes', []);
    $map[(string) $aId] = 971; $map[(string) $bId] = 972;   // AGENT_CODE_MAX is 1000
    Settings::set('agent_codes', $map, 'json', 'agent', false);
    Settings::flush();

    $sell = static fn(array $seats, string $phone, int $by): array => BookingService::counterSale(
        $route, $sid, TD, $seats, ['name' => 'Guard Pax ' . substr($phone, -2), 'phone' => $phone, 'gender' => 'Male', 'paymentMethod' => 'cash'], $by, 'counter'
    );
    $bkA    = $sell([$free[0], $free[1]], PHONES[0], $aId);     // agent A's
    $bkDesk = $sell([$free[2]], PHONES[1], $deskId);            // the desk's, no agent
    $bkA2   = $sell([$free[3]], PHONES[2], $aId);               // agent A's, for Assign Agents
    $bkDesk2 = $sell([$free[4]], PHONES[3], $deskId);

    /* ---- 1. the rule ----------------------------------------------------- */
    check('free: no seller', AgentWallet::heldByOtherAgent(['sold_by_admin_id' => null], $bId) === '');
    check('free: an office / counter seller', AgentWallet::heldByOtherAgent(['sold_by_admin_id' => $deskId], $bId) === '');
    check('free: already the target agent', AgentWallet::heldByOtherAgent(['sold_by_admin_id' => $bId], $bId) === '');
    check('held: another agent → their code', AgentWallet::heldByOtherAgent(['sold_by_admin_id' => $aId], $bId) === 'SHG-0971');
    $company = AgentWallet::resolveAgentCodeFromString(Settings::getString('company_agent_code', 'SHG-0001'));
    if ($company !== null) {
        check('free: the company "Other" code', AgentWallet::heldByOtherAgent(['sold_by_admin_id' => $company], $bId) === '');
    }

    /* ---- 2. seat map over HTTP ----------------------------------------- */
    $probe = req(jar('probe'), 'GET', '/admin/login.php');
    if ($probe['code'] !== 200) {
        echo "  SKIP  HTTP part: test server not reachable at " . BASE . "\n";
    } else {
        $jb   = signIn(BOSS, $PW);
        $page = req($jb, 'GET', '/admin/seatmap.php?route=' . $rid . '&date=' . TD);
        check('the seat map offers "also take tickets that belong to another agent"', str_contains($page['body'], 'name="move_owned"'));
        $post = static fn(array $seats, bool $take) => req($jb, 'POST', '/admin/seatmap.php?route=' . $rid . '&date=' . TD,
            ['shg_csrf' => csrfFrom($page['body']), 'route' => $rid, 'date' => TD, 'action' => 'assign_agent', 'agent_id' => $bId,
             'seats' => $seats] + ($take ? ['move_owned' => '1'] : []));

        $r = $post([(string) $free[0], (string) $free[2]], false);
        check('bulk pick for B: agent A\'s ticket stays with A', soldBy((int) $bkA['id']) === $aId);
        check('bulk pick for B: the desk ticket moves to B', soldBy((int) $bkDesk['id']) === $bId);
        check('the flash names the skipped ticket and its agent', str_contains($r['body'], (string) $bkA['pnr'] . ' (SHG-0971)') && str_contains($r['body'], 'NOT moved'));

        $page = req($jb, 'GET', '/admin/seatmap.php?route=' . $rid . '&date=' . TD);
        $post([(string) $free[0]], true);
        check('with "also take…" ticked, agent A\'s ticket moves to B', soldBy((int) $bkA['id']) === $bId);

        $ja = signIn(AGENT_A, $PW);
        $html = req($ja, 'GET', '/admin/agent.php')['body'];
        check('agent A\'s panel lists the ticket moved AWAY', str_contains($html, 'id="agentMoves"') && str_contains($html, (string) $bkA['pnr'])
            && str_contains($html, 'moved AWAY from this agent'));
        $html = req($jb, 'GET', '/admin/agent.php?agent=' . $bId)['body'];
        check('agent B\'s panel lists it as taken FROM another agent', str_contains($html, (string) $bkA['pnr']) && str_contains($html, 'taken FROM another agent'));

        /* ---- 3. Assign Agents ------------------------------------------- */
        $ap = req($jb, 'GET', '/admin/agent-assign.php?from=' . TD . '&to=' . TD . '&only=all');
        check('Assign Agents lists the day', $ap['code'] === 200 && str_contains($ap['body'], (string) $bkA2['pnr']));
        req($jb, 'POST', '/admin/agent-assign.php?from=' . TD . '&to=' . TD . '&only=all',
            ['shg_csrf' => csrfFrom($ap['body']), 'bulk_agent' => $bId, 'ids' => [(int) $bkA2['id'], (int) $bkDesk2['id']]]);
        check('Assign Agents bulk: agent A\'s ticked ticket is skipped', soldBy((int) $bkA2['id']) === $aId);
        check('Assign Agents bulk: the desk ticket moves', soldBy((int) $bkDesk2['id']) === $bId);
        $ap = req($jb, 'GET', '/admin/agent-assign.php?from=' . TD . '&to=' . TD . '&only=all');
        req($jb, 'POST', '/admin/agent-assign.php?from=' . TD . '&to=' . TD . '&only=all',
            ['shg_csrf' => csrfFrom($ap['body']), 'bulk_agent' => $bId, 'ids' => [(int) $bkA2['id']], 'move_owned' => '1']);
        check('Assign Agents bulk with "also take…": it moves', soldBy((int) $bkA2['id']) === $bId);
        $s = req($jb, 'GET', '/admin/agent-assign.php?from=' . TD . '&to=' . TD . '&only=all&q=Guard');
        check('Assign Agents search works (one bind per placeholder)', $s['code'] === 200 && str_contains($s['body'], (string) $bkA2['pnr']), 'HTTP ' . $s['code']);
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
    if ($codesBefore !== null) {
        Database::update('settings', ['svalue' => (string) $codesBefore['svalue']], 'skey = :k', ['k' => 'agent_codes']);
        try { Settings::flush(); } catch (Throwable $e) {}
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
