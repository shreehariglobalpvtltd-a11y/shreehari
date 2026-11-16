<?php
/**
 * 16 Nov 2026 — Master v2.0 unit Y5 (agents).
 *
 *   P8-5d  HelpDesk::forSeller() + the Agent Panel "मेरा फलो-अप · My follow-ups"
 *          (switch agent_followups_on, default 0): only the agent's own sales,
 *          never a booking-less ticket, never the message or the phone, and
 *          SERVICE tickets only — never a fake-ticket report, complaint,
 *          staff / safety / payment case that may be about the seller.
 *   P8-12e a cancel at or above helpdesk_cancel_escalate_inr (₹, default 0 = off)
 *          on which a refund is OWED opens ONE 'refund' help-desk ticket — no
 *          customer message, no duplicate, never breaks the cancel; an unpaid,
 *          auto, undo or 0%-slab cancel opens nothing.
 *   P8-6c  an agent records on WhatsApp the cash on their OWN pay-at-boarding
 *          booking (office_settle_cod, switch wa_agent_settle_cod_on, default
 *          0): two-step, own COD only, and the cash lands on the agent's
 *          wallet as cash owed to the office.
 *
 * Seeds two temp agents, a temp superadmin and a handful of far-future
 * bookings (TD below), and removes every row it made.
 *
 *   SHG_TEST_BASE=http://localhost:8985 php -c <ini> tests/u11-Y5-agents-test.php
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
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/quickunlock.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8985'), '/'));
const TD       = '2099-11-07';
const LIKE     = '91000115';          // every phone below starts with this
const PH_AG_A  = '9100011501';
const PH_AG_B  = '9100011502';
const PH_BOSS  = '9100011503';
const PH_LONE  = '9100011519';        // a number with no booking at all
const AG_A     = 'u11_y5_agent_a';
const AG_B     = 'u11_y5_agent_b';
const BOSS     = 'u11_y5_boss';
const PW       = 'U11Y5Agents77x';
const REFUSAL  = 'This assistant cannot do that. Tell the person to call the office for it.';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* ---- HTTP (one cookie jar per person) --------------------------------- */
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
function locationOf(array $r): string {
    return preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m) === 1 ? $m[1] : '';
}
/** The follow-ups panel's own HTML, or '' when it is not on the page. */
function panelOf(string $html): string {
    $i = strpos($html, 'id="followupsBox"');
    if ($i === false) { return ''; }
    $j = strpos($html, '<div class="panel" data-pane="sales balance">', $i);
    return substr($html, $i, $j === false ? 6000 : $j - $i);
}

/* ---- settings this test turns, put back exactly as found ---------------- */
$keys = ['agent_followups_on', 'helpdesk_cancel_escalate_inr', 'wa_agent_settle_cod_on', 'wa_agent_admin_write', 'wa_agent_oneshot',
         'wa_ops_stepup_on', 'wa_ops_stepup_actions', 'wa_login_on', 'whatsapp_notify_customer', 'whatsapp_notify_admin', 'whatsapp_driver',
         'sms_enabled', 'allow_cod', 'agent_wallet_enabled', 'staff_seats', 'helpdesk_ack', 'helpdesk_office_alert'];
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
$sw = static function (string $k, mixed $v, string $type = 'bool', string $group = 'ai'): void {
    Settings::set($k, $v, $type, $group, false);
    Settings::flush();
};

$cleanup = static function (): void {
    $adminIds = array_map('intval', array_column(Database::fetchAll('SELECT id FROM admins WHERE username IN (:a, :b, :c)', ['a' => AG_A, 'b' => AG_B, 'c' => BOSS]), 'id'));
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . LIKE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (Database::fetchAll('SELECT id FROM support_tickets WHERE booking_id = :b', ['b' => $bid]) as $t) {
            Database::delete('support_tickets', 'id = :i', ['i' => (int) $t['id']]);   // support_messages cascade
        }
        foreach (['agent_ledger', 'message_logs', 'ai_agent_calls', 'notifications'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => $bid]);                         // legs / seats / pax / payments / tickets cascade
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('support_tickets', "phone LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('ai_agent_calls', "phone LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn') AND kkey LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('wa_identity_links', "phone LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . LIKE . "%'", []); } catch (Throwable $e) {}
    foreach ($adminIds as $aid) {
        try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_sessions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :a', ['a' => $aid]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => TD]) as $s) {
        try { Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
        try { Database::delete('booking_seats', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
};

$jars = [];
echo "\n=== Y5 agents: follow-ups, cancel follow-up ticket, agent cash on WhatsApp ===\n\n";
$cleanup();
try {
    /* ---- 0. the migration ------------------------------------------------ */
    echo "== migration ==\n";
    $mig = (string) @file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-11-16d-Y5-agents.sql');
    foreach (['agent_followups_on' => ['bool', 'agent'], 'helpdesk_cancel_escalate_inr' => ['int', 'helpdesk'], 'wa_agent_settle_cod_on' => ['bool', 'ai']] as $k => [$t, $g]) {
        $row = Database::fetch('SELECT svalue, stype, sgroup, label FROM settings WHERE skey = :k', ['k' => $k]);
        check("migration seeds $k OFF (0, $t, $g, bilingual label)", $row !== null && (string) $keep[$k]['svalue'] === '0'
            && $row['stype'] === $t && $row['sgroup'] === $g && str_contains((string) $row['label'], ' · ') && mb_strlen((string) $row['label']) <= 191,
            $row === null ? 'missing — apply the migration' : $row['svalue'] . '/' . $row['stype'] . '/' . $row['sgroup']);
    }
    check('migration is INSERT IGNORE (safe to re-run)', str_contains($mig, 'INSERT IGNORE INTO settings') && !preg_match('/\b(UPDATE|DELETE|DROP)\b/', preg_replace('/^--.*$/m', '', $mig) ?? ''));

    /* ---- fixtures ---------------------------------------------------------- */
    $sw('agent_followups_on', '0', 'bool', 'agent');
    $sw('helpdesk_cancel_escalate_inr', '0', 'int', 'helpdesk');
    $sw('wa_agent_settle_cod_on', '0');
    $sw('wa_agent_admin_write', '0');
    $sw('wa_agent_oneshot', '0');
    $sw('wa_ops_stepup_on', '0');
    $sw('wa_login_on', '0');
    $sw('whatsapp_driver', 'click_to_chat', 'string', 'notify');   // journal only, no network
    $sw('whatsapp_notify_customer', '1', 'bool', 'notify');         // a customer message WOULD be journaled
    $sw('whatsapp_notify_admin', '0', 'bool', 'notify');
    $sw('sms_enabled', '0', 'bool', 'notify');
    $sw('allow_cod', '1', 'bool', 'payment');
    $sw('agent_wallet_enabled', '1', 'bool', 'agent');
    $sw('helpdesk_ack', '1', 'bool', 'helpdesk');                   // the follow-up must stay silent even with acks ON
    $sw('helpdesk_office_alert', '0', 'bool', 'helpdesk');          // the urgent fake / safety fixtures below alert nobody
    $sw('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats');

    $mkAdmin = static function (string $user, string $name, string $role, string $phone): int {
        $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash(PW, PASSWORD_DEFAULT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
        QuickUnlock::set($id, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
        return $id;
    };
    $A    = $mkAdmin(AG_A, 'U11 Agent A', 'agent', PH_AG_A);
    $B    = $mkAdmin(AG_B, 'U11 Agent B', 'agent', PH_AG_B);
    $boss = $mkAdmin(BOSS, 'U11 Boss', 'superadmin', PH_BOSS);

    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 AND to_city IN ('Nepalgunj','Rupaidiha','Kohalpur') ORDER BY id LIMIT 1")
          ?? Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    check('a sleeper route to sell on', $route !== null);
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $free = array_values(array_filter(Seats::seatIds('sleeper', 'sharing'), static fn(string $s): bool => !in_array($s, ['L1', 'L2', 'L3', 'L4', 'L5', 'L6'], true)));
    $seatN = 0;
    $mk = static function (string $phone, bool $cod, ?int $soldBy, string $name) use ($routeId, $free, &$seatN): array {
        $b = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => [$free[$seatN++]],
            'passengers' => [['name' => $name, 'gender' => 'Male', 'age' => 30]],
            'contact' => ['phone' => $phone], 'bookingMode' => 'sharing', 'cabinType' => 'single',
            'isCod' => $cod, 'paymentMethod' => $cod ? 'cod' : 'upi']);
        if ($soldBy !== null) {   // an agent-coded sale: the customer booked with the agent's code
            Database::update('bookings', ['sold_by_admin_id' => $soldBy], 'id = :i', ['i' => (int) $b['id']]);
        }
        return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]) ?? [];
    };
    $payOf = static fn(array $b): string => (string) Database::scalar('SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $b['id']], '');

    $bA1 = $mk('9100011511', true,  $A, 'U11 Own Cod');        // A's own pay-at-boarding sale
    $bB1 = $mk('9100011512', true,  $B, 'U11 Other Agent');    // B's sale
    $bA2 = $mk('9100011513', false, $A, 'U11 Own Online');     // A's sale, online payment pending
    $bA3 = $mk('9100011514', true,  $A, 'U11 Odd Pending');    // A's COD whose payment row reads 'pending'
    Database::run("UPDATE payments SET status = 'pending' WHERE booking_id = :b", ['b' => (int) $bA3['id']]);
    $bA4 = $mk('9100011520', true,  $A, 'U11 Gate Cod');       // for the step-up gate
    check('seeded: A\'s COD booking is confirmed, payment cod_pending, sold by A',
        $bA1['status'] === 'confirmed' && $payOf($bA1) === 'cod_pending' && (int) $bA1['sold_by_admin_id'] === $A, $bA1['pnr'] ?? '?');
    check('seeded: A\'s online booking waits for payment (pending)', $bA2['status'] === 'pending' && $payOf($bA2) === 'pending' && (int) $bA2['is_cod'] === 0);

    /* =====================================================================
     *  1. HelpDesk::forSeller (P8-5d)
     * ===================================================================== */
    echo "\n== forSeller ==\n";
    $open = static fn(array $in): array => HelpDesk::open($in + ['trusted' => true, 'ack' => false, 'source' => 'admin', 'lang' => 'ne']);
    $t1 = $open(['phone' => '9100011511', 'pnr' => $bA1['pnr'], 'category' => 'seat', 'message' => 'U11 SECRET body words one']);
    $t5 = $open(['phone' => '9100011511', 'pnr' => $bA1['pnr'], 'category' => 'booking_help', 'message' => 'U11 SECRET body words five']);
    Database::update('support_tickets', ['status' => 'in_progress'], 'id = :i', ['i' => (int) $t5['id']]);
    $t4 = $open(['phone' => '9100011511', 'pnr' => $bA1['pnr'], 'category' => 'other', 'message' => 'U11 SECRET body words four']);
    Database::update('support_tickets', ['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $t4['id']]);
    $t2 = $open(['phone' => '9100011512', 'pnr' => $bB1['pnr'], 'category' => 'booking_help', 'message' => 'U11 SECRET body words two']);
    $t3 = $open(['phone' => PH_LONE, 'category' => 'other', 'message' => 'U11 SECRET body words three']);
    check('tickets opened (2 open + 1 resolved on A\'s sale, 1 on B\'s, 1 with no booking)',
        !empty($t1['ok']) && !empty($t2['ok']) && !empty($t3['ok']) && !empty($t4['ok']) && !empty($t5['ok']));
    check('the booking-less ticket really has no booking',
        Database::scalar('SELECT booking_id FROM support_tickets WHERE id = :i', ['i' => (int) $t3['id']], 'x') === null);

    $fa = HelpDesk::forSeller($A);
    $fb = HelpDesk::forSeller($B);
    $refsA = array_column($fa, 'ticket_ref');
    check('A sees the open and in-progress tickets on their own sale', in_array($t1['ref'], $refsA, true) && in_array($t5['ref'], $refsA, true), implode(',', $refsA));
    check('A never sees B\'s ticket', !in_array($t2['ref'], $refsA, true));
    check('A never sees the booking-less ticket', !in_array($t3['ref'], $refsA, true));
    check('A never sees a resolved ticket', !in_array($t4['ref'], $refsA, true));
    check('B sees only B\'s own', array_column($fb, 'ticket_ref') === [$t2['ref']], implode(',', array_column($fb, 'ticket_ref')));
    $keysOk = true; $leak = false;
    foreach (array_merge($fa, $fb) as $row) {
        $keysOk = $keysOk && array_keys($row) === ['ticket_ref', 'category', 'status', 'due_at', 'pnr'];
        $flat = json_encode($row, JSON_UNESCAPED_UNICODE);
        $leak = $leak || str_contains((string) $flat, 'U11 SECRET') || str_contains((string) $flat, '91000115');
    }
    check('rows carry ref, category, status, due, PNR — nothing else', $keysOk && $fa !== []);
    check('no message body and no phone in any row', !$leak);
    $r1 = array_values(array_filter($fa, static fn(array $r): bool => $r['ticket_ref'] === $t1['ref']))[0] ?? [];
    check('the row names the PNR and the due time', ($r1['pnr'] ?? '') === $bA1['pnr'] && ($r1['status'] ?? '') === 'open'
        && (!HelpDesk::ready() || ($r1['due_at'] ?? null) !== null), json_encode($r1));
    check('forSeller(0) and a limit of 1', HelpDesk::forSeller(0) === [] && count(HelpDesk::forSeller($A, 1)) === 1);

    /* Cases that may be ABOUT the seller never reach the accused agent: a
       passenger's fake-ticket report on A's sale (verify page), a staff
       complaint, safety, payment, other, and the AI desk's complaint /
       payment dispute / refund exception (AiHandoff). */
    $about = [];
    foreach (['fake_ticket', 'staff', 'safety', 'payment', 'other'] as $i => $cat) {
        $about[$cat] = $open(['phone' => '9100011511', 'pnr' => $bA1['pnr'], 'category' => $cat, 'message' => 'U11 SECRET about the seller ' . $i]);
    }
    foreach (['complaint', 'payment_dispute', 'refund_exception'] as $i => $cat) {
        $x = $open(['phone' => '9100011511', 'pnr' => $bA1['pnr'], 'category' => 'other', 'message' => 'U11 SECRET ai desk case ' . $i]);
        Database::update('support_tickets', ['category' => $cat], 'id = :i', ['i' => (int) ($x['id'] ?? 0)]);
        $about[$cat] = $x;
    }
    $linked = true;
    foreach ($about as $x) {
        $linked = $linked && !empty($x['ok']) && (int) Database::scalar('SELECT COALESCE(booking_id, 0) FROM support_tickets WHERE id = :i', ['i' => (int) $x['id']], 0) === (int) $bA1['id'];
    }
    check('8 cases about the seller opened and linked to A\'s own sale', $linked && count($about) === 8);
    $refsA = array_column(HelpDesk::forSeller($A), 'ticket_ref');
    $shown = array_keys(array_filter($about, static fn(array $x): bool => in_array((string) ($x['ref'] ?? '-'), $refsA, true)));
    check('A never sees a fake-ticket report, complaint, staff, safety, payment, other or AI-desk case on their sale', $shown === [], implode(',', $shown));
    check('  the service tickets on the same sale still show', in_array($t1['ref'], $refsA, true) && in_array($t5['ref'], $refsA, true));
    check('  the allow-list is service work only', array_intersect(HelpDesk::SELLER_CATEGORIES,
        ['fake_ticket', 'staff', 'safety', 'payment', 'other', 'complaint', 'payment_dispute', 'refund_exception', 'identity', 'document_request', 'policy_exception', 'approval']) === []
        && in_array('refund', HelpDesk::SELLER_CATEGORIES, true) && in_array('seat', HelpDesk::SELLER_CATEGORIES, true));

    /* =====================================================================
     *  2. The Agent Panel (HTTP) — hidden with 0, shown with 1
     * ===================================================================== */
    echo "\n== agent panel (HTTP) ==\n";
    $jars['a'] = sys_get_temp_dir() . '/shg_u11y5_a_' . getmypid() . '.txt';
    $jars['b'] = sys_get_temp_dir() . '/shg_u11y5_boss_' . getmypid() . '.txt';
    $r = signIn($jars['a'], AG_A);
    check('agent A signs in', $r['code'] === 302 && !str_contains(locationOf($r), 'login.php'), 'HTTP ' . $r['code'] . ' -> ' . locationOf($r));
    $pg = http($jars['a'], '/admin/agent.php?tab=sales');
    check('agent panel opens for A', $pg['code'] === 200, 'HTTP ' . $pg['code'] . ' -> ' . locationOf($pg));
    check('switch 0: no follow-ups panel', panelOf($pg['body']) === '' && !str_contains($pg['body'], 'My follow-ups'));

    $sw('agent_followups_on', '1', 'bool', 'agent');
    $pg = http($jars['a'], '/admin/agent.php?tab=sales');
    $pan = panelOf($pg['body']);
    check('switch 1: the panel is there, bilingual heading', $pg['code'] === 200 && $pan !== '' && str_contains($pan, 'मेरा फलो-अप · My follow-ups'), 'HTTP ' . $pg['code']);
    check('  lists A\'s two open tickets with their PNR link', str_contains($pan, (string) $t1['ref']) && str_contains($pan, (string) $t5['ref'])
        && str_contains($pan, '/admin/booking-view.php?pnr=' . urlencode((string) $bA1['pnr'])));
    check('  category label Nepali · English, status bilingual', str_contains($pan, HelpDesk::label('seat', 'ne')) && str_contains($pan, HelpDesk::label('seat'))
        && str_contains($pan, 'खुला · Open') && str_contains($pan, 'हेर्दैछ · In progress'));
    check('  never B\'s, the booking-less or the resolved ticket', !str_contains($pan, (string) $t2['ref']) && !str_contains($pan, (string) $t3['ref'])
        && !str_contains($pan, (string) $t4['ref']) && !str_contains($pan, (string) $bB1['pnr']));
    check('  no message body and no phone in the panel', !str_contains($pan, 'U11 SECRET') && !str_contains($pan, '91000115'));
    $leakRefs = array_filter($about, static fn(array $x): bool => str_contains($pan, (string) ($x['ref'] ?? '-')));
    check('  no fake-ticket / complaint / staff / safety / payment case in the panel', $leakRefs === []
        && !str_contains($pan, HelpDesk::label('fake_ticket', 'ne')) && !str_contains($pan, HelpDesk::label('fake_ticket'))
        && !str_contains($pan, HelpDesk::label('staff', 'ne')), implode(',', array_keys($leakRefs)));
    check('  a phone-width card table (data-label on every cell)', str_contains($pan, 'class="card-table"') && substr_count($pan, 'data-label=') >= 10);
    check('  shown on the home (balance) tab too', str_contains($pan, 'data-pane="sales balance"'));
    $bv = http($jars['a'], '/admin/booking-view.php?pnr=' . urlencode((string) $bA1['pnr']));
    check('the PNR link opens for the agent (own sale)', $bv['code'] === 200, 'HTTP ' . $bv['code'] . ' -> ' . locationOf($bv));
    $pg = http($jars['a'], '/admin/agent.php?agent=' . $B . '&tab=sales');
    $pan = panelOf($pg['body']);
    check('?agent=<B> does not let A read B\'s follow-ups', !str_contains($pan, (string) $t2['ref']) && str_contains($pan, (string) $t1['ref']));

    $r = signIn($jars['b'], BOSS);
    check('the office (superadmin) signs in', $r['code'] === 302 && !str_contains(locationOf($r), 'login.php'), 'HTTP ' . $r['code'] . ' -> ' . locationOf($r));
    $pg  = http($jars['b'], '/admin/agent.php?agent=' . $A . '&tab=sales');
    $pan = panelOf($pg['body']);
    check('office viewing agent A sees A\'s follow-ups', $pg['code'] === 200 && str_contains($pan, (string) $t1['ref']) && str_contains($pan, 'Agent follow-ups')
        && !str_contains($pan, (string) $t2['ref']), 'HTTP ' . $pg['code']);
    $sw('agent_followups_on', '0', 'bool', 'agent');
    $pg = http($jars['b'], '/admin/agent.php?agent=' . $A . '&tab=sales');
    check('switch back to 0: hidden for the office too', $pg['code'] === 200 && panelOf($pg['body']) === '');

    /* =====================================================================
     *  3. Cancel follow-up ticket (P8-12e)
     * ===================================================================== */
    echo "\n== cancel follow-up ==\n";
    $hd = static fn(array $b): array => Database::fetchAll("SELECT * FROM support_tickets WHERE booking_id = :b AND category = 'refund'", ['b' => (int) $b['id']]);
    $msgsTo = static fn(string $ph, int $after): int => (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number LIKE :p AND id > :i",
        ['p' => '%' . $ph, 'i' => $after], 0);
    $maxMsg = static fn(): int => (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);

    /* A refund is owed only on money COLLECTED: a COD sale whose cash came
       in (settleCod -> payment 'verified'), cancelled far ahead of travel. */
    $paid = static function (array $b) use ($boss): array {
        BookingService::settleCod((int) $b['id'], $boss, 'u11 cash in');
        return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]) ?? [];
    };
    $rowOf = static fn(array $b): array => Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]) ?? [];

    $c0 = $paid($mk('9100011515', true, $A, 'U11 Cancel Zero'));
    $total = (float) $c0['total_amount'];
    $m0 = $maxMsg();
    BookingService::cancel((string) $c0['pnr'], 'u11 threshold off', false);
    $sent0 = $msgsTo('9100011515', $m0);
    check('threshold 0: a cancel opens no ticket (even with a refund owed)', $hd($c0) === [] && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $c0['id']]) === 'cancelled'
        && (string) ($rowOf($c0)['refund_status'] ?? '') === 'pending');

    $sw('helpdesk_cancel_escalate_inr', (string) (int) floor($total), 'int', 'helpdesk');
    $c1 = $paid($mk('9100011516', true, $A, 'U11 Cancel Big'));
    $m1 = $maxMsg();
    $res = BookingService::cancel((string) $c1['pnr'], 'u11 threshold on', false);
    $t = $hd($c1);
    check('at the threshold, paid: the cancel still succeeds with a refund owed', (string) ($res['booking']['status'] ?? '') === 'cancelled'
        && (float) ($res['refund']['amount'] ?? 0) > 0, inr((float) ($res['refund']['amount'] ?? 0)));
    check('  and one refund ticket is opened for the booking', count($t) === 1 && $t[0]['status'] === 'open' && (int) $t[0]['booking_id'] === (int) $c1['id'], (string) count($t));
    $tk = $t[0] ?? [];
    check('  message "Cancelled ticket above ₹X - refund follow-up"', (string) ($tk['message'] ?? '') === 'Cancelled ticket above ' . inr((int) floor($total)) . ' - refund follow-up', (string) ($tk['message'] ?? ''));
    check('  opened by staff (source admin), with the customer\'s number', ($tk['source'] ?? '') === 'admin' && str_ends_with((string) ($tk['phone'] ?? ''), '9100011516'));
    check('  no acknowledgement sent to the customer', array_key_exists('ack_sent_at', $tk) && $tk['ack_sent_at'] === null
        && (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE purpose = 'support_ack' AND to_number LIKE '%9100011516'", [], 0) === 0);
    check('  no extra customer message: the same count as a paid cancel without the follow-up', $msgsTo('9100011516', $m1) === $sent0, $msgsTo('9100011516', $m1) . ' vs ' . $sent0);
    $c1row = $rowOf($c1);
    check('  refunds stay manual: the refund waits as pending, nothing marked paid', (string) ($c1row['refund_status'] ?? '') === 'pending'
        && abs((float) $c1row['refund_amount'] - (float) $res['refund']['amount']) < 0.01);
    check('  the audit row carries the refund due', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'support.cancel_followup' AND entity_id = :p AND new_value LIKE '%refund_due%'",
        ['p' => (string) $c1['pnr']]));
    check('no duplicate: a second call while it is open opens nothing', HelpDesk::cancelFollowUp($c1row) === null && count($hd($c1)) === 1);
    check('the follow-up shows in the selling agent\'s follow-ups', in_array((string) ($tk['ticket_ref'] ?? '-'), array_column(HelpDesk::forSeller($A), 'ticket_ref'), true));
    Database::update('support_tickets', ['status' => 'resolved'], 'id = :i', ['i' => (int) ($tk['id'] ?? 0)]);
    $again = HelpDesk::cancelFollowUp($c1row);
    check('once resolved, a new follow-up may be opened (only OPEN ones block)', $again !== null && count($hd($c1)) === 2, (string) $again);

    // An earlier refund already PAID back ('processed'): only this cancel's own
    // remainder ("settle by hand") is owed; with none, nothing opens.
    Database::update('support_tickets', ['status' => 'resolved'], "booking_id = :b AND category = 'refund'", ['b' => (int) $c1['id']]);
    $done = ['refund_status' => 'processed'] + $c1row;
    check('refund already processed and no remainder: nothing opens', HelpDesk::cancelFollowUp($done, ['amount' => 0.0]) === null && count($hd($c1)) === 2);
    check('refund already processed, a remainder owed: one opens', HelpDesk::cancelFollowUp($done, ['amount' => 150.0]) !== null && count($hd($c1)) === 3);
    check('refundOwed: pending row, processed remainder, nothing', HelpDesk::refundOwed(['refund_status' => 'pending', 'refund_amount' => '500.00']) === 500.0
        && HelpDesk::refundOwed(['refund_status' => 'processed', 'refund_amount' => '500.00'], ['amount' => 120]) === 120.0
        && HelpDesk::refundOwed(['refund_status' => 'none', 'refund_amount' => '0.00'], ['amount' => 0.0]) === 0.0
        && HelpDesk::refundOwed(['refund_status' => 'processed', 'refund_amount' => '500.00']) === 0.0);

    /* Nothing collected = nothing owed = no refund ticket, no office alert,
       nothing on the agent's follow-ups (review 16 Nov: an unpaid COD cancel
       at the threshold used to open a high-priority 'refund' ticket). */
    $u1 = $mk('9100011522', true, $A, 'U11 Unpaid Desk');
    check('fixture: an unpaid pay-at-boarding sale at the threshold', $payOf($u1) === 'cod_pending' && (float) $u1['total_amount'] >= floor($total));
    $ru = BookingService::cancel((string) $u1['pnr'], 'u11 unpaid desk cancel', false);
    check('unpaid COD cancelled at the threshold: no refund ticket', (string) ($ru['booking']['status'] ?? '') === 'cancelled'
        && (float) ($ru['refund']['amount'] ?? -1) === 0.0 && $hd($u1) === [] && (string) ($rowOf($u1)['refund_status'] ?? '') === 'none');
    check('  and a direct call on that row opens nothing either', HelpDesk::cancelFollowUp($rowOf($u1), $ru['refund'] ?? null) === null && $hd($u1) === []);
    $u2 = $mk('9100011523', true, $A, 'U11 Unpaid Auto');
    BookingService::cancel((string) $u2['pnr'], 'Cash not paid 60 min before boarding (auto)', false, 'auto');
    check('the cron\'s "cash not paid" auto-cancel: no refund ticket', $hd($u2) === [] && (string) ($rowOf($u2)['status'] ?? '') === 'cancelled');
    $u3 = $mk('9100011524', true, $A, 'U11 Unpaid Undo');
    BookingService::cancel((string) $u3['pnr'], 'u11 undo', true, 'undo');
    check('a QuickBot undo (nothing to refund): no refund ticket', $hd($u3) === [] && (string) ($rowOf($u3)['status'] ?? '') === 'cancelled');
    $u4 = $paid($mk('9100011525', true, $A, 'U11 Late Zero Slab'));
    Database::update('booking_legs', ['travel_date' => date('Y-m-d', strtotime('-1 day'))], 'booking_id = :b', ['b' => (int) $u4['id']]);
    $r4 = BookingService::cancel((string) $u4['pnr'], 'u11 late cancel', false);
    check('a paid ticket cancelled too late (0% slab, refund ₹0): no refund ticket', (float) ($r4['refund']['amount'] ?? -1) === 0.0 && $hd($u4) === [],
        inr((float) ($r4['refund']['amount'] ?? -1)));
    $noneRefs = array_column(Database::fetchAll('SELECT ticket_ref FROM support_tickets WHERE booking_id IN (:a, :b, :c, :d)',
        ['a' => (int) $u1['id'], 'b' => (int) $u2['id'], 'c' => (int) $u3['id'], 'd' => (int) $u4['id']]), 'ticket_ref');
    check('none of those opened any help-desk ticket at all', $noneRefs === [], implode(',', $noneRefs));

    // one number, two big PAID tickets cancelled minutes apart: each keeps its own follow-up
    $c4 = $paid($mk('9100011521', true, $A, 'U11 Same Phone One'));
    $c5 = $paid($mk('9100011521', true, $A, 'U11 Same Phone Two'));
    BookingService::cancel((string) $c4['pnr'], 'u11 same phone 1', false);
    BookingService::cancel((string) $c5['pnr'], 'u11 same phone 2', false);
    $t4s = $hd($c4); $t5s = $hd($c5);
    check('same number, two cancels: one ticket each, never moved between bookings', count($t4s) === 1 && count($t5s) === 1
        && (int) $t4s[0]['id'] !== (int) $t5s[0]['id'], count($t4s) . '/' . count($t5s));

    $sw('helpdesk_cancel_escalate_inr', (string) ((int) floor($total) + 1), 'int', 'helpdesk');
    $c2 = $paid($mk('9100011517', true, $A, 'U11 Cancel Small'));
    BookingService::cancel((string) $c2['pnr'], 'u11 below threshold', false);
    check('below the threshold (paid, refund owed): no ticket', $hd($c2) === [] && (string) ($rowOf($c2)['refund_status'] ?? '') === 'pending');

    $sw('helpdesk_cancel_escalate_inr', '1', 'int', 'helpdesk');
    $c3 = $paid($mk('9100011518', true, null, 'U11 Cancel Bad Phone'));
    Database::update('bookings', ['contact_phone' => '12'], 'id = :i', ['i' => (int) $c3['id']]);
    $ok3 = true;
    try { BookingService::cancel((string) $c3['pnr'], 'u11 helpdesk refuses', false); } catch (Throwable $e) { $ok3 = false; }
    check('a help-desk refusal (bad number, refund owed) never breaks the cancel', $ok3 && Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $c3['id']]) === 'cancelled'
        && $hd($c3) === [] && (string) ($rowOf($c3)['refund_status'] ?? '') === 'pending');
    Database::update('bookings', ['contact_phone' => '9100011518'], 'id = :i', ['i' => (int) $c3['id']]);   // so cleanup finds it
    $sw('helpdesk_cancel_escalate_inr', '0', 'int', 'helpdesk');

    /* =====================================================================
     *  4. Agent records cash on WhatsApp (P8-6c)
     * ===================================================================== */
    echo "\n== agent cash on WhatsApp ==\n";
    $ctx = static function (string $phone, int $turn, string $text, string $channel = 'whatsapp'): array {
        $c = AiTools::whoIs($phone);
        $c['turn'] = $turn; $c['channel'] = $channel; $c['messageText'] = $text;
        return $c;
    };
    $names = static fn(array $c): array => array_column(AiTools::catalogue($c), 'name');
    $cA = $ctx(PH_AG_A, 1, 'cash liye');
    check('A writes as a scoped seller', $cA['role'] === 'staff' && (int) $cA['adminId'] === $A && (int) $cA['scopeAdminId'] === $A, $cA['role']);
    check('switch 0: the tool is not on the agent\'s list', !in_array('office_settle_cod', $names($cA), true));
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'confirm' => true], $cA);
    check('switch 0: refused like any tool not on the list', $r['ok'] === false && $r['say'] === REFUSAL && $payOf($bA1) === 'cod_pending');

    $sw('wa_agent_settle_cod_on', '1');
    check('switch 1: the tool is on the agent\'s WhatsApp list', in_array('office_settle_cod', $names($cA), true));
    check('  never on the website chat', !in_array('office_settle_cod', $names($ctx(PH_AG_A, 1, 'x', 'web')), true));
    check('  and the office still needs wa_agent_admin_write (off)', !in_array('office_settle_cod', $names($ctx(PH_BOSS, 1, 'x')), true));
    $r = AiTools::run('office_settle_cod', ['pnr' => $bB1['pnr']], $cA);
    check('another agent\'s booking is refused, nothing revealed', $r['ok'] === false && str_contains($r['say'], 'not one of your own sales') && $r['data'] === [] && $payOf($bB1) === 'cod_pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA2['pnr']], $cA);
    check('a non-COD (online pending) own booking is refused', $r['ok'] === false && str_contains($r['say'], 'not a pay-at-boarding') && $payOf($bA2) === 'pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA3['pnr']], $cA);
    check('a COD booking whose payment is not cash-on-boarding is refused', $r['ok'] === false && str_contains($r['say'], 'no cash-on-boarding fare') && $payOf($bA3) === 'pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr']], $ctx(PH_AG_B, 1, 'cash liye'));
    check('agent B cannot record cash on A\'s booking', $r['ok'] === false && str_contains($r['say'], 'not one of your own sales'));
    $r = AiTools::run('office_settle_cod', ['pnr' => $u1['pnr']], $cA);   // A's unpaid COD sale, cancelled above
    check('a cancelled own COD booking is refused (no cash due)', $r['ok'] === false && $payOf($u1) !== 'verified', $r['say']);

    $ledger = static fn(array $b): array => Database::fetchAll('SELECT agent_admin_id, account, entry_type, amount FROM agent_ledger WHERE booking_id = :b ORDER BY id', ['b' => (int) $b['id']]);
    check('before: nothing on the wallet for A\'s COD sale (create defers it)', $ledger($bA1) === []);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'note' => 'at Surat'], $cA);
    check('step 1: preview only — the booking card, nothing recorded', $r['ok'] === true && ($r['data']['pnr'] ?? '') === $bA1['pnr'] && $payOf($bA1) === 'cod_pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'confirm' => true], $ctx(PH_AG_A, 1, 'ho'));
    check('a confirm in the SAME message is refused', $r['ok'] === false && $payOf($bA1) === 'cod_pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'confirm' => true], $ctx(PH_AG_A, 2, 'kati ho?'));
    check('a confirm flag without a plain ho is refused', $r['ok'] === false && str_contains($r['say'], 'not replied ho') && $payOf($bA1) === 'cod_pending', $r['say']);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA4['pnr'], 'confirm' => true], $ctx(PH_AG_A, 2, 'ho'));
    check('a ho naming another own PNR does not press either (the pin is for one PNR)', $r['ok'] === false && str_contains($r['say'], 'Preview this exact PNR')
        && $payOf($bA1) === 'cod_pending' && $payOf($bA4) === 'cod_pending', $r['say']);
    // that refusal used up the pin; preview again, then ho
    $cashBefore = (float) (AgentWallet::balances($A)['cash'] ?? 0);   // the paid cancels above already hold some
    AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'note' => 'at Surat'], $ctx(PH_AG_A, 3, 'cash liye'));
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'confirm' => true], $ctx(PH_AG_A, 4, 'ho'));
    check('step 2: ho in a later message records the cash', $r['ok'] === true && $payOf($bA1) === 'verified', $r['say']);
    check('  the payment is verified BY the agent', (int) Database::scalar('SELECT verified_by FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $bA1['id']], 0) === $A);
    $rows = $ledger($bA1);
    $byType = [];
    foreach ($rows as $l) { $byType[$l['entry_type']] = $l; }
    $tot = (float) $bA1['total_amount'];
    check('  cash_due = the fare, on A\'s cash account (cash A owes the office)', isset($byType['cash_due']) && (int) $byType['cash_due']['agent_admin_id'] === $A
        && $byType['cash_due']['account'] === 'cash' && abs((float) $byType['cash_due']['amount'] - $tot) < 0.01, json_encode($rows));
    $expComm = AgentWallet::commissionForBooking($tot, $A, 1, (string) $bA1['created_at']);
    check('  commission exactly as a desk cash sale by A earns it (' . inr($expComm) . ')', $expComm > 0
        ? (isset($byType['commission']) && (int) $byType['commission']['agent_admin_id'] === $A && abs((float) $byType['commission']['amount'] - $expComm) < 0.01)
        : !isset($byType['commission']), json_encode($rows));
    check('  nothing posted to anyone else', array_unique(array_map('intval', array_column($rows, 'agent_admin_id'))) === [$A]);
    $bal = AgentWallet::balances($A);
    check('  A\'s wallet: cash held grows by exactly the fare', abs((float) ($bal['cash'] ?? 0) - $cashBefore - $tot) < 0.01, inr($cashBefore) . ' -> ' . json_encode($bal));
    check('  audit row names the agent', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'booking.cod.settle_agent' AND entity_id = :p AND detail LIKE :n",
        ['p' => $bA1['pnr'], 'n' => '%agent #' . $A . '%'], 0) === 1);
    $r = AiTools::run('office_settle_cod', ['pnr' => $bA1['pnr'], 'confirm' => true], $ctx(PH_AG_A, 5, 'ho'));
    check('a repeat ho records nothing twice', $r['ok'] === false && count($ledger($bA1)) === count($rows), $r['say']);

    if (AiVerify::available()) {
        $sw('wa_ops_stepup_on', '1');
        $sw('wa_ops_stepup_actions', 'office_settle_cod', 'string');
        $r = AiTools::run('office_settle_cod', ['pnr' => $bA4['pnr']], $ctx(PH_AG_A, 6, 'cash liye'));
        check('the step-up gate still applies when the office lists the tool', $r['ok'] === false && !empty($r['data']['needs_verification']) && $payOf($bA4) === 'cod_pending', $r['say']);
        $sw('wa_ops_stepup_on', '0');
    }
    $sw('wa_agent_settle_cod_on', '0');
    check('switch back to 0: off the list again', !in_array('office_settle_cod', $names($cA), true));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restore();
    foreach ($jars as $j) { @unlink($j); }
}
$left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . LIKE . "%'", [], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE phone LIKE '%" . LIKE . "%'", [], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username IN (:a, :b, :c)', ['a' => AG_A, 'b' => AG_B, 'c' => BOSS], 0);
check('clean-up left no rows behind', $left === 0, (string) $left);

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
