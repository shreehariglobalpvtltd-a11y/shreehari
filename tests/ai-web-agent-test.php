<?php
/**
 * =====================================================================
 *  ai-web-agent-test.php — SHG Sahayak on the WEBSITE and in the APP
 *  (24 Sep 2026): the identity, the catalogue, the report tools.
 *
 *  wa-agent-test.php proves the hands on WhatsApp, where the phone number
 *  is the identity. This suite proves the same hands on the website,
 *  where the SESSION is:
 *
 *    • IDENTITY   the office session is admin, a counter agent's is staff
 *                 scoped to their own book, an OTP customer is a customer
 *                 on their own number, nobody is a guest with a stage key
 *    • CATALOGUE  a guest is never offered issue_ticket (no number to sell
 *                 to); a signed-in passenger only when ai_web_sell is ON;
 *                 WhatsApp keeps following wa_agent_sell
 *    • REPORTS    sales_report counts a real sale, and an agent sees only
 *                 their own; site_visitors reads the beacon and refuses
 *                 staff; occupancy lists the coming days; the office gets
 *                 the leaderboard; every report carries a drawable chart
 *    • FEEDBACK   a 5-star lands in `feedback`; a 1-star also files a
 *                 complaint in `enquiries` for the office to call back
 *    • AUDIT      every call is in ai_agent_calls with channel = web, and
 *                 a guest is logged under a hashed key, never blank
 *    • ENTRY      with no key the web agent is silent (null); "reset" is
 *                 answered locally; the migration seeds the switches
 *
 *  Creates one real sale on a throwaway date and cleans up after itself.
 *      php tests/ai-web-agent-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/aichart.php';

const WEB_AGENT = '9100006101';   // our counter agent
const WEB_OTHER = '9100006102';   // another counter agent
const WEB_BOSS  = '9100006103';   // the office
const WEB_CUST  = '9100006104';   // an OTP customer
const WEB_PAX   = '9100006105';   // the passenger the agent sells to
const WEB_LIKE  = '910000610';    // cleanup prefix

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Website / app assistant — identity, catalogue, reports, feedback, audit ===\n\n";

/* ---- switches this suite drives; restored exactly as found ----------- */
$PINNED = ['wa_agent_on', 'wa_agent_sell', 'ai_web_agent_on', 'ai_web_sell', 'anthropic_api_key', 'gemini_api_key', 'ai_provider',
           'daily_service_on', 'allow_cod', 'quick_ticket_customer_on', 'quick_ticket_customer_boarding', 'quick_ticket_max_open',
           'whatsapp_driver', 'whatsapp_notify_customer', 'whatsapp_notify_admin', 'events_beacon_on'];
$prior = [];
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
Settings::set('wa_agent_on', true, 'bool', 'ai', false);
Settings::set('wa_agent_sell', false, 'bool', 'ai', false);
Settings::set('ai_web_agent_on', true, 'bool', 'ai', false);
Settings::set('ai_web_sell', false, 'bool', 'ai', false);
Settings::set('anthropic_api_key', '', 'string', 'ai', false);
Settings::set('gemini_api_key', '', 'string', 'ai', false);
Settings::set('ai_provider', 'auto', 'string', 'ai', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_customer_boarding', '', 'string', 'booking', false);
Settings::set('quick_ticket_max_open', 6, 'int', 'booking', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::flush();

/* ---- a route to sell on ---------------------------------------------- */
$route = null;
foreach (QuickTicket::routes() as $r) {
    if ($r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $route = $r; break; }
}
if ($route === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; $restoreSettings(); exit(0); }
$today  = todayISO();
$window = bookingWindow();
$D1 = addDaysISO($today, 10);   // inside the 14-day occupancy window
if ($D1 > (string) $window['to']) { echo "  SKIP  the public booking window is shorter than 10 days\n"; $restoreSettings(); exit(0); }

/* ---- staff fixtures ---------------------------------------------------- */
$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', [
            'username' => $username, 'password_hash' => password_hash('Web@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name, 'must_change_pw' => 0], 'id = :i', ['i' => $id]);
    return $id;
};
$agentId = $mkStaff('web-agent', 'Web Test Agent', 'agent', WEB_AGENT);
$otherId = $mkStaff('web-other', 'Web Other Agent', 'agent', WEB_OTHER);
$bossId  = $mkStaff('web-boss',  'Web Test Boss',  'superadmin', WEB_BOSS);

$cleanup = static function () use ($D1): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WEB_LIKE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'feedback'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('ai_agent_calls', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('ai_agent_calls', "phone LIKE '" . WEB_LIKE . "%' OR phone LIKE 'web-guest-%' OR phone LIKE 'web-staff-%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn','wa_ticket_fix') AND (kkey LIKE 'web-%' OR kkey LIKE '" . WEB_LIKE . "%')", []); } catch (Throwable $e) {}
    try { Database::delete('feedback', "user_phone LIKE '" . WEB_LIKE . "%' OR name LIKE 'Web Test%'", []); } catch (Throwable $e) {}
    try { Database::delete('enquiries', "phone LIKE '" . WEB_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('app_events', "session_key LIKE 'aaaaweb%'", []); } catch (Throwable $e) {}
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D1]) as $s) {
        $sid = (int) $s['id'];
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]);
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        try { Database::delete('booking_seats', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D1]);
    Database::delete('schedules', 'travel_date = :d', ['d' => $D1]);
    try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[USER_SESSION_KEY]);
};
$cleanup();

/* Sign somebody in the way the panel / the OTP door does, then ask whoIsWeb. */
$asStaff = static function (int $id): array {
    unset($_SESSION[USER_SESSION_KEY]);
    $row = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $id]);
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $id, 'username' => $row['username'], 'full_name' => $row['full_name'],
        'role' => $row['role'], 'phone' => $row['phone'], 'is_active' => 1, 'permissions' => [], 'last_seen' => time()];
    return AiTools::whoIsWeb();
};
$asCustomer = static function (string $phone, string $name): array {
    unset($_SESSION[ADMIN_SESSION_KEY]);
    $_SESSION[USER_SESSION_KEY] = ['id' => 999999, 'phone' => $phone, 'full_name' => $name, 'role' => 'customer', 'last_seen' => time()];
    return AiTools::whoIsWeb();
};
$asGuest = static function (): array {
    unset($_SESSION[ADMIN_SESSION_KEY], $_SESSION[USER_SESSION_KEY]);
    return AiTools::whoIsWeb();
};
$names = static fn(array $tools): array => array_column($tools, 'name');
$turn  = 0;
$run   = static function (string $tool, array $args, array $ctx) use (&$turn): array {
    $ctx['turn'] = ++$turn;
    return AiTools::run($tool, $args, $ctx);
};

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

try {
    /* ================================================================
     *  1. Identity — the session decides, the message never does
     * ================================================================ */
    echo "== who is asking ==\n";
    $boss  = $asStaff($bossId);
    $agent = $asStaff($agentId);
    $other = $asStaff($otherId);
    $cust  = $asCustomer(WEB_CUST, 'Web Test Customer');
    $guest = $asGuest();

    check('the office session is admin', $boss['role'] === 'admin' && $boss['adminId'] === $bossId && $boss['scopeAdminId'] === null, $boss['role']);
    check('  on the web channel with a staff stage key', $boss['channel'] === 'web' && $boss['stageKey'] === 'web-staff-' . $bossId, $boss['stageKey']);
    check('a counter agent session is staff', $agent['role'] === 'staff' && $agent['adminId'] === $agentId);
    check('  scoped to their own book', (int) $agent['scopeAdminId'] === $agentId);
    check('an OTP customer is a customer on their own number', $cust['role'] === 'customer' && $cust['phone'] === WEB_CUST && $cust['adminId'] === 0, $cust['phone']);
    check('  keyed on that number', $cust['stageKey'] === 'web-' . WEB_CUST);
    check('nobody signed in is a guest with no number', $guest['role'] === 'customer' && $guest['phone'] === '' && $guest['adminId'] === 0);
    check('  and still has a stage key (hashed session, never blank)', str_starts_with((string) $guest['stageKey'], 'web-guest-') && strlen((string) $guest['stageKey']) > 12, $guest['stageKey']);

    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bossId, 'username' => 'web-boss', 'full_name' => 'Web Test Boss', 'role' => 'support', 'phone' => WEB_BOSS, 'is_active' => 1, 'last_seen' => time()];
    $sup = AiTools::whoIsWeb();
    check('a support-role session is NOT given the office powers', $sup['role'] === 'customer' && $sup['adminId'] === 0, $sup['role']);
    unset($_SESSION[ADMIN_SESSION_KEY]);

    /* ================================================================
     *  2. The catalogue per role and channel
     * ================================================================ */
    echo "\n== the catalogue ==\n";
    $gT = $names(AiTools::catalogue($guest));
    $cT = $names(AiTools::catalogue($cust));
    $aT = $names(AiTools::catalogue($agent));
    $bT = $names(AiTools::catalogue($boss));

    check('a guest may ask, quote and rate', in_array('plan_ticket', $gT, true) && in_array('record_feedback', $gT, true) && in_array('find_ticket', $gT, true));
    check('  but is never offered a report', !in_array('sales_report', $gT, true) && !in_array('site_visitors', $gT, true));
    check('an agent gets sales_report + occupancy_report', in_array('sales_report', $aT, true) && in_array('occupancy_report', $aT, true));
    check('  but NOT site_visitors or the leaderboard', !in_array('site_visitors', $aT, true) && !in_array('agent_leaderboard', $aT, true));
    check('the office gets every report', in_array('site_visitors', $bT, true) && in_array('agent_leaderboard', $bT, true) && in_array('office_day', $bT, true));

    check('issue_ticket is absent for everyone while ai_web_sell is OFF', !in_array('issue_ticket', $gT, true) && !in_array('issue_ticket', $cT, true));
    Settings::set('ai_web_sell', true, 'bool', 'ai', false);
    Settings::flush();
    check('with ai_web_sell ON a signed-in passenger is offered issue_ticket', in_array('issue_ticket', $names(AiTools::catalogue($cust)), true));
    check('  a GUEST still is not — there is no number to sell to', !in_array('issue_ticket', $names(AiTools::catalogue($guest)), true));
    check('  and staff_sell appears for staff', in_array('staff_sell', $names(AiTools::catalogue($agent)), true));
    $waCust = AiTools::whoIs(WEB_CUST) + ['channel' => 'whatsapp'];
    check('WhatsApp keeps following wa_agent_sell (OFF) — unaffected by the web switch', !in_array('issue_ticket', $names(AiTools::catalogue($waCust)), true));
    $refused = $run('issue_ticket', ['name' => 'Ram', 'confirm' => true], $guest + ['role' => 'customer']);
    check('  a guest naming issue_ticket is refused', $refused['ok'] === false, $refused['say']);
    Settings::set('ai_web_sell', false, 'bool', 'ai', false);
    Settings::flush();

    /* ================================================================
     *  3. Reports — a real sale, seen by the right people
     * ================================================================ */
    echo "\n== reports ==\n";
    Settings::set('wa_agent_sell', true, 'bool', 'ai', false);   // the sale itself goes through the WhatsApp desk path
    Settings::flush();
    $agentWa = AiTools::whoIs(WEB_AGENT) + ['channel' => 'whatsapp'];
    $agentWa['turn'] = ++$turn;
    $plan = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D1], $agentWa);
    check('the agent quotes a seat on a throwaway date', $plan['ok'] === true, $plan['say']);
    $agentWa['turn'] = ++$turn;
    $sold = AiTools::run('staff_sell', ['name' => 'Web Test Passenger', 'phone' => WEB_PAX, 'pay' => 'cash', 'confirm' => true], $agentWa);
    check('  and sells it (cash) for a passenger', $sold['ok'] === true && (string) ($sold['data']['pnr'] ?? '') !== '', $sold['say']);
    Settings::set('wa_agent_sell', false, 'bool', 'ai', false);
    Settings::flush();
    $pnr = (string) ($sold['data']['pnr'] ?? '');

    $rep = $run('sales_report', ['period' => 'today'], $boss);
    check('the office sales_report for today counts the sale', $rep['ok'] && (int) ($rep['data']['tickets'] ?? 0) >= 1, 'tickets=' . ($rep['data']['tickets'] ?? '?'));
    check('  with revenue in INR and the whole-company scope', (float) ($rep['data']['revenue'] ?? 0) > 0 && ($rep['data']['scope'] ?? '') === 'whole company');
    check('  and a drawable chart block', isset($rep['data']['chart']) && AiChart::valid($rep['data']['chart']));
    check('  that AiChart can actually paint', AiChart::pngBytes($rep['data']['chart']) !== null);

    $mine = $run('sales_report', ['period' => 'today'], $agent);
    check('the selling agent sees the sale in their own report', $mine['ok'] && (int) ($mine['data']['tickets'] ?? 0) >= 1 && ($mine['data']['scope'] ?? '') === 'own sales only');
    $theirs = $run('sales_report', ['period' => 'today'], $other);
    check('  another agent does NOT', $theirs['ok'] && (int) ($theirs['data']['tickets'] ?? 0) === 0, 'tickets=' . ($theirs['data']['tickets'] ?? '?'));
    $byAgent = $run('sales_report', ['period' => 'today', 'group_by' => 'agent'], $agent);
    check('  and an agent asking for the per-agent breakdown is refused', $byAgent['ok'] === false);

    $lead = $run('agent_leaderboard', ['period' => 'today'], $boss);
    $rows = $lead['data']['rows'] ?? [];
    $hasAgent = false;
    foreach ($rows as $r) { if (($r['agent'] ?? '') === 'Web Test Agent') { $hasAgent = true; } }
    check('the leaderboard names the selling agent', $lead['ok'] && $hasAgent, json_encode(array_slice($rows, 0, 3), JSON_UNESCAPED_UNICODE));
    $leadNo = $run('agent_leaderboard', ['period' => 'today'], $agent);
    check('  and is refused to an agent', $leadNo['ok'] === false);

    $byRoute = $run('sales_report', ['period' => 'custom', 'from' => $today, 'to' => $today, 'group_by' => 'route'], $boss);
    check('a custom range grouped by route works', $byRoute['ok'] && ($byRoute['data']['groupBy'] ?? '') === 'route' && (int) ($byRoute['data']['tickets'] ?? 0) >= 1);
    $back = $run('sales_report', ['period' => 'custom', 'from' => $today, 'to' => addDaysISO($today, -400)], $boss);
    check('  a reversed / over-long range is clamped, not refused', $back['ok'] && $back['data']['to'] === $today);

    $occ = $run('occupancy_report', ['days' => 30], $boss);
    $deps = $occ['data']['departures'] ?? [];
    $found = null;
    foreach ($deps as $d) { if (($d['date'] ?? '') === $D1 && (int) ($d['sold'] ?? 0) >= 1) { $found = $d; } }
    check('occupancy_report lists the throwaway date with the seat sold', $occ['ok'] && $found !== null && (int) $found['capacity'] > 0, $found ? $found['sold'] . '/' . $found['capacity'] : 'not found');
    check('  and every departure carries a fill percentage', $deps !== [] && !array_filter($deps, static fn($d) => !array_key_exists('fillPct', $d)));
    check('  as a percent chart capped at 100', isset($occ['data']['chart']) && ($occ['data']['chart']['max'] ?? 0) === 100);
    check('  and days ahead are clamped to 14', $occ['data']['to'] === addDaysISO($today, 13), $occ['data']['to']);
    $occNo = $run('occupancy_report', [], $guest);
    check('  a guest cannot ask it', $occNo['ok'] === false);

    /* ---- the beacon read back ---- */
    for ($i = 0; $i < 9; $i++) {
        Database::insert('app_events', ['name' => $i % 3 === 0 ? 'search' : 'view', 'session_key' => 'aaaaweb' . str_pad((string) ($i % 3), 25, '0'),
            'props' => json_encode(['view' => $i % 2 ? 'home' : 'my']), 'path' => '/', 'created_at' => date('Y-m-d H:i:s', time() - $i * 60)]);
    }
    $vis = $run('site_visitors', ['days' => 7], $boss);
    check('site_visitors counts visits (distinct session keys) and views', $vis['ok'] && (int) ($vis['data']['totals']['visits'] ?? 0) >= 3 && (int) ($vis['data']['totals']['views'] ?? 0) >= 6,
        json_encode($vis['data']['totals'] ?? [], JSON_UNESCAPED_UNICODE));
    check('  and says who is on the site RIGHT NOW', (int) ($vis['data']['onSiteNow'] ?? 0) >= 3, (string) ($vis['data']['onSiteNow'] ?? '?'));
    check('  with the funnel counters present', isset($vis['data']['totals']['searches'], $vis['data']['totals']['checkout_drops'], $vis['data']['totals']['ticketsSold']));
    check('  and a chart', isset($vis['data']['chart']) && AiChart::valid($vis['data']['chart']));
    $visNo = $run('site_visitors', ['days' => 7], $agent);
    check('  an agent asking for traffic is refused', $visNo['ok'] === false);
    $visClamp = $run('site_visitors', ['days' => 500], $boss);
    check('  days are clamped to 90', $visClamp['ok'] && (int) $visClamp['data']['days'] === 90);

    /* ================================================================
     *  4. Feedback — reputation, into the screens the office reads
     * ================================================================ */
    echo "\n== feedback ==\n";
    $fb5 = $run('record_feedback', ['rating' => 5, 'comment' => 'Ekdam ramro sewa', 'pnr' => $pnr], $cust);
    check('a 5-star from a passenger is saved', $fb5['ok'] && (int) ($fb5['data']['feedbackId'] ?? 0) > 0);
    $row = Database::fetch('SELECT * FROM feedback WHERE id = :i', ['i' => (int) $fb5['data']['feedbackId']]);
    check('  on their own number, with no complaint filed', $row !== null && (string) $row['user_phone'] === WEB_CUST && (int) $row['rating'] === 5 && ($fb5['data']['complaintRef'] ?? '') === '');
    check('  a PNR that is not theirs is not attached', $row !== null && $row['booking_id'] === null);

    $fb1 = $run('record_feedback', ['rating' => 1, 'comment' => 'Bus 2 ghanta late', 'phone' => WEB_PAX, 'name' => 'Web Test Passenger', 'pnr' => $pnr], $agent);
    check('a 1-star logged by the agent for a passenger is saved', $fb1['ok'] && (int) ($fb1['data']['feedbackId'] ?? 0) > 0);
    check('  attached to the PNR the agent sold', (int) ($fb1['data']['bookingId'] ?? 0) > 0);
    check('  AND filed as a complaint with a reference', str_starts_with((string) ($fb1['data']['complaintRef'] ?? ''), 'SHG-C-'), (string) ($fb1['data']['complaintRef'] ?? ''));
    check('  that the Enquiries inbox can see', Database::exists("SELECT 1 FROM enquiries WHERE phone = :p AND source = 'complaint'", ['p' => WEB_PAX]));
    $fb0 = $run('record_feedback', ['rating' => 9], $guest);
    check('a rating outside 1–5 is refused', $fb0['ok'] === false);

    /* ================================================================
     *  5. Audit — channel web, never a blank identity
     * ================================================================ */
    echo "\n== audit ==\n";
    check('report calls are logged with channel = web',
        Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'sales_report' AND channel = 'web' AND ok = 1", ['p' => WEB_BOSS]));
    check('a guest call is logged under its hashed key, not an empty number',
        Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone LIKE 'web-guest-%' AND tool = 'occupancy_report' AND ok = 0", []));
    check('the WhatsApp sale stayed on channel whatsapp',
        Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'staff_sell' AND channel = 'whatsapp'", ['p' => WEB_AGENT]));

    /* ================================================================
     *  6. The entry point and the wiring
     * ================================================================ */
    echo "\n== the entry point ==\n";
    check('with no key the web agent is off', AiAgent::webEnabled() === false);
    check('  and answers null, never throws', AiAgent::handleWeb($guest, 'namaste', 'ne') === null);
    Settings::set('anthropic_api_key', 'sk-ant-test-not-a-real-key', 'string', 'ai', false);
    Settings::flush();
    check('with a key it is on', AiAgent::webEnabled() === true);
    $reset = AiAgent::handleWeb($guest, 'reset', 'hi');
    check('  "reset" is answered locally, in the widget language', is_array($reset) && str_contains((string) $reset['text'], 'नई शुरुआत') && $reset['charts'] === []);
    Settings::set('ai_web_agent_on', false, 'bool', 'ai', false);
    Settings::flush();
    check('  ai_web_agent_on = 0 switches it off again', AiAgent::webEnabled() === false);
    Settings::set('ai_web_agent_on', true, 'bool', 'ai', false);
    Settings::set('anthropic_api_key', '', 'string', 'ai', false);
    Settings::flush();

    $mig = $src('database/upgrade-2026-09-24-ai-sahayak-pro.sql');
    check('the migration seeds ai_web_agent_on ON and ai_web_sell OFF',
        str_contains($mig, "('ai_web_agent_on',   '1'") && str_contains($mig, "('ai_web_sell',       '0'"));
    check('api/ai-chat.php requires CSRF and POST', str_contains($src('api/ai-chat.php'), 'Security::requireCsrf()') && str_contains($src('api/ai-chat.php'), 'Security::requirePost()'));
    check('  and never echoes a key', !str_contains($src('api/ai-chat.php'), 'anthropic_api_key'));
    check('the widget asks the agent first and keeps the rule engine', str_contains($src('assets/js/16-lazy.js'), "shgApi.post('/ai-chat.php'") && str_contains($src('assets/js/16-lazy.js'), 'answerIntent(q)'));
    check('index.php ships the aiAgent flag, never a key', str_contains($src('index.php'), "'aiAgent'") && !preg_match('/sk-ant-/', $src('index.php')));
    check('the admin copilot page is in the nav', str_contains($src('admin/_guard.php'), "'ai-copilot.php'") && is_file($root . '/admin/ai-copilot.php'));
    check('cron/rotate.php sweeps the chart pictures', str_contains($src('cron/rotate.php'), 'AiChart::sweep'));
    check('this suite is registered in the battery', str_contains($src('tests/run-all.php'), "'ai-web-agent-test.php'"));
    check('  and so is the chart suite', str_contains($src('tests/run-all.php'), "'ai-chart-test.php'"));
} catch (Throwable $e) {
    check('no exception escaped the suite', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "  \033[32m{$PASS} passed\033[0m, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
