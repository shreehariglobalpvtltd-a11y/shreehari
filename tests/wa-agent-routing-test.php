<?php
/**
 * wa-agent-routing-test.php — every ticket reaches its passenger (10 Oct 2026).
 *     php tests/wa-agent-routing-test.php
 *
 * includes/waagentroute.php (watch → agent → timeout → next → admin), the
 * short ticket link, ticket changes behind a 6-digit code (includes/waedit.php),
 * the owner teaching the bot (includes/wateach.php), the "hi" number.
 * Every send goes to a stub: nothing leaves this machine.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waqueue.php';
require_once INCLUDE_PATH . '/wabot.php';
require_once INCLUDE_PATH . '/waagentroute.php';
require_once INCLUDE_PATH . '/waedit.php';
require_once INCLUDE_PATH . '/wateach.php';
require_once INCLUDE_PATH . '/ticketlink.php';
require_once INCLUDE_PATH . '/sitefacts.php';
if (stripos((string) DB_NAME, 'test') === false) { exit("Refusing: DB_NAME must be a test database.\n"); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 400) : '') . "\n"; }
}

const AG_A = '9400031001'; const AG_B = '9400031002'; const AG_C = '9400031003';
const CUST = '9400031010'; const CUST2 = '9400031011'; const BOSS = '9400031099'; const DESK = '9400031098';
const PNRS = ['SHG-2099-90001', 'SHG-2099-90002', 'SHG-2099-90003', 'SHG-2099-90004', 'SHG-2099-90005', 'SHG-2099-90006'];

$keepKeys = ['wa_route_on' => '1', 'wa_route_grace_min' => '3', 'wa_route_timeout_min' => '5', 'wa_route_busy_min' => '60', 'wa_route_max_agents' => '4',
    'wa_admin_number' => '91' . BOSS, 'company_whatsapp' => '91' . DESK, 'wa_buttons_on' => '1', 'wa_ticket_buttons_on' => '1', 'wa_edit_otp_on' => '1', 'wa_teach_on' => '1', 'ai_kb_on' => '1',
    'wa_policy' => 'meta_first', 'wa_agent_rewrite' => '1', 'wa_login_on' => '0', 'wa_agent_on' => '0', 'wa_hi_meta' => '1',
    'wa_local_brain_on' => '1', 'wa_habits_on' => '0', 'wa_good_morning_on' => '1', 'wa_good_morning_tpl_on' => '0', 'wa_route_admin_tpl_on' => '0',
    'wa_meta_templates_on' => '0', 'wa_bulk_on' => '0', 'wa_order_menu_on' => '1', 'whatsapp_bot_enabled' => '1', 'ai_learn_on' => '1'];
$keep = [];
foreach ($keepKeys as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
}
$intl = static fn(string $p): string => '91' . $p;
$cleanup = static function () use ($keep): void {
    foreach ($keep as $k => $v) {
        try { if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); } else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); } } catch (Throwable $e) {}
    }
    Settings::flush();
    $p = "'" . implode("','", PNRS) . "'";
    foreach ([
        "DELETE FROM delivery_log WHERE pnr IN ($p)",
        "DELETE FROM ticket_edit_audit WHERE pnr IN ($p)",
        "DELETE tl FROM ticket_links tl JOIN bookings b ON b.id = tl.booking_id WHERE b.pnr IN ($p)",
        "DELETE q FROM wa_ticket_queue q JOIN bookings b ON b.id = q.booking_id WHERE b.pnr IN ($p)",
        "DELETE m FROM message_logs m JOIN bookings b ON b.id = m.booking_id WHERE b.pnr IN ($p)",
        "DELETE p FROM booking_passengers p JOIN bookings b ON b.id = p.booking_id WHERE b.pnr IN ($p)",
        "DELETE l FROM booking_legs l JOIN bookings b ON b.id = l.booking_id WHERE b.pnr IN ($p)",
        "DELETE FROM bookings WHERE pnr IN ($p)",
        "DELETE FROM agent_route_permissions WHERE admin_id IN (SELECT id FROM admins WHERE username LIKE 'war-agent-%')",
        "DELETE FROM wa_agent_presence WHERE admin_id IN (SELECT id FROM admins WHERE username LIKE 'war-agent-%')",
        "DELETE FROM admins WHERE username LIKE 'war-agent-%'",
        "DELETE FROM kv_store WHERE kkey LIKE '%9400031%'",
        "DELETE FROM otp_codes WHERE identifier LIKE '%9400031%'",
        "DELETE FROM rate_limits WHERE identifier LIKE '%9400031%'",
        "DELETE FROM message_logs WHERE to_number LIKE '%9400031%'",
        "DELETE FROM notifications WHERE title LIKE '%SHG-2099-9000%'",
        "DELETE FROM ai_kb_articles WHERE category = 'whatsapp-taught' AND canonical_title LIKE '%war-test%'",
        "DELETE FROM ai_unanswered_questions WHERE normalized_question LIKE '%war-test%'",
        "DELETE FROM ai_agent_calls WHERE phone LIKE '%9400031%'",
    ] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    WaRoute::$transport = null;
    WaAgentRoute::$transport = null;
    WaAgentRoute::$pusher = null;
    WaEdit::$codeGen = null;
};
register_shutdown_function($cleanup);
$cleanup();
foreach ($keepKeys as $k => $v) {
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();
WaAgentRoute::reset();

/* ---- every send is captured ---- */
$sent = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$sent): array {
    $sent[] = ['to' => $to, 'text' => $text, 'image' => $image, 'ui' => null, 'via' => 'route'];
    return ['success' => true, 'message_id' => 'wamid.WAR' . count($sent), 'error' => ''];
};
WaAgentRoute::$transport = static function (string $to, string $text, ?string $image, ?array $ui) use (&$sent): array {
    $sent[] = ['to' => $to, 'text' => $text, 'image' => $image, 'ui' => $ui, 'via' => 'agent'];
    return ['success' => true, 'message_id' => 'wamid.WAR' . count($sent), 'error' => ''];
};
$pushes = [];
WaAgentRoute::$pusher = static function (int $adminId, array $payload) use (&$pushes): int {
    $pushes[] = ['admin' => $adminId, 'payload' => $payload];
    return 0;   // nobody subscribed, unless a test says otherwise
};
WaEdit::$codeGen = static fn(): string => '123456';
$to = static function (string $phone) use (&$sent): array {
    return array_values(array_filter($sent, static fn(array $s): bool => $s['to'] === '91' . $phone));
};
$window = static function (string $phone, bool $open = true): void {
    if ($open) {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'test') ON DUPLICATE KEY UPDATE kvalue = :v2",
            ['k' => '91' . $phone, 'v' => (string) time(), 'v2' => (string) time()]);
    } else {
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => '91' . $phone]);
    }
};
$ask = static fn(string $phone, string $q): array => WaBot::reply('+91' . $phone, $q);

/* ---- fixtures: three agents, bookings cloned from a real confirmed one on route 2 ---- */
$mkAgent = static function (string $user, string $phone): int {
    return (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('War@12345', PASSWORD_BCRYPT),
        'full_name' => ucfirst(str_replace('war-agent-', 'Agent ', $user)) . ' Test', 'role' => 'agent', 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0]);
};
$A = $mkAgent('war-agent-a', AG_A);
$B = $mkAgent('war-agent-b', AG_B);
$C = $mkAgent('war-agent-c', AG_C);

$src = Database::fetch("SELECT b.* FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
    JOIN schedules s ON s.id = bl.schedule_id WHERE b.status = 'confirmed' AND s.route_id = 2 ORDER BY b.id DESC LIMIT 1");
$travel = date('Y-m-d', time() + 2 * 86400);
$sched = (int) Database::scalar('SELECT id FROM schedules WHERE route_id = 2 AND travel_date = :d LIMIT 1', ['d' => $travel], 0);
$mkBooking = static function (string $pnr, string $phone, int $soldBy, string $source = 'web') use ($src, $travel, $sched): int {
    $row = $src;
    unset($row['id']);
    $row['pnr'] = $pnr; $row['contact_phone'] = $phone; $row['contact_country_code'] = 'IN'; $row['alt_phone'] = null;
    $row['sold_by_admin_id'] = $soldBy; $row['status'] = 'confirmed'; $row['source'] = $source; $row['created_at'] = date('Y-m-d H:i:s'); $row['confirmed_at'] = date('Y-m-d H:i:s');
    $bid = (int) Database::insert('bookings', $row);
    foreach (Database::fetchAll("SELECT * FROM booking_legs WHERE booking_id = :b", ['b' => (int) $src['id']]) as $leg) {
        $oldLeg = (int) $leg['id'];
        unset($leg['id']);
        $leg['booking_id'] = $bid; $leg['travel_date'] = $travel; $leg['schedule_id'] = $sched > 0 ? $sched : $leg['schedule_id'];
        $legId = (int) Database::insert('booking_legs', $leg);
        foreach (Database::fetchAll("SELECT * FROM booking_passengers WHERE booking_id = :b AND (leg_id = :l OR leg_id IS NULL)", ['b' => (int) $src['id'], 'l' => $oldLeg]) as $px) {
            unset($px['id'], $px['boarded_at']);
            $px['booking_id'] = $bid; $px['leg_id'] = $px['leg_id'] !== null ? $legId : null; $px['full_name'] = 'Ram war-test Bahadur';
            $px['passenger_ref'] = 'WAR-' . strtoupper(bin2hex(random_bytes(5)));
            Database::insert('booking_passengers', $px);
        }
    }
    return $bid;
};

echo "\n=== ticket delivery: agent routing, ticket link, changes by code, teaching ===\n\n";
try {
    check('fixtures: source booking on route 2 + a schedule 2 days ahead', $src !== null && $sched > 0, 'sched=' . $sched);
    check('routing tables + switch ready', WaAgentRoute::ready() && WaAgentRoute::on() && WaEdit::ready() && TicketLink::ready());

    /* ---------- the "hi" number ---------- */
    check('"hi" goes to the Meta number (webhook live)', WaQueue::hiNumber() === SiteFacts::chatDigits(), WaQueue::hiNumber());
    Settings::set('wa_hi_meta', '0', 'string', 'whatsapp', false); Settings::flush();
    check('wa_hi_meta = 0 → back to the AiSensy number', WaQueue::hiNumber() !== SiteFacts::chatDigits(), WaQueue::hiNumber());
    Settings::set('wa_hi_meta', '1', 'string', 'whatsapp', false); Settings::flush();

    $b1 = $mkBooking(PNRS[0], CUST, $A);
    $b2 = $mkBooking(PNRS[1], CUST2, $A);

    /* ---------- short ticket link ---------- */
    $code = TicketLink::code($b1);
    check('short code: 10 characters', strlen($code) === 10, $code);
    check('short code reused, not re-minted', TicketLink::code($b1) === $code);
    check('short URL is /ticket/view.php?code=', str_contains(TicketLink::forBooking($b1), '/ticket/view.php?code=' . $code));
    $res = TicketLink::resolve($code);
    check('resolve() → the booking', $res !== null && (int) $res['id'] === $b1);
    check('malformed / unknown code → null', TicketLink::resolve('abc') === null && TicketLink::resolve('ZZZZZZZZZZ') === null);
    Database::run('UPDATE ticket_links SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE short_code = :c', ['c' => $code]);
    check('expired code → null', TicketLink::resolve($code) === null);
    check('expired → a fresh code is minted', ($code2 = TicketLink::code($b1)) !== '' && $code2 !== $code);

    /* ---------- the page ---------- */
    $php = PHP_BINARY; $ini = (string) php_ini_loaded_file();
    $runPage = static function (string $c) use ($php, $ini): string {
        $f = tempnam(sys_get_temp_dir(), 'tlv') . '.php';
        file_put_contents($f, '<?php $_GET["code"] = ' . var_export($c, true) . '; $_SERVER["REMOTE_ADDR"] = "127.0.0.9"; chdir(' . var_export(dirname(__DIR__), true) . '); include "ticket/view.php";');
        $out = (string) shell_exec(escapeshellarg($php) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($f) . ' 2>&1');
        @unlink($f);
        return $out;
    };
    $page = $runPage($code2);
    check('ticket/view.php shows the PNR, the hi button to the Meta number, first name only', str_contains($page, PNRS[0])
        && str_contains($page, 'wa.me/' . SiteFacts::chatDigits() . '?text=Hi%20' . PNRS[0]) && str_contains($page, 'Ram B.') && !str_contains($page, CUST), mb_substr(strip_tags($page), 0, 300));
    $page2 = $runPage('ZZZZZZZZZZ');
    check('unknown code → neutral page, no PNR', str_contains($page2, 'Link not available') && !str_contains($page2, 'SHG-2099'), mb_substr(strip_tags($page2), 0, 200));

    /* ---------- did the customer get it? ---------- */
    check('customerGot: nothing sent → false', WaAgentRoute::customerGot($b1) === false);
    Database::run("INSERT INTO wa_ticket_queue (booking_id, pnr, phone, role, status, via, sent_at) VALUES (:b, :p, :ph, 'customer', 'sent', 'template', NOW())",
        ['b' => $b1, 'p' => PNRS[0], 'ph' => $intl(CUST)]);
    check('customerGot: a template accepted just now → null (wait)', WaAgentRoute::customerGot($b1) === null);
    Database::run("UPDATE wa_ticket_queue SET status = 'failed', last_error = 'WCC' WHERE booking_id = :b", ['b' => $b1]);
    check('customerGot: failed → false', WaAgentRoute::customerGot($b1) === false);

    /* ---------- watch → agent A (the seller) ---------- */
    WaAgentRoute::onConfirmed(['id' => $b1, 'status' => 'pending']);   // a stale event row: read fresh
    WaAgentRoute::onConfirmed(['id' => $b1]);
    $watch = Database::fetchAll('SELECT * FROM delivery_log WHERE booking_id = :b', ['b' => $b1]);
    check('booking.approved → one "watching" row with a deadline (no duplicate)', count($watch) === 1 && $watch[0]['status'] === 'watching' && $watch[0]['deadline_at'] !== null);

    $window(AG_A); $window(AG_B); $window(CUST); $window(BOSS);
    Database::run('UPDATE delivery_log SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE booking_id = :b', ['b' => $b1]);
    $sent = [];
    $t = WaAgentRoute::tick();
    $offer = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered'", ['b' => $b1]);
    check('tick: customer did not get it → offered to the seller (agent A)', $offer !== null && (int) $offer['agent_id'] === $A, json_encode($t));
    $toA = $to(AG_A);
    check('agent A got the hand-over: passenger number, wa.me link, ticket link, Done/Busy buttons', $toA !== []
        && str_contains($toA[0]['text'], PNRS[0]) && str_contains($toA[0]['text'], 'wa.me/91' . CUST) && str_contains($toA[0]['text'], '/ticket/view.php?code=')
        && ($toA[0]['ui']['buttons'][0]['id'] ?? '') === 'shg:rt ok ' . (int) $offer['id'] && ($toA[0]['ui']['buttons'][1]['id'] ?? '') === 'shg:rt busy ' . (int) $offer['id'],
        json_encode($toA[0] ?? null, JSON_UNESCAPED_UNICODE));
    check('the hand-over is free: no template anywhere', array_filter($sent, static fn($s) => str_starts_with((string) $s['text'], 'template:')) === []);

    /* ---------- agent A taps ✅ ---------- */
    $sent = [];
    $r = $ask(AG_A, 'rt ok ' . (int) $offer['id']);
    $acc = Database::fetch('SELECT status, closed FROM delivery_log WHERE id = :i', ['i' => (int) $offer['id']]);
    check('✅ → accepted + closed', $acc['status'] === 'accepted' && (int) $acc['closed'] === 1, json_encode($acc));
    check('agent thanked', str_contains($r['text'], 'धन्यवाद'), $r['text']);
    check('passenger told who their agent is (free, window open)', ($c = $to(CUST)) !== [] && str_contains($c[0]['text'], 'एजेन्ट'), json_encode($sent, JSON_UNESCAPED_UNICODE));
    check('accepted agent shows on the page data', (WaAgentRoute::acceptedAgent($b1)['id'] ?? 0) === $A);

    /* ---------- timeout → busy → next agent → declined → admin ---------- */
    Database::insert('agent_route_permissions', ['admin_id' => $B, 'route_id' => 2]);
    WaAgentRoute::onConfirmed(['id' => $b2]);
    Database::run('UPDATE delivery_log SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE booking_id = :b', ['b' => $b2]);
    $sent = [];
    WaAgentRoute::tick();
    $o1 = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered'", ['b' => $b2]);
    check('ticket 2 → agent A first (seller)', $o1 !== null && (int) $o1['agent_id'] === $A);
    Database::run('UPDATE delivery_log SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE id = :i', ['i' => (int) $o1['id']]);
    $sent = [];
    WaAgentRoute::tick();
    $tA = Database::fetch('SELECT status FROM delivery_log WHERE id = :i', ['i' => (int) $o1['id']]);
    check('5 minutes, no answer → timeout', ($tA['status'] ?? '') === 'timeout');
    check('agent A is now busy', WaAgentRoute::status($A) === 'busy');
    $o2 = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered' ORDER BY id DESC LIMIT 1", ['b' => $b2]);
    check('→ the next agent on the route (B, assigned) is asked', $o2 !== null && (int) $o2['agent_id'] === $B, json_encode($o2));
    $r = $ask(AG_B, 'ho');
    check('a bare "ho" from B (maybe confirming a sale) is NOT taken as the hand-over answer',
        (Database::fetch('SELECT status FROM delivery_log WHERE id = :i', ['i' => (int) $o2['id']])['status'] ?? '') === 'offered', $r['text']);
    $sent = [];
    $r = $ask(AG_B, 'busy ' . PNRS[1]);
    check('B says "busy <PNR>" → declined, B busy', (Database::fetch('SELECT status FROM delivery_log WHERE id = :i', ['i' => (int) $o2['id']])['status'] ?? '') === 'declined'
        && WaAgentRoute::status($B) === 'busy', $r['text']);
    $ex = Database::fetchAll("SELECT status, channel, detail FROM delivery_log WHERE booking_id = :b AND status IN ('exhausted','customer_direct','admin_notified')", ['b' => $b2]);
    check('nobody left → exhausted + passenger direct + admin told (one each)', count($ex) === 3, json_encode($ex, JSON_UNESCAPED_UNICODE));
    check('admin got the free alert with the PNR + passenger link', ($ad = $to(BOSS)) !== [] && str_contains($ad[0]['text'], PNRS[1]) && str_contains($ad[0]['text'], 'wa.me/91' . CUST2),
        json_encode($ad, JSON_UNESCAPED_UNICODE));
    check('the passenger window was closed → logged as waiting for their "hi" (nothing paid)', str_contains((string) (array_values(array_filter($ex, fn($x) => $x['status'] === 'customer_direct'))[0]['detail'] ?? ''), 'window closed'));
    WaAgentRoute::exhaust(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $b2]));
    check('exhaust runs once per ticket', (int) Database::scalar("SELECT COUNT(*) FROM delivery_log WHERE booking_id = :b AND status = 'exhausted'", ['b' => $b2], 0) === 1);

    /* admin window closed → the panel bell, never a paid template */
    $b3 = $mkBooking(PNRS[2], CUST, $C);
    $window(BOSS, false);
    WaAgentRoute::setStatus($A, 'offline'); WaAgentRoute::setStatus($B, 'offline');
    $sent = [];
    WaAgentRoute::offerNext(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $b3]));
    $skip = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND agent_id = :c", ['b' => $b3, 'c' => $C]);
    check('agent C (window closed, no push) → skipped', ($skip['status'] ?? '') === 'skipped', json_encode($skip));
    check('admin window closed → admin panel bell', Database::exists("SELECT 1 FROM notifications WHERE title LIKE :t", ['t' => '%' . PNRS[2] . '%']));
    check('offline agents are never asked', !Database::exists("SELECT 1 FROM delivery_log WHERE booking_id = :b AND agent_id IN (:a, :bb)", ['b' => $b3, 'a' => $A, 'bb' => $B]));

    /* push path: C has a web push → offered by push; C writes → the packet arrives */
    Database::run('DELETE FROM delivery_log WHERE booking_id = :b', ['b' => $b3]);
    WaAgentRoute::$pusher = static function (int $adminId, array $payload) use (&$pushes): int { $pushes[] = ['admin' => $adminId, 'payload' => $payload]; return 1; };
    $pushes = [];
    WaAgentRoute::offerNext(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $b3]));
    $po = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered'", ['b' => $b3]);
    check('closed window + web push → offered by push, link opens WhatsApp to the Meta number', ($po['channel'] ?? '') === 'push'
        && str_contains((string) ($pushes[0]['payload']['url'] ?? ''), 'wa.me/' . SiteFacts::chatDigits()), json_encode($pushes));
    $window(AG_C);
    $sent = [];
    $r = $ask(AG_C, 'hi');
    $pk = $to(AG_C);
    check('C opens WhatsApp ("hi") → the hand-over packet goes as its own message', $pk !== [] && str_contains($pk[0]['text'], PNRS[2]) && str_contains($pk[0]['text'], 'wa.me/91' . CUST),
        json_encode($pk, JSON_UNESCAPED_UNICODE));
    check('… and C\'s own "hi" is still answered by the bot (not swallowed)', !str_contains($r['text'], 'wa.me/91' . CUST), $r['text']);
    $r = $ask(AG_C, 'ok ' . PNRS[2]);
    check('"ok <PNR>" typed → accepted', (Database::fetch("SELECT status FROM delivery_log WHERE booking_id = :b AND agent_id = :a ORDER BY id DESC LIMIT 1", ['b' => $b3, 'a' => $C])['status'] ?? '') === 'accepted', $r['text']);
    WaAgentRoute::$pusher = static function (int $adminId, array $payload) use (&$pushes): int { $pushes[] = ['admin' => $adminId, 'payload' => $payload]; return 0; };

    /* presence words */
    $r = $ask(AG_A, 'available');
    check('"available" → available (+ buttons)', WaAgentRoute::status($A) === 'available' && ($r['ui']['buttons'][0]['id'] ?? '') === 'shg:rt avail', $r['text']);
    $ask(AG_A, 'offline');
    check('"offline" → offline, left out of candidates', WaAgentRoute::status($A) === 'offline'
        && !in_array($A, array_column(WaAgentRoute::candidates(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $b1])), 'id'), true));
    $ask(AG_A, 'rt avail');
    check('button "rt avail" → available again', WaAgentRoute::status($A) === 'available');
    $r = $ask(CUST, 'available');
    check('a customer writing "available" is not an agent command', WaAgentRoute::status($A) === 'available' && !str_contains($r['text'], 'available हुनुभयो'));

    /* ---------- walk-ins and desk sales ---------- */
    $bw = $mkBooking(PNRS[4], '9999999999', $A, 'counter');
    WaAgentRoute::onConfirmed(['id' => $bw]);
    check('walk-in with a placeholder number → not watched (the seller copy already went)', !Database::exists('SELECT 1 FROM delivery_log WHERE booking_id = :b', ['b' => $bw]));
    WaAgentRoute::setStatus($A, 'available'); WaAgentRoute::setStatus($B, 'available');
    $bd = $mkBooking(PNRS[5], CUST2, $A, 'counter');
    WaAgentRoute::onConfirmed(['id' => $bd]);
    Database::run('UPDATE delivery_log SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE booking_id = :b', ['b' => $bd]);
    WaAgentRoute::tick();
    $do = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered'", ['b' => $bd]);
    check('desk sale → only its own seller is asked', $do !== null && (int) $do['agent_id'] === $A, json_encode($do));
    Database::run('UPDATE delivery_log SET deadline_at = NOW() - INTERVAL 1 MINUTE WHERE id = :i', ['i' => (int) $do['id']]);
    $sent = [];
    WaAgentRoute::tick();
    check('desk seller who does not answer is NOT marked busy', WaAgentRoute::status($A) === 'available');
    check('desk sale: no other agent, no admin alarm', !Database::exists("SELECT 1 FROM delivery_log WHERE booking_id = :b AND (agent_id = :bb OR status = 'admin_notified')", ['b' => $bd, 'bb' => $B])
        && $to(BOSS) === [], json_encode(Database::fetchAll('SELECT status, agent_id FROM delivery_log WHERE booking_id = :b', ['b' => $bd])));

    /* ---------- ticket changes behind a code ---------- */
    $sent = [];
    WaEdit::afterTicket($b1, '+91' . CUST);
    $menu = $to(CUST);
    check('after a free ticket: Name / Date / More buttons', $menu !== [] && ($menu[0]['ui']['buttons'][0]['id'] ?? '') === 'shg:ed name ' . PNRS[0]
        && ($menu[0]['ui']['buttons'][2]['id'] ?? '') === 'shg:ed more ' . PNRS[0], json_encode($menu, JSON_UNESCAPED_UNICODE));
    $sent = [];
    WaEdit::afterTicket($b1, '+91' . CUST);
    check('buttons once an hour per ticket', $to(CUST) === []);
    Database::run("DELETE FROM rate_limits WHERE identifier LIKE :i", ['i' => '%' . CUST . '%']);
    $sent = [];
    WaEdit::afterTicket($b1, '+91' . CUST, true);
    check('deferred (booking request path): nothing sent now', $to(CUST) === []);
    WaEdit::flushDeferred();
    check('… the routing cron sends it', ($dm = $to(CUST)) !== [] && ($dm[0]['ui']['buttons'][0]['id'] ?? '') === 'shg:ed name ' . PNRS[0]);

    $r = $ask(CUST, 'ed name ' . PNRS[0]);
    check('✏️ Name → asks the new name (shows the old one)', str_contains($r['text'], 'Ram war-test Bahadur'), $r['text']);
    $r = $ask(CUST, 'ok');
    check('"ok" while a new name is awaited is NOT taken as the name', !str_contains($r['text'], '६ अंक')
        && (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $b1], '') === 'Ram war-test Bahadur', $r['text']);
    $sent = [];
    $r = $ask(CUST, 'Sita war-test Sharma');
    $otpMsg = $to(CUST);
    check('new name → a 6-digit code is sent free in the chat', $otpMsg !== [] && str_contains($otpMsg[0]['text'], '123456') && str_contains($r['text'], '६ अंक'), $r['text']);
    $r = $ask(CUST, '000000');
    check('wrong code → refused, nothing changed', str_contains($r['text'], 'मिलेन')
        && (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $b1], '') === 'Ram war-test Bahadur', $r['text']);
    $r = $ask(CUST, '123456');
    $nm = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => $b1], '');
    $au = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'passenger_name' ORDER BY id DESC LIMIT 1", ['b' => $b1]);
    check('right code → the name is changed', $nm === 'Sita war-test Sharma', $r['text'] . ' | db=' . $nm);
    check('audit: old, new, by, otp_verified = 1, done', $au !== null && $au['old_value'] === 'Ram war-test Bahadur' && $au['new_value'] === 'Sita war-test Sharma'
        && (int) $au['otp_verified'] === 1 && $au['outcome'] === 'done' && str_contains((string) $au['changed_by'], CUST), json_encode($au, JSON_UNESCAPED_UNICODE));

    $r = $ask(CUST2, 'ed name ' . PNRS[0]);
    check('another number cannot change this ticket', str_contains($r['text'], '🔒'), $r['text']);

    // Cancel = a request only.
    $r = $ask(CUST, 'ed cancel ' . PNRS[0]);
    check('❌ Cancel → code asked, office settles it', str_contains($r['text'], 'कोड') || str_contains($r['text'], 'code'), $r['text']);
    $r = $ask(CUST, '123456');
    check('cancel after the code = a REQUEST: booking still confirmed', (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $b1], '') === 'confirmed', $r['text']);
    check('cancel request audited (requested, code verified) + panel bell', Database::exists("SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request' AND outcome = 'requested' AND otp_verified = 1", ['b' => $b1])
        && Database::exists("SELECT 1 FROM notifications WHERE title LIKE :t", ['t' => 'Cancel request%' . PNRS[0] . '%']));

    // Change agent.
    WaAgentRoute::setStatus($B, 'available');
    $r = $ask(CUST, 'ed agent ' . PNRS[0]);
    $r = $ask(CUST, '123456');
    $na = Database::fetch("SELECT * FROM delivery_log WHERE booking_id = :b AND status = 'offered' ORDER BY id DESC LIMIT 1", ['b' => $b1]);
    check('🧑‍💼 Change agent (after the code) → a DIFFERENT agent is asked', $na !== null && (int) $na['agent_id'] !== $A, $r['text'] . ' | ' . json_encode($na));
    check('agent change audited', Database::exists("SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'agent' AND otp_verified = 1", ['b' => $b1]));

    // Date: a preview, a code, then the same tool the desk uses (or an honest refusal).
    $new = date('Y-m-d', time() + 3 * 86400);
    $r = $ask(CUST, 'ed date ' . PNRS[0]);
    $r = $ask(CUST, $new);
    $dateFlow = str_contains($r['text'], '६ अंक') || str_contains($r['text'], '❌');
    check('📅 Date → a preview + code, or an honest refusal (audited)', $dateFlow, $r['text']);
    if (str_contains($r['text'], '६ अंक')) {
        $r = $ask(CUST, '123456');
        check('date change committed through fix_ticket (audited with the code)', Database::exists("SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'travel_date' AND otp_verified = 1", ['b' => $b1]), $r['text']);
    } else {
        check('date refusal audited', Database::exists("SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'travel_date' AND outcome = 'refused'", ['b' => $b1]));
        echo "        (refusal said: " . mb_substr(str_replace("
", ' ', $r['text']), 0, 200) . ")
";
    }

    // Words / voice → the same actions.
    check('intent: "mero naam galat cha" → name', WaEdit::intentOf('mero naam galat cha', 'mero naam galat cha') === 'name');
    check('intent: "miti badalnu cha" → date', WaEdit::intentOf('miti badalnu cha', 'miti badalnu cha') === 'date');
    check('intent: "agent change gardinus" → agent', WaEdit::intentOf('agent change gardinus', 'agent change gardinus') === 'agent');
    check('intent: "ticket cancel garnu cha" → cancel', WaEdit::intentOf('ticket cancel garnu cha', 'ticket cancel garnu cha') === 'cancel');
    check('intent: a refund QUESTION is not a cancel', WaEdit::intentOf('cancel garda kati paisa katcha?', 'cancel garda kati paisa katcha?') === '');
    check('intent: "ticket confirm bhayo?" → confirm', WaEdit::intentOf('ticket confirm bhayo', 'ticket confirm bhayo?') === 'confirm');
    check('intent: small talk → nothing', WaEdit::intentOf('namaste', 'namaste') === '' && WaEdit::intentOf('bholi 2 seat chahiyo', 'bholi 2 seat chahiyo') === '');
    check('intent: "payment credited, please update" → nothing (no edit / date inside words)', WaEdit::intentOf('payment credited, please update', 'payment credited, please update') === '');
    check('intent: "agent se baat karunga" → nothing', WaEdit::intentOf('agent se baat karunga', 'agent se baat karunga') === '');
    check('intent: "नाम ठीक है" (the name is fine) → nothing', WaEdit::intentOf('नाम ठीक है', 'नाम ठीक है') === '');
    require_once INCLUDE_PATH . '/aitools.php';
    check('staff words ("<PNR> ko naam galat cha" from an agent) stay with the staff tools', WaEdit::handle('+91' . AG_A, PNRS[0] . ' ko naam galat cha', AiTools::whoIs('+91' . AG_A)) === null);
    $r = $ask(CUST, 'mero naam galat cha');
    check('typed/spoken "naam galat cha" opens the name change on their next live ticket', str_contains($r['text'], 'अहिलेको नाम') && str_contains($r['text'], 'war-test'), $r['text']);
    $r = $ask(CUST, 'stop');
    check('"stop" ends the change, nothing changed', str_contains($r['text'], 'nothing was changed'), $r['text']);

    /* ---------- the owner teaches the bot ---------- */
    $r = $ask(BOSS, 'sikau: war-test bus ma wifi cha? = छ, बसमा निःशुल्क WiFi छ।');
    $art = Database::fetch("SELECT * FROM ai_kb_articles WHERE category = 'whatsapp-taught' AND canonical_title LIKE '%war-test%' ORDER BY id DESC LIMIT 1");
    check('sikau: → a published, verified article at once', $art !== null && $art['publication_status'] === 'published' && $art['verification_status'] === 'verified' && str_contains($r['text'], 'सिकें'), $r['text']);
    require_once INCLUDE_PATH . '/aiknowledge.php';
    $hit = AiKb::search('war-test bus ma wifi cha', 'public', 1);
    check('the lesson is found for the next customer (no AI)', ($hit[0]['id'] ?? 0) === (int) ($art['id'] ?? -1));
    Database::run("INSERT INTO ai_unanswered_questions (question_hash, normalized_question, language, frequency) VALUES (:h, 'war-test bus ma khana milcha', 'en', 4)",
        ['h' => hash('sha256', 'war-test bus ma khana milcha')]);
    $qid = (int) Database::scalar("SELECT id FROM ai_unanswered_questions WHERE normalized_question = 'war-test bus ma khana milcha'", [], 0);
    $r = $ask(BOSS, 'prashna');
    check('prashna → the unanswered questions with their numbers', str_contains($r['text'], '#' . $qid) && str_contains($r['text'], 'jawaf'), $r['text']);
    $r = $ask(BOSS, 'jawaf ' . $qid . ': बाटोमा होटलमा रोकिन्छ, खाना आफैं किन्नुपर्छ।');
    check('jawaf N: → answered + linked to the new article', (string) Database::scalar('SELECT status FROM ai_unanswered_questions WHERE id = :i', ['i' => $qid], '') === 'answered', $r['text']);
    $r = $ask(CUST, 'sikau: war-test x = y');
    check('a customer cannot teach', !str_contains($r['text'], 'सिकें') && !Database::exists("SELECT 1 FROM ai_kb_articles WHERE canonical_title = 'war-test x'"), $r['text']);
    $r = $ask(DESK, 'sikau: war-test desk q = desk a');
    check('the desk handset (company_whatsapp) cannot teach', !Database::exists("SELECT 1 FROM ai_kb_articles WHERE canonical_title LIKE 'war-test desk%'"), $r['text']);
    $r = $ask(BOSS, 'bot help');
    check('bot help lists the commands', str_contains($r['text'], 'sikau') && str_contains($r['text'], 'available'), $r['text']);

    /* ---------- good morning ---------- */
    $todaySched = (int) Database::scalar('SELECT id FROM schedules WHERE route_id = 2 AND travel_date = CURDATE() LIMIT 1', [], 0);
    if ($todaySched > 0) {
        WaAgentRoute::setStatus($B, 'available');
        $window(AG_B);
        $sent = [];
        $gm = WaAgentRoute::goodMorning();
        $gB = $to(AG_B);
        check('6 AM: the route agent gets a free good morning with Available / Busy / Offline', $gB !== [] && str_contains($gB[0]['text'], 'शुभ प्रभात')
            && ($gB[0]['ui']['buttons'][0]['id'] ?? '') === 'shg:rt avail', json_encode($gm));
        $sent = [];
        WaAgentRoute::goodMorning();
        check('good morning once a day', $to(AG_B) === []);
    } else {
        check('good morning (no bus today on route 2 in this DB — skipped)', true);
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n" . ($FAIL === 0 ? "\033[32m" : "\033[31m") . "$PASS passed, $FAIL failed\033[0m\n";
exit($FAIL === 0 ? 0 : 1);
