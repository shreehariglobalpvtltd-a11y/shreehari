<?php
/**
 * Live sync + seat detail (2 Oct 2026).
 *
 * Owner: "real time ma jun ticket edit, update, change garyo … agent panel
 * ma pani, admin panel ma pani change hunu paryo" and "seat ma click garne
 * bittikai tyasko details dekhinu paryo".
 *
 *   1. LiveSync::version() moves when anything an agent / office screen
 *      prints moves — a reseat (an in-place UPDATE), a name fix, the agent
 *      changed, a cancel, a bus swap — and stays put otherwise; a change to
 *      one agent's book does not move another agent's.
 *   2. SeatVersion moves on a reseat (it used to watch count + max id only,
 *      so the live stream never fired for a seat change).
 *   3. LiveSync::scopeFor() pins a counter agent to their own book.
 *   4. Over HTTP: admin/api/seat-detail.php gives the office and the
 *      selling agent the full card, another agent only "sold"; the poll
 *      answers a sync scope (pinned for an agent); the seat map lists every
 *      bus on a route + date.
 *
 *   php -c .claude/php-dev.ini tests/live-sync-test.php   (HTTP part needs SHG_TEST_BASE)
 *
 * Throwaway agents + bookings on a far-future date; cleans up. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'notify', 'booking', 'agentwallet', 'seatversion', 'livesync'] as $inc) {
    require_once INCLUDE_PATH . '/' . $inc . '.php';
}

define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
const TD      = '2099-10-21';
const AGENT_A = 'lst-agent-a';
const AGENT_B = 'lst-agent-b';
const BOSS    = 'lst-boss';
const PHONE_A = '9100007851';
const PHONE_B = '9100007852';
$PW = 'LiveSync@12345';

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
    $body = substr($raw, $hsz);
    $json = ($body !== '' && $body[0] === '{') ? json_decode($body, true) : null;
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_lst_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }
function signIn(string $user, string $pw): array {
    $j  = jar($user);
    $lp = req($j, 'GET', '/admin/login.php');
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
    req($j, 'POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
    return [$j, req($j, 'GET', '/admin/agent.php')['code']];
}
function cleanup(): void {
    foreach ([PHONE_A, PHONE_B] as $p) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules',    'travel_date = :d', ['d' => TD]);
    foreach ([AGENT_A, AGENT_B, BOSS] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
}

echo "\n=== Live sync + seat detail ===\n\n";
cleanup();
try {
    $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "  SKIP  no active sleeper route\n"; exit(0); }
    $rid  = (int) $route['id'];
    $sid  = (int) Seats::schedule($rid, TD)['id'];
    $free = Seats::availability($rid, TD)['available'] ?? [];
    if (count($free) < 6) { echo "  SKIP  not enough free seats on the fixture day\n"; exit(0); }

    $mk = static fn(string $u, string $n, string $role): int => (int) Database::insert('admins', [
        'username' => $u, 'password_hash' => password_hash($GLOBALS['PW'], PASSWORD_BCRYPT), 'full_name' => $n,
        'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    $aId = $mk(AGENT_A, 'Sync Agent A', 'agent');
    $bId = $mk(AGENT_B, 'Sync Agent B', 'agent');
    $mk(BOSS, 'Sync Boss', 'superadmin');

    $sell = static fn(array $seats, string $name, string $phone, int $by): array => BookingService::counterSale(
        $route, $sid, TD, $seats, ['name' => $name, 'phone' => $phone, 'gender' => 'Male', 'paymentMethod' => 'cash'], $by, 'agent'
    );
    $bkA = $sell([$free[0], $free[1]], 'Sync Pax A', PHONE_A, $aId);
    $bkB = $sell([$free[2]], 'Sync Pax B', PHONE_B, $bId);
    check('setup: two agent sales on one departure', (string) $bkA['status'] === 'confirmed' && (string) $bkB['status'] === 'confirmed');

    $vA = static fn(): string => LiveSync::version('agent:' . $aId);
    $vB = static fn(): string => LiveSync::version('agent:' . $bId);
    $vS = static fn(): string => LiveSync::version('sched:' . $sid);

    /* ---- 1. stability --------------------------------------------------- */
    $a0 = $vA(); $b0 = $vB(); $s0 = $vS(); $seat0 = SeatVersion::of($sid);
    check('a version is a 16-char hash', strlen($a0) === 16 && strlen($s0) === 16, $a0);
    check('nothing changed → the same version twice', $a0 === $vA() && $s0 === $vS() && $seat0 === SeatVersion::of($sid));
    check('two agents have different versions', $a0 !== $b0);

    /* ---- 2. a reseat is an in-place UPDATE ------------------------------ */
    Seats::transferSeat($sid, (string) $free[0], (string) $free[5], (string) $route['coach_type'], 0, 'live-sync test');
    check('reseat → agent A\'s version moves', $vA() !== $a0);
    check('reseat → the departure\'s version moves', $vS() !== $s0);
    check('reseat → SeatVersion moves (the live stream fires)', SeatVersion::of($sid) !== $seat0);
    check('reseat of A\'s passenger → agent B\'s version does not', $vB() === $b0);

    /* ---- 3. a name fix (booking_passengers only) ------------------------ */
    $a1 = $vA();
    Database::query('UPDATE booking_passengers SET full_name = :n WHERE booking_id = :b AND is_primary = 1', ['n' => 'Sync Pax A Fixed', 'b' => (int) $bkA['id']]);
    check('passenger name fixed → agent A\'s version moves', $vA() !== $a1);

    /* ---- 4. the agent changed on a ticket ------------------------------- */
    $a2 = $vA(); $b2 = $vB();
    AgentWallet::reassignSeller(BookingService::detail((string) $bkB['pnr']), $aId, 0);
    check('ticket credited B → A: A\'s version moves', $vA() !== $a2);
    check('ticket credited B → A: B\'s version moves (it left B\'s book)', $vB() !== $b2);

    /* ---- 5. a bus swap on the departure --------------------------------- */
    $a3 = $vA();
    $otherBus = (int) Database::scalar('SELECT id FROM buses WHERE id <> COALESCE((SELECT bus_id FROM schedules WHERE id = :s), 0) ORDER BY id LIMIT 1', ['s' => $sid], 0);
    if ($otherBus > 0) {
        $prevBus = Database::scalar('SELECT bus_id FROM schedules WHERE id = :s', ['s' => $sid]);
        Database::update('schedules', ['bus_id' => $otherBus], 'id = :id', ['id' => $sid]);
        check('bus swapped on the departure → the agent\'s version moves', $vA() !== $a3);
        Database::update('schedules', ['bus_id' => $prevBus], 'id = :id', ['id' => $sid]);
    }

    /* ---- 6. a cancel ----------------------------------------------------- */
    $a4 = $vA();
    BookingService::cancel((string) $bkA['pnr'], 'live-sync test', false, 'admin');
    check('cancel → agent A\'s version moves', $vA() !== $a4);

    /* ---- 7. scope pinning ------------------------------------------------ */
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bId, 'username' => AGENT_B, 'full_name' => AGENT_B, 'role' => 'agent',
        'permissions' => [], 'must_change_pw' => false, 'logged_in_at' => time(), 'last_seen' => time()];
    check('an agent asking for another agent\'s scope gets their own', LiveSync::scopeFor('agent:' . $aId) === 'agent:' . $bId);
    check('an agent asking for the office scope gets their own', LiveSync::scopeFor('all') === 'agent:' . $bId);
    check('a departure scope is kept for an agent (the seat map is theirs to watch)', LiveSync::scopeFor('sched:' . $sid) === 'sched:' . $sid);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'office', 'full_name' => 'office', 'role' => 'superadmin',
        'permissions' => [], 'must_change_pw' => false, 'logged_in_at' => time(), 'last_seen' => time()];
    check('the office may watch any agent', LiveSync::scopeFor('agent:' . $aId) === 'agent:' . $aId);
    check('the office may watch everything', LiveSync::scopeFor('all') === 'all');
    check('nonsense scope → none', LiveSync::scopeFor('agent:x; DROP') === '' && LiveSync::version('') === '');
    unset($_SESSION[ADMIN_SESSION_KEY]);

    /* ---- 8. over HTTP ------------------------------------------------------ */
    $probe = req(jar('probe'), 'GET', '/admin/login.php');
    if ($probe['code'] !== 200) {
        echo "  SKIP  HTTP part: test server not reachable at " . BASE . "\n";
    } else {
        // A fresh sale for A (A's first one is cancelled now), B keeps none.
        $free2 = Seats::availability($rid, TD)['available'] ?? [];
        $bkA2  = $sell([$free2[0]], 'Sync Pax A2', PHONE_A, $aId);
        $bkB2  = $sell([$free2[1]], 'Sync Pax B2', PHONE_B, $bId);
        $seatA = (string) $free2[0];
        $seatB = (string) $free2[1];

        [$ja, $codeA] = signIn(AGENT_A, $PW);
        check('agent A signs in', $codeA === 200, 'HTTP ' . $codeA);
        $d = req($ja, 'GET', '/admin/api/seat-detail.php?sid=' . $sid . '&seat=' . rawurlencode($seatA))['json'] ?? [];
        check('agent A gets the full card for their own berth', ($d['booking']['pnr'] ?? '') === (string) $bkA2['pnr']
            && ($d['booking']['passengers'][0]['name'] ?? '') === 'Sync Pax A2', (string) ($d['booking']['pnr'] ?? json_encode($d)));
        check('the card names the seller code / kind', ($d['booking']['seller']['kind'] ?? '') === 'agent');
        $d = req($ja, 'GET', '/admin/api/seat-detail.php?sid=' . $sid . '&seat=' . rawurlencode($seatB))['json'] ?? [];
        check('agent A gets only "sold" for agent B\'s berth — no ticket, no name', ($d['ok'] ?? false) === true
            && ($d['status'] ?? '') === 'booked' && ($d['mine'] ?? true) === false && !isset($d['booking']), json_encode($d));
        $p = req($ja, 'GET', '/admin/pending-count.php?sync=agent:' . $bId)['json'] ?? [];
        check('the poll pins agent A to their own scope', ($p['sync'] ?? '') === 'agent:' . $aId && strlen((string) ($p['ver'] ?? '')) === 16, json_encode($p));

        [$jb] = signIn(BOSS, $PW);
        $d = req($jb, 'GET', '/admin/api/seat-detail.php?sid=' . $sid . '&seat=' . rawurlencode($seatB))['json'] ?? [];
        check('the office gets the full card for any berth', ($d['booking']['pnr'] ?? '') === (string) $bkB2['pnr'], (string) ($d['booking']['pnr'] ?? ''));
        $d = req($jb, 'GET', '/admin/api/seat-detail.php?sid=' . $sid . '&seat=ZZ99')['json'] ?? [];
        check('an unknown seat is refused cleanly', ($d['ok'] ?? true) === false);
        $p = req($jb, 'GET', '/admin/pending-count.php?sync=agent:' . $aId)['json'] ?? [];
        check('the office poll answers the agent scope it asked for', ($p['sync'] ?? '') === 'agent:' . $aId && ($p['ver'] ?? '') === LiveSync::version('agent:' . $aId));

        // A second bus on the same route + date shows up as a tab on the seat map.
        $sid2 = (int) Database::insert('schedules', ['route_id' => $rid, 'travel_date' => TD, 'slot' => 2, 'total_seats' => 72, 'status' => 'scheduled']);
        $html = req($jb, 'GET', '/admin/seatmap.php?route=' . $rid . '&date=' . TD)['body'];
        check('seat map lists both buses on the date', str_contains($html, 'class="sm-buses"') && str_contains($html, 'sid=' . $sid2), 'tabs ' . (str_contains($html, 'sm-buses') ? 'shown' : 'missing'));
        check('seat map carries its live-sync anchor', str_contains($html, 'data-scope="sched:' . $sid . '"'));
        $html = req($ja, 'GET', '/admin/agent.php')['body'];
        // (the fixture day is in 2099 — outside the panel's 7-back / 30-ahead window)
        check('agent panel carries the passengers-by-journey-date panel', str_contains($html, 'id="tripsByJourney"') && !str_contains($html, 'id="trip-' . $sid . '"'));
        check('agent panel carries its live-sync anchor (own scope)', str_contains($html, 'data-scope="agent:' . $aId . '"'));
        $html = req($ja, 'GET', '/admin/agent-sales.php?range=custom&from=' . TD . '&to=' . TD)['body'];
        check('My Sales on the journey basis finds the far-future trip', str_contains($html, (string) $bkA2['pnr']));
        $html = req($ja, 'GET', '/admin/agent-sales.php?basis=sale&range=custom&from=' . TD . '&to=' . TD)['body'];
        check('My Sales on the sale basis does not (cut today, travels in 2099)', !str_contains($html, (string) $bkA2['pnr']));
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
