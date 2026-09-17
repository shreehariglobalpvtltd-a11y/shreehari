<?php
/**
 * admin/api/wa-send.php — who may compose what (18 Sep 2026).
 *
 * Over real HTTP against the test server: a signed-in AGENT may preview
 * their OWN statement-type messages (WaTemplates::OWN_AGENT_PURPOSES)
 * without commissions.view, and a ticket for a booking THEY sold — but
 * never another agent's account, another seller's booking, an office
 * summary or a departure document. The office (superadmin) may do all of
 * it. CSRF is enforced on every call. Preview only — nothing is sent.
 *
 *   php tests/wa-send-scope-test.php          (needs php -S 127.0.0.1:8899)
 * Throwaway agents + bookings on a far-future date; cleans up after itself
 * and restores the settings it pins. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/watemplates.php';

define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
const TD      = '2099-09-24';
const AGENT_A = 'wss-agent-a';
const AGENT_B = 'wss-agent-b';
const BOSS    = 'wss-boss';
const PHONE_A = '9100007741';
const PHONE_B = '9100007742';
$PW = 'WsScope@12345';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function req(string $jar, string $method, string $url, array $opt = []): array {
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
        if (!empty($opt['csrf'])) { $headers[] = 'X-CSRF-Token: ' . $opt['csrf']; }
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $body = substr($raw, $hsz);
    $json = null; if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) { $json = json_decode($body, true); }
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
}
function jar(string $n): string { $p = sys_get_temp_dir() . '/shg_wss_' . $n . '_' . getmypid() . '.cookies'; @unlink($p); return $p; }
/** Sign a staff account in and return [jar, csrf from the admin shell's <meta name="csrf">]. */
function signIn(string $user, string $pw): array {
    $j  = jar($user);
    $lp = req($j, 'GET', '/admin/login.php');
    preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
    req($j, 'POST', '/admin/login.php', ['form' => ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]]);
    $page = req($j, 'GET', '/admin/agent.php');
    preg_match('/<meta name="csrf" content="([^"]+)"/', $page['body'], $c);
    return [$j, (string) ($c[1] ?? ''), $page['code']];
}
/** One preview call; returns [ok(bool), error-or-text, raw json]. */
function preview(string $jar, string $csrf, string $purpose, array $ctx): array {
    $r = req($jar, 'POST', '/admin/api/wa-send.php', ['json' => ['action' => 'preview', 'purpose' => $purpose] + $ctx, 'csrf' => $csrf]);
    $j = $r['json'] ?? [];
    return [(bool) ($j['ok'] ?? false), (string) ($j['error'] ?? ($j['text'] ?? '')), $j, $r['code']];
}

/* ---- settings pinned for the run, restored raw --------------------------- */
$PINNED = ['wa_admin_tools_enabled'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreSettings = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) {
            try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            try {
                Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

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
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%' OR bucket LIKE 'wa_send%'"); } catch (Throwable $e) {}
}

echo "\n=== wa-send.php scope walls (agent vs office, over HTTP) ===\n\n";
cleanup();
$sidPinned = false;
try {
    $probe = req(jar('probe'), 'GET', '/admin/login.php');
    if ($probe['code'] !== 200) { echo "  SKIP  test server not reachable at " . BASE . " (HTTP {$probe['code']})\n"; exit(0); }

    Settings::set('wa_admin_tools_enabled', '1', 'bool', 'whatsapp', false);
    Settings::flush();

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "  SKIP  no active route\n"; exit(0); }
    $rid   = (int) $route['id'];
    $sched = Seats::schedule($rid, TD);
    $sid   = (int) $sched['id'];
    $free  = Seats::availability($rid, TD)['available'] ?? [];
    if (count($free) < 2) { echo "  SKIP  fewer than two free seats on the fixture day\n"; exit(0); }

    $mk = static function (string $user, string $name, string $role, string $pw): int {
        return (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash($pw, PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    };
    $aId = $mk(AGENT_A, 'Scope Agent A', 'agent', $PW);
    $bId = $mk(AGENT_B, 'Scope Agent B', 'agent', $PW);
    $mk(BOSS, 'Scope Boss', 'superadmin', $PW);
    try { AgentWallet::saveProfile($aId, ['whatsapp' => PHONE_A]); AgentWallet::saveProfile($bId, ['whatsapp' => PHONE_B]); } catch (Throwable $e) {}

    $bkA = BookingService::counterSale($route, $sid, TD, [$free[0]], ['name' => 'Scope Pax A', 'phone' => PHONE_A, 'gender' => 'Male', 'paymentMethod' => 'cash'], $aId, 'counter');
    $bkB = BookingService::counterSale($route, $sid, TD, [$free[1]], ['name' => 'Scope Pax B', 'phone' => PHONE_B, 'gender' => 'Male', 'paymentMethod' => 'cash'], $bId, 'counter');
    check('two counter sales seeded (one per agent)', (string) $bkA['status'] === 'confirmed' && (string) $bkB['status'] === 'confirmed', "A={$bkA['pnr']} B={$bkB['pnr']}");

    /* ---- as agent A ------------------------------------------------------ */
    [$ja, $csrfA, $codeA] = signIn(AGENT_A, $PW);
    check('agent A signs in and gets a CSRF token', $codeA === 200 && $csrfA !== '', 'HTTP ' . $codeA);

    [$ok, $msg] = preview($ja, $csrfA, 'agent_statement', ['agent_id' => $aId, 'from' => '2026-09-01', 'to' => todayISO()]);
    check('agent A may preview their OWN statement (no commissions.view needed)', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'agent_outstanding', ['agent_id' => $aId]);
    check('agent A may preview their OWN outstanding balance', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'agent_statement', ['agent_id' => $bId, 'from' => '2026-09-01', 'to' => todayISO()]);
    check('agent A is refused agent B\'s statement', !$ok, $msg);   // the registry permission fires first; the scope wall behind it is the second line of defence
    [$ok, $msg] = preview($ja, $csrfA, 'agent_settlement_done', ['agent_id' => $aId, 'ledger_id' => 1]);
    check('agent A is refused an office-only purpose about themselves (settlement notice)', !$ok, $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'agent_payment_reminder', ['agent_id' => $aId]);
    check('agent A is refused a payment reminder about themselves', !$ok, $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'booking_ticket', ['pnr' => (string) $bkA['pnr']]);
    check('agent A may preview the ticket of a booking THEY sold', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'booking_ticket', ['pnr' => (string) $bkB['pnr']]);
    check('agent A is refused the ticket of agent B\'s booking', !$ok, $msg);   // the composer's own scope refuses first (Booking not found); the wall after compose is the backstop
    [$ok, $msg] = preview($ja, $csrfA, 'booking_passengers', ['pnr' => (string) $bkB['pnr']]);
    check('agent A is refused the passenger details of agent B\'s booking', !$ok, $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'admin_daily_summary', ['on' => todayISO()]);
    check('agent A is refused the office daily summary', !$ok, $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'chalan_send', ['sid' => $sid, 'doc' => 'challan', 'target' => 'office']);
    check('agent A is refused a departure document (chalan)', !$ok, $msg);
    [$ok, $msg] = preview($ja, '', 'agent_statement', ['agent_id' => $aId, 'from' => '2026-09-01', 'to' => todayISO()]);
    check('a call without the CSRF header is refused', !$ok && stripos($msg, 'session') !== false, $msg);
    [$ok, $msg] = preview($ja, $csrfA, 'no_such_purpose', []);
    check('an unknown purpose is refused', !$ok, $msg);

    /* ---- as the office --------------------------------------------------- */
    [$jb, $csrfB, $codeB] = signIn(BOSS, $PW);
    check('the office signs in', $codeB === 200 && $csrfB !== '', 'HTTP ' . $codeB);
    [$ok, $msg] = preview($jb, $csrfB, 'agent_statement', ['agent_id' => $aId, 'from' => '2026-09-01', 'to' => todayISO()]);
    check('the office may preview agent A\'s statement', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($jb, $csrfB, 'booking_ticket', ['pnr' => (string) $bkB['pnr']]);
    check('the office may preview any booking\'s ticket', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($jb, $csrfB, 'agent_payment_reminder', ['agent_id' => $bId]);
    check('the office may preview a payment reminder to an agent', $ok, $ok ? 'ok' : $msg);
    [$ok, $msg] = preview($jb, $csrfB, 'admin_daily_summary', ['on' => todayISO()]);
    check('the office may preview the daily summary', $ok, $ok ? 'ok' : $msg);

    /* ---- the switch ------------------------------------------------------- */
    Settings::set('wa_admin_tools_enabled', '0', 'bool', 'whatsapp', false);
    Settings::flush();
    [$ok, $msg] = preview($jb, $csrfB, 'agent_statement', ['agent_id' => $aId, 'from' => '2026-09-01', 'to' => todayISO()]);
    check('with wa_admin_tools_enabled off even the office is refused', !$ok && stripos($msg, 'switched off') !== false, $msg);

} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
    $restoreSettings();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
