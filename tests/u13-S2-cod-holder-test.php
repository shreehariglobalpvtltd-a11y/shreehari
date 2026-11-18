<?php
/**
 * u13 S2 (17 Nov 2026, owner decision 2 - "as your wish"): who holds a
 * pay-at-boarding (COD) fare.
 *
 *   BookingService::settleCod(..., $holder)
 *     'office' (DEFAULT - bus crew / office; Payments COD tab, booking page,
 *              cash book, chalani tick)  -> agent earns commission, NO cash_due
 *     'agent'  (agent's own WhatsApp tool, or the office picks "एजेन्ट")
 *                                         -> commission + cash_due (as before)
 *     'online' (UTR claim / UPI / eSewa tick) -> commission, NO cash_due
 *   Non-agent COD unchanged. Cancel reverses only what was written.
 *   Payments COD tab: "कसले लियो · Who has the cash" only on agent-sold, non-claim rows.
 *
 *   SHG_TEST_BASE=http://localhost:9302 php -c .claude/php-dev.ini -d extension=php_curl.dll tests/u13-S2-cod-holder-test.php
 *
 * Synthetic rows only (no customer flow, WhatsApp switched off while it runs);
 * everything it made is deleted and every switch put back.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/quickunlock.php';
require_once INCLUDE_PATH . '/payadapter.php';
require_once INCLUDE_PATH . '/ledgercheck.php';
require_once INCLUDE_PATH . '/aitools.php';

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:9302'), '/'));
const PH_PREFIX = '91000013';
const BOSS    = 'u13_s2_codholder_boss';
const BOSS_PW = 'U13S2CodHolder77';
const AGENT   = 'u13_s2_codholder_agent';
const STAGE_KEY = 'u13-s2-refix-stage';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JAR = sys_get_temp_dir() . '/shg_u13_s2_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, array $opt = []): array {
    global $JAR;
    $ch = curl_init(BASE . $url);
    $h = [];
    if (!empty($opt['ajax'])) { $h[] = 'X-Requested-With: XMLHttpRequest'; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $h]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function phones(): array {
    $o = [];
    for ($i = 10; $i <= 39; $i++) { $o[] = PH_PREFIX . $i; }
    return $o;
}
function cleanup(): void {
    $ph = phones();
    $in = "'" . implode("','", $ph) . "'";
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone IN ($in)") as $r) {
        foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
        if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
        foreach (['booking_collections', 'agent_ledger', 'pay_events', 'commissions', 'booking_fines'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_id = :p AND action LIKE 'booking.%'", ['p' => $r['pnr']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ([BOSS, AGENT] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::run('DELETE FROM quick_unlock WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
    try { Database::delete('kv_store', "kscope = 'wa_stage' AND kkey = :k", ['k' => STAGE_KEY]); } catch (Throwable $e) {}
}

echo "\n=== u13 S2: who holds pay-at-boarding cash (office default | agent | online) ===\n\n";
cleanup();

$keep = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod', 'agent_wallet_enabled', 'wa_agent_settle_cod_on'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$sched = null; $schedKeep = null; $schedMade = false;

try {
    Settings::set('whatsapp_notify_customer', '0', 'bool', 'notify', false);
    Settings::set('whatsapp_notify_admin', '0', 'bool', 'notify', false);
    Settings::set('allow_cod', '1', 'bool', 'booking', false);
    Settings::set('agent_wallet_enabled', '1', 'bool', 'agent', false);
    Settings::flush();

    $TD = '';
    for ($i = 60; $i >= 41; $i--) {
        $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
        if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
    }
    if ($TD === '') { throw new RuntimeException('no free date 41-60 days ahead to seed on'); }
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $schedKeep = Database::fetch('SELECT id, is_blocked, status, seats_booked FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]);
    $sched   = Seats::schedule($routeId, $TD);
    $schedMade = $schedKeep === null;
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);

    $bossId = (int) Database::insert('admins', ['username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'U13 S2 Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    $agentId = (int) Database::insert('admins', ['username' => AGENT, 'password_hash' => password_hash('U13S2AgentPw!9', PASSWORD_DEFAULT),
        'full_name' => 'U13 S2 Agent', 'role' => 'agent', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    QuickUnlock::set($bossId, 'pin', '583943');
    $boss = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
    $_SESSION[ADMIN_SESSION_KEY] = $boss;

    $seatNo = 0;
    $seatList = ['L2', 'L4', 'L6', 'L8', 'L10', 'L12', 'L14', 'L16', 'L18', 'L20', 'L22', 'L24', 'L26', 'L28', 'U2', 'U4', 'U6', 'U8'];
    $phoneNo = 10;
    /* A desk sale, then turned into an unpaid pay-at-boarding booking sold by $seller. */
    $cod = static function (string $tag, ?int $seller, string $utr = '') use (&$seatNo, &$phoneNo, $seatList, $routeId, $TD, $bossId): array {
        $seat = $seatList[$seatNo++];
        $phone = PH_PREFIX . ($phoneNo++);
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => [$seat],
            'passengers' => [['name' => 'U13 ' . $tag . ' Pax', 'age' => 33, 'gender' => 'Female']],
            'contact' => ['phone' => $phone], 'bookingMode' => 'sharing', 'paymentMethod' => 'cash', 'isCod' => false, 'boarding' => ''],
            ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => 'cash']);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        Database::update('bookings', ['is_cod' => 1, 'source' => 'web', 'sold_by_admin_id' => $seller, 'status' => 'confirmed'], 'id = :i', ['i' => (int) $row['id']]);
        $pay = ['status' => 'cod_pending', 'method' => 'cod', 'mode' => 'offline', 'verified_by' => null, 'verified_at' => null, 'gateway_payload' => null, 'utr_number' => null];
        if ($utr !== '') {   // the passenger's pay.php claim, as CodPayClaim stores it
            $pay['utr_number'] = $utr;
            $pay['mode'] = 'utr';
            $pay['gateway_payload'] = (string) json_encode(['pay_claim' => ['method' => 'esewa', 'utr' => $utr, 'at' => date('c')]], JSON_UNESCAPED_SLASHES);
        }
        Database::update('payments', $pay, 'booking_id = :b', ['b' => (int) $row['id']]);
        return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $row['id']]) ?? [];
    };
    $led = static function (array $b): array {
        $o = ['commission' => 0.0, 'commission_void' => 0.0, 'cash_due' => 0.0, 'n_cash' => 0, 'comm_acct' => 0.0, 'cash_acct' => 0.0, 'all' => 0.0, 'rows' => 0];
        foreach (Database::fetchAll('SELECT account, entry_type, amount FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $b['id']]) as $r) {
            $a = (float) $r['amount'];
            if (isset($o[$r['entry_type']])) { $o[$r['entry_type']] += $a; }
            if ($r['account'] === 'cash') { $o['cash_acct'] += $a; $o['n_cash']++; }
            else { $o['comm_acct'] += $a; }
            $o['all'] += $a; $o['rows']++;
        }
        return $o;
    };
    $expComm = static function (array $b) use ($agentId): float {
        $bid = (int) $b['id'];
        return AgentWallet::commissionForBooking(AgentWallet::commissionBase($b, $bid), $agentId, AgentWallet::seatCountFor($bid), (string) $b['created_at']);
    };
    $payRow = static fn(array $b): array => Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b['id']]) ?? [];
    $auditHolder = static function (array $b): string {
        $nv = (string) Database::scalar("SELECT new_value FROM audit_logs WHERE action = 'booking.cod.settle' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => $b['pnr']], '');
        $j = json_decode($nv, true);
        return is_array($j) ? (string) ($j['holder'] ?? '') : '';
    };
    $near = static fn(float $a, float $b): bool => abs($a - $b) < 0.01;

    /* ---------- 1. office (default) ---------- */
    echo "-- office settle (default) --\n";
    $OFF = $cod('OFF', $agentId);
    $tot = (float) $OFF['total_amount'];
    $ec  = $expComm($OFF);
    check('the agent earns a commission on this booking at all (so the test means something)', $ec > 0, (string) $ec);
    BookingService::settleCod((int) $OFF['id'], $bossId, 'u13 crew took it');
    $l = $led($OFF);
    check('office settle: payment verified', ($payRow($OFF)['status'] ?? '') === 'verified');
    check('office settle: commission exactly as today (' . inr($ec) . ')', $near($l['commission'], $ec), json_encode($l));
    check('office settle: NO cash_due on the agent', $l['n_cash'] === 0, json_encode($l));
    check('office settle: holder stamped on the payment row', AgentWallet::codHolder((int) $OFF['id']) === 'office', (string) ($payRow($OFF)['gateway_payload'] ?? ''));
    check('office settle: audit row records holder office', $auditHolder($OFF) === 'office');
    check('office settle: sellerHoldsCash() false', AgentWallet::sellerHoldsCash((int) $OFF['id'], Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $OFF['id']]) ?? []) === false);
    check('office settle: syncCashDue() (booking page / ledger fix) adds nothing',
        AgentWallet::syncCashDue(Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $OFF['id']]) ?? [], 'u13') === '' && $led($OFF)['n_cash'] === 0);
    BookingService::settleCod((int) $OFF['id'], $bossId, 'double click', 'agent');
    check('a second settle (even with holder agent) is a no-op - still no cash_due', $led($OFF)['n_cash'] === 0 && $near($led($OFF)['commission'], $ec));

    /* ---------- 2. agent holds ---------- */
    echo "-- agent holder --\n";
    $AG = $cod('AG', $agentId);
    BookingService::settleCod((int) $AG['id'], $bossId, 'u13 agent has it', 'agent');
    $l = $led($AG);
    check('agent holder: commission as today', $near($l['commission'], $expComm($AG)), json_encode($l));
    check('agent holder: cash_due = the fare, as today', $near($l['cash_due'], (float) $AG['total_amount']) && $l['n_cash'] === 1, json_encode($l));
    check('agent holder: holder agent on the payment row + audit', AgentWallet::codHolder((int) $AG['id']) === 'agent' && $auditHolder($AG) === 'agent');

    /* ---------- 3. online claim ---------- */
    echo "-- online (UTR claim) --\n";
    $ON = $cod('ON', $agentId, '7' . random_int(10000000000, 99999999999));
    BookingService::settleCod((int) $ON['id'], $bossId, 'बैंकमा जाँचेँ');
    $l = $led($ON);
    $gp = (string) ($payRow($ON)['gateway_payload'] ?? '');
    check('online claim: commission as today', $near($l['commission'], $expComm($ON)), json_encode($l));
    check('online claim: NO cash_due', $l['n_cash'] === 0, json_encode($l));
    check('online claim: holder online (payment row + audit)', AgentWallet::codHolder((int) $ON['id']) === 'online' && $auditHolder($ON) === 'online', $gp);
    check('online claim: the pay_claim marker keeps its place (ticket still reads ONLINE · eSewa)',
        str_starts_with($gp, '{"pay_claim":{"method":"esewa"') && (CodPayClaim::current((int) $ON['id'])['method'] ?? 'esewa') === 'esewa', $gp);

    /* ---------- 4. chalani ticks ---------- */
    echo "-- chalani tick --\n";
    check('tickHolder: Cash / Office / Agent -> office; UPI / eSewa / Bank -> online',
        OpsHub::tickHolder('Cash') === 'office' && OpsHub::tickHolder('Office') === 'office' && OpsHub::tickHolder('Agent') === 'office'
        && OpsHub::tickHolder('UPI') === 'online' && OpsHub::tickHolder('eSewa') === 'online' && OpsHub::tickHolder('Bank') === 'online');
    $CH = $cod('CH', $agentId);
    $m = OpsHub::markPaid((int) $CH['id'], 'Cash', null, '', $boss);
    $l = $led($CH);
    check('chalani "Cash" tick settles the booking', !empty($m['ok']) && ($payRow($CH)['status'] ?? '') === 'verified', (string) ($m['msg'] ?? ''));
    check('chalani tick = office: commission, no cash_due, no handover', $near($l['commission'], $expComm($CH)) && $l['n_cash'] === 0, json_encode($l));
    check('chalani tick: holder office', AgentWallet::codHolder((int) $CH['id']) === 'office' && $auditHolder($CH) === 'office');
    $CU = $cod('CU', $agentId);
    OpsHub::markPaid((int) $CU['id'], 'UPI', null, '', $boss);
    check('chalani "UPI" tick: holder online, no cash_due', AgentWallet::codHolder((int) $CU['id']) === 'online' && $led($CU)['n_cash'] === 0);

    /* ---------- 5. non-agent COD unchanged ---------- */
    echo "-- non-agent COD --\n";
    $NA = $cod('NA', null);
    BookingService::settleCod((int) $NA['id'], $bossId, 'u13 walk-in');
    check('no seller: nothing on any wallet (as before)', $led($NA)['rows'] === 0);
    $ST = $cod('ST', $bossId);
    BookingService::settleCod((int) $ST['id'], $bossId, 'u13 desk staff sale');
    $ls = $led($ST);
    $agentOnly = Settings::getBool('commission_agent_only', false);
    check('staff (non-agent) seller: ledger exactly as before the rule (cash_due ' . ($agentOnly ? 'none - agent-only switch on' : 'kept') . ')',
        $agentOnly ? $ls['rows'] === 0 : $near($ls['cash_due'], (float) $ST['total_amount']), json_encode($ls));

    /* ---------- 6. the agent's own WhatsApp tool passes 'agent' ---------- */
    $src = (string) file_get_contents(INCLUDE_PATH . '/aitools.php');
    check("agent WhatsApp tool (office_settle_cod as agent) settles with holder 'agent', office path 'office'",
        str_contains($src, "\$asAgent ? 'agent' : 'office'"));
    $osrc = (string) file_get_contents(INCLUDE_PATH . '/opshub.php');
    check('chalani markPaid passes tickHolder($mode)', str_contains($osrc, "self::tickHolder(\$mode)"));

    /* ---------- 7. legacy rows keep the old reading ---------- */
    Database::update('payments', ['gateway_payload' => null], 'booking_id = :b', ['b' => (int) $AG['id']]);
    check('a settled COD with no holder mark (before this rule) still reads as the seller holding cash',
        AgentWallet::codHolder((int) $AG['id']) === '' && AgentWallet::sellerHoldsCash((int) $AG['id'], Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $AG['id']]) ?? []) === true);
    Database::update('payments', ['gateway_payload' => '{"cod_holder":"agent"}'], 'booking_id = :b', ['b' => (int) $AG['id']]);

    /* ---------- 8. HTTP: Payments COD tab ---------- */
    echo "-- Payments COD tab --\n";
    if (!function_exists('curl_init')) {
        check('curl loaded (re-run with -d extension=php_curl.dll)', false);
    } else {
        $H1 = $cod('H1', $agentId);
        $H2 = $cod('H2', $agentId, '8' . random_int(10000000000, 99999999999));
        $H3 = $cod('H3', null);
        $H4 = $cod('H4', $agentId);
        try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
        $login = http('/admin/login.php?classic=1&other=1');
        $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => BOSS, 'password' => BOSS_PW]]);
        check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
        $row = static function (string $body, string $pnr): string {
            return preg_match('/<tr data-pnr="' . preg_quote($pnr, '/') . '".*?<\/tr>/s', $body, $m) === 1 ? $m[0] : '';
        };
        $p1 = http('/admin/payments.php?tab=cod&q=' . urlencode((string) $H1['pnr']));
        $tr = $row($p1['body'], (string) $H1['pnr']);
        check('agent-sold COD row: "कसले लियो · Who has the cash" choice, office preselected',
            $tr !== '' && str_contains($tr, 'name="cod_holder"') && str_contains($tr, 'कसले लियो') && preg_match('/<option value="office" selected>/', $tr) === 1 && str_contains($tr, 'value="agent"'),
            'HTTP ' . $p1['code'] . ' row ' . strlen($tr));
        $p2 = http('/admin/payments.php?tab=cod&q=' . urlencode((string) $H2['pnr']));
        $tr2 = $row($p2['body'], (string) $H2['pnr']);
        check('online-claim row: no holder choice (it is company money)', $tr2 !== '' && !str_contains($tr2, 'name="cod_holder"') && str_contains($tr2, 'Online received'));
        $p3 = http('/admin/payments.php?tab=cod&q=' . urlencode((string) $H3['pnr']));
        $tr3 = $row($p3['body'], (string) $H3['pnr']);
        check('non-agent row: no holder choice', $tr3 !== '' && !str_contains($tr3, 'name="cod_holder"'));
        $csrf = csrfFrom($p1['body']);
        $j = http('/admin/payments.php?tab=cod', ['ajax' => true, 'form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'cod_settle', 'booking_id' => (int) $H1['id'], 'note' => 'u13', 'cod_holder' => 'agent']]);
        $l1 = $led($H1);
        check('POST with एजेन्ट: commission + cash_due on the agent', str_contains($j['body'], '"ok":true') && $near($l1['cash_due'], (float) $H1['total_amount']) && $l1['commission'] > 0, $j['body'] . ' ' . json_encode($l1));
        $j4 = http('/admin/payments.php?tab=cod', ['ajax' => true, 'form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'cod_settle', 'booking_id' => (int) $H4['id'], 'note' => 'u13', 'cod_holder' => 'office']]);
        $l4 = $led($H4);
        check('POST with बस स्टाफ / अफिस: commission only, no cash_due', str_contains($j4['body'], '"ok":true') && $l4['n_cash'] === 0 && $l4['commission'] > 0, $j4['body'] . ' ' . json_encode($l4));
        $j2 = http('/admin/payments.php?tab=cod', ['ajax' => true, 'form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'cod_settle', 'booking_id' => (int) $H2['id'], 'note' => 'u13 bank ok']]);
        check('POST on the claim row: settled online, no cash_due', str_contains($j2['body'], '"ok":true') && $led($H2)['n_cash'] === 0 && AgentWallet::codHolder((int) $H2['id']) === 'online', $j2['body']);
        $H5 = $cod('H5', $agentId, '9' . random_int(10000000000, 99999999999));
        $j5 = http('/admin/payments.php?tab=cod', ['ajax' => true, 'form' => [CSRF_TOKEN_NAME => $csrf, 'action' => 'cod_settle', 'booking_id' => (int) $H5['id'], 'note' => 'u13 crafted', 'cod_holder' => 'agent']]);
        check('refix: a crafted POST cod_holder=agent on a UTR-claim row still settles online - no cash_due on the agent',
            str_contains($j5['body'], '"ok":true') && $led($H5)['n_cash'] === 0 && AgentWallet::codHolder((int) $H5['id']) === 'online', $j5['body'] . ' ' . json_encode($led($H5)));
    }

    /* ---------- 10. refix: the ledger check reads the holder ---------- */
    echo "-- ledger check (cash_mismatch) --\n";
    $since = date('Y-m-d', strtotime('-1 day'));
    $lcIds = static fn(): array => array_map(static fn(array $r): int => (int) $r['booking_id'], LedgerCheck::find('cash_mismatch', $since));
    $ids = $lcIds();
    foreach (['office' => $OFF, 'online claim' => $ON, 'chalani Cash' => $CH, 'chalani UPI' => $CU, 'agent holder' => $AG] as $k => $b) {
        check("ledger check: $k settle is NOT listed as a cash mismatch", !in_array((int) $b['id'], $ids, true));
    }
    if (isset($H4, $H2, $H5)) {
        check('ledger check: Payments-tab office / claim settles not listed', !array_intersect([(int) $H4['id'], (int) $H2['id'], (int) $H5['id']], $ids));
    }
    // A real mismatch is still caught and fixed: the agent-held COD lost its cash_due.
    Database::run("DELETE FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due'", ['b' => (int) $AG['id']]);
    $hit = array_values(array_filter(LedgerCheck::find('cash_mismatch', $since), static fn(array $r): bool => (int) $r['booking_id'] === (int) $AG['id']));
    check('ledger check: agent-held COD with its cash_due missing IS listed (paid in cash)', count($hit) === 1 && ($hit[0]['note'] ?? '') === 'paid in cash', json_encode($hit));
    $fx = LedgerCheck::fix('cash_mismatch', (int) $AG['id']);
    check('ledger fix: puts the cash_due back and the row clears', $fx === '' && $near($led($AG)['cash_due'], (float) $AG['total_amount']), $fx . ' ' . json_encode($led($AG)));
    // A stray cash_due on an office-settled agent COD is listed the other way, and its fix clears it.
    Database::insert('agent_ledger', ['agent_admin_id' => $agentId, 'booking_id' => (int) $OFF['id'], 'account' => 'cash', 'entry_type' => 'cash_due',
        'amount' => (float) $OFF['total_amount'], 'note' => 'u13 stray', 'ref' => (string) $OFF['pnr']]);
    $hit = array_values(array_filter(LedgerCheck::find('cash_mismatch', $since), static fn(array $r): bool => (int) $r['booking_id'] === (int) $OFF['id']));
    check('ledger check: a stray cash_due on an office settle is listed as not a cash sale (office / bus crew)',
        count($hit) === 1 && ($hit[0]['note'] ?? '') === 'not a cash sale' && str_contains((string) ($hit[0]['expected'] ?? ''), 'office / bus crew'), json_encode($hit));
    $fx = LedgerCheck::fix('cash_mismatch', (int) $OFF['id']);
    check('ledger fix: removes the stray cash_due and the row clears', $fx === '' && $led($OFF)['n_cash'] === 0, $fx . ' ' . json_encode($led($OFF)));
    Database::insert('agent_ledger', ['agent_admin_id' => $agentId, 'booking_id' => (int) $ON['id'], 'account' => 'cash', 'entry_type' => 'cash_due',
        'amount' => (float) $ON['total_amount'], 'note' => 'u13 stray', 'ref' => (string) $ON['pnr']]);
    $hit = array_values(array_filter(LedgerCheck::find('cash_mismatch', $since), static fn(array $r): bool => (int) $r['booking_id'] === (int) $ON['id']));
    check('ledger check: a stray cash_due on an online settle reads "paid online to the company"',
        count($hit) === 1 && str_contains((string) ($hit[0]['expected'] ?? ''), 'paid online to the company'), json_encode($hit));
    LedgerCheck::fix('cash_mismatch', (int) $ON['id']);
    check('ledger fix: online settle back to no cash_due', $led($ON)['n_cash'] === 0);

    /* ---------- 11. refix: the agent's WhatsApp tool never takes a UTR-claim row ---------- */
    echo "-- agent WhatsApp tool vs an online claim --\n";
    Settings::set('wa_agent_settle_cod_on', '1', 'bool', 'agent', false);
    Settings::flush();
    $tool = new ReflectionMethod('AiTools', 'officeSettleCod');
    $tool->setAccessible(true);
    $actx = static fn(int $turn, string $msg): array => ['role' => 'staff', 'admin' => ['id' => $agentId, 'role' => 'agent'], 'adminId' => $agentId,
        'scopeAdminId' => $agentId, 'channel' => 'whatsapp', 'via' => 'whatsapp', 'stageKey' => STAGE_KEY, 'turn' => $turn, 'messageText' => $msg, 'name' => 'U13 S2 Agent'];
    $TA = $cod('TA', $agentId);
    $r1 = $tool->invoke(null, ['pnr' => (string) $TA['pnr']], $actx(1, 'cash liye'));
    $r2 = $tool->invoke(null, ['pnr' => (string) $TA['pnr'], 'confirm' => true], $actx(2, 'ho'));
    $l = $led($TA);
    check('agent tool, plain COD: preview + ho records it - holder agent, commission + cash_due (unchanged)',
        !empty($r1['ok']) && !empty($r2['ok']) && AgentWallet::codHolder((int) $TA['id']) === 'agent' && $near($l['cash_due'], (float) $TA['total_amount']) && $l['commission'] > 0,
        json_encode([$r1['say'] ?? '', $r2['say'] ?? '', $l]));
    $TC = $cod('TC', $agentId, '6' . random_int(10000000000, 99999999999));
    $r1 = $tool->invoke(null, ['pnr' => (string) $TC['pnr']], $actx(3, 'cash liye'));
    $r2 = $tool->invoke(null, ['pnr' => (string) $TC['pnr'], 'confirm' => true], $actx(4, 'ho'));
    check('agent tool, UTR-claim row: preview refused (the office checks the bank)', empty($r1['ok']) && str_contains(json_encode($r1, JSON_UNESCAPED_UNICODE), 'online payment'), json_encode($r1));
    check('agent tool, UTR-claim row: confirm refused too', empty($r2['ok']), json_encode($r2));
    check('agent tool, UTR-claim row: payment still cod_pending, no ledger row, no holder', ($payRow($TC)['status'] ?? '') === 'cod_pending' && $led($TC)['rows'] === 0 && AgentWallet::codHolder((int) $TC['id']) === '',
        json_encode([$payRow($TC)['status'] ?? '', $led($TC)]));
    $TL = $cod('TL', $agentId);
    $r1 = $tool->invoke(null, ['pnr' => (string) $TL['pnr']], $actx(5, 'cash liye'));
    $lateUtr = '5' . random_int(10000000000, 99999999999);
    Database::update('payments', ['utr_number' => $lateUtr, 'mode' => 'utr',
        'gateway_payload' => (string) json_encode(['pay_claim' => ['method' => 'upi', 'utr' => $lateUtr, 'at' => date('c')]], JSON_UNESCAPED_SLASHES)], 'booking_id = :b', ['b' => (int) $TL['id']]);
    $r2 = $tool->invoke(null, ['pnr' => (string) $TL['pnr'], 'confirm' => true], $actx(6, 'ho'));
    check('agent tool: a claim that lands between preview and ho is refused at confirm', !empty($r1['ok']) && empty($r2['ok']) && ($payRow($TL)['status'] ?? '') === 'cod_pending' && $led($TL)['n_cash'] === 0,
        json_encode([$r1['ok'] ?? null, $r2]));
    Settings::set('wa_agent_settle_cod_on', (string) ($keep['wa_agent_settle_cod_on']['svalue'] ?? '0'), 'bool', 'agent', false);
    Settings::flush();

    /* ---------- 9. cancel after each: only what was written comes back ---------- */
    echo "-- cancel --\n";
    foreach (['office' => $OFF, 'online' => $ON, 'chalani' => $CH] as $k => $b) {
        BookingService::cancel((string) $b['pnr'], 'u13 cancel', false);
        $l = $led($b);
        check("cancel after $k settle: commission nets to 0 and no cash row - ledger back to zero", $near($l['comm_acct'], 0.0) && $l['n_cash'] === 0 && $near($l['all'], 0.0), json_encode($l));
    }
    BookingService::cancel((string) $AG['pnr'], 'u13 cancel', false);
    $l = $led($AG);
    check('cancel after agent settle: commission nets to 0; the cash the agent took stays for the manual refund (as today)',
        $near($l['comm_acct'], 0.0) && $near($l['cash_acct'], (float) $AG['total_amount']), json_encode($l));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    cleanup();
    @unlink($JAR);
    $sid = (int) ($sched['id'] ?? 0);
    if ($sid > 0) {
        if ($schedMade) {
            if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0) === 0) {
                Database::delete('schedules', 'id = :i', ['i' => $sid]);
            }
        } elseif (is_array($schedKeep)) {
            Database::update('schedules', ['is_blocked' => (int) $schedKeep['is_blocked'], 'status' => (string) $schedKeep['status'],
                'seats_booked' => (int) $schedKeep['seats_booked']], 'id = :i', ['i' => (int) $schedKeep['id']]);
        }
    }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
    $left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE :p", ['p' => PH_PREFIX . '%'], 0);
    check('cleanup: no test booking left', $left === 0, (string) $left);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
