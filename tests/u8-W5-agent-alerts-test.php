<?php
/**
 * =====================================================================
 *  u8-W5-agent-alerts-test.php — two agent-side items of unit W5
 *  (16 Nov 2026).
 *
 *  P8-12b  trip_alert_agents_on (default 0): a driver's breakdown / SOS
 *          also posts ONE line in the office chat of every active agent /
 *          counter who sold seats on that bus.
 *    - switch off: nobody is told
 *    - on: each seller once (their seat count), inactive / cancelled /
 *      office sellers never; no passenger name, phone or GPS in the line
 *    - the same event again adds no second line (uuid per event + agent)
 *    - SOS wording; a row with only live_trip_id finds its schedule
 *    - alertOffice() reports 'agents' and still rings the bell / office
 *
 *  P8-8a   WaAgentRoute::goodMorning: the agent's text gains one line -
 *          this month's sales vs target, %, rank (AgentTargets).
 *    - performanceLine(): the figures; '' with nothing to say
 *    - goodMorning() end to end with the WhatsApp + push senders stubbed:
 *      the line is in the text; the delivery switches are untouched
 *    - P6-11e: past wa_global_daily_cap the good-morning waits (push only)
 *
 *  shari_test only; every row it makes is deleted, every setting restored.
 *    php -c .claude/php-dev.ini tests/u8-W5-agent-alerts-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripevents.php';
require_once INCLUDE_PATH . '/staffmsg.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waagentroute.php';
require_once INCLUDE_PATH . '/agenttargets.php';

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? ' — ' . $why : '') . "\n"; }
}

echo "\n=== W5 · agents told about a breakdown (P8-12b) + good-morning performance line (P8-8a) ===\n\n";

$tag      = 'w5a' . substr((string) time(), -6);
$admins   = [];          // made admin ids
$bookings = [];
$bells    = [];
$tripIds  = [];
$settings = [];          // key => original full row (or null)
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$rlBefore = array_map(static fn(array $r): string => (string) $r['identifier'],
    Database::fetchAll("SELECT identifier FROM rate_limits WHERE bucket = 'wa_gm'"));
$kvMade   = [];
StaffMsg::$pushEnabled = false;                       // nothing leaves the machine

$keep = static function (string $key) use (&$settings): void {
    if (!array_key_exists($key, $settings)) {
        $settings[$key] = Database::fetch('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $key]);
    }
};
$set = static function (string $key, string $value, string $type, string $group) use ($keep): void {
    $keep($key);
    Settings::set($key, $value, $type, $group);
    Settings::flush();
};
$mkAdmin = static function (string $suffix, string $role, int $active, string $phone = '') use (&$admins, $tag): int {
    $id = (int) Database::insert('admins', [
        'username' => $tag . $suffix, 'password_hash' => password_hash('x', PASSWORD_BCRYPT),
        'full_name' => 'W5 ' . ucfirst($suffix) . ' Agent', 'role' => $role, 'is_active' => $active,
    ] + ($phone !== '' ? ['phone' => $phone] : []));
    $admins[] = $id;
    return $id;
};
$mkBooking = static function (int $sid, string $date, int $seller, int $seats, string $status, float $amount) use (&$bookings, $tag): int {
    $pnr = 'SHG-TEST-' . strtoupper($tag) . count($bookings);
    $bid = (int) Database::insert('bookings', [
        'pnr' => $pnr, 'contact_phone' => '9800000099', 'contact_email' => '', 'status' => $status,
        'total_amount' => $amount, 'sold_by_admin_id' => $seller, 'confirmed_at' => date('Y-m-d H:i:s'),
    ]);
    $bookings[] = $bid;
    $leg = (int) Database::insert('booking_legs', [
        'booking_id' => $bid, 'schedule_id' => $sid, 'leg_type' => 'outbound', 'travel_date' => $date,
        'seat_count' => $seats, 'fare_per_seat' => 1800.00, 'leg_total' => 1800.00 * $seats,
    ]);
    for ($i = 1; $i <= $seats; $i++) {
        Database::insert('booking_passengers', [
            'booking_id' => $bid, 'leg_id' => $leg, 'seat_no' => 'W' . $bid . $i, 'full_name' => 'W5 Secret Passenger',
            'passenger_ref' => generatePassengerRef(),
        ]);
    }
    return $bid;
};
$chat = static function (int $agentId): array {
    return Database::fetchAll(
        "SELECT m.* FROM staff_messages m JOIN staff_threads t ON t.id = m.thread_id
          WHERE t.agent_admin_id = :a AND t.kind = 'general' ORDER BY m.id", ['a' => $agentId]);
};

try {
    $route = Database::fetch("SELECT id, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) {
        throw new RuntimeException('no active route');
    }
    $today = date('Y-m-d');
    $sid   = (int) Seats::schedule((int) $route['id'], $today)['id'];

    $A = $mkAdmin('alpha', 'agent', 1, '98000' . substr((string) time(), -5));
    $B = $mkAdmin('bravo', 'counter', 1);
    $C = $mkAdmin('charlie', 'agent', 0);                 // inactive
    $D = $mkAdmin('delta', 'agent', 1);                   // only a cancelled sale
    $mkBooking($sid, $today, $A, 1, 'confirmed', 1800.0);
    $mkBooking($sid, $today, $A, 2, 'pending', 3600.0);
    $mkBooking($sid, $today, $B, 1, 'confirmed', 1800.0);
    $mkBooking($sid, $today, $C, 1, 'confirmed', 1800.0);
    $mkBooking($sid, $today, $D, 1, 'cancelled', 1800.0);
    $super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
    if ($super > 0) {
        $mkBooking($sid, $today, $super, 1, 'confirmed', 1800.0);   // an office sale: never an "agent"
    }

    $ctx = ['bus' => 'W5 Bus 03', 'route' => $route['from_city'] . ' → ' . $route['to_city'], 'date' => $today, 'dep' => '13:00'];
    $ev  = ['id' => 900000000 + random_int(1, 99999999), 'live_trip_id' => null, 'schedule_id' => $sid, 'kind' => 'breakdown',
            'lat' => null, 'lng' => null, 'note' => 'W5 puncture', 'created_at' => date('Y-m-d H:i:s')];

    /* ---------------- P8-12b ---------------- */
    $set('trip_alert_agents_on', '0', 'bool', 'notify');
    check('switch OFF: alertAgents tells nobody', TripEvents::alertAgents($ev, $ctx) === 0 && $chat($A) === [] && $chat($B) === []);

    $set('trip_alert_agents_on', '1', 'bool', 'notify');
    $n = TripEvents::alertAgents($ev, $ctx);
    $ma = $chat($A); $mb = $chat($B);
    check('switch ON: the two active sellers are told', $n === 2 && count($ma) === 1 && count($mb) === 1, 'told ' . $n);
    check('  inactive agent, cancelled-only seller and office seller are not', $chat($C) === [] && $chat($D) === []
        && (int) Database::scalar("SELECT COUNT(*) FROM staff_messages WHERE body LIKE :b", ['b' => '%W5 Bus 03%'], 0) === 2);
    $txt = (string) ($ma[0]['body'] ?? '');
    check('  Nepali first: "बस बिग्रियो · Bus breakdown — W5 Bus 03"', str_starts_with($txt, '🛠️ बस बिग्रियो · Bus breakdown — W5 Bus 03'), $txt);
    check('  the route, the seat count (confirmed + pending = 3) and the office line',
        str_contains($txt, 'रुट · Route: ' . $ctx['route']) && str_contains($txt, 'Your seats on this bus: 3')
        && str_contains($txt, 'कार्यालयले हेर्दैछ') && str_contains($txt, "wait for the office's next message"), $txt);
    check('  the counter sees its own seat count', str_contains((string) ($mb[0]['body'] ?? ''), 'Your seats on this bus: 1'));
    check('  no passenger name, no passenger phone, no GPS link', !str_contains($txt, 'Secret Passenger') && !str_contains($txt, '9800000099') && !str_contains($txt, 'maps'));
    check('  posted from the office side into the agent\'s own office chat', (string) ($ma[0]['side'] ?? '') === 'office'
        && (string) Database::scalar('SELECT uniq_key FROM staff_threads WHERE id = :t', ['t' => (int) $ma[0]['thread_id']], '') === 'g:' . $A);
    check('  one audit row for the fan-out', Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'trip.driver_alert_agents' AND entity_id = :e",
        ['s' => $startAudit, 'e' => (string) $sid]));

    TripEvents::alertAgents($ev, $ctx);
    check('the same event again adds no second line', count($chat($A)) === 1 && count($chat($B)) === 1);

    $sos = ['id' => $ev['id'] + 1, 'kind' => 'sos'] + $ev;
    TripEvents::alertAgents($sos, $ctx);
    check('an SOS is its own line, worded as an emergency', count($chat($A)) === 2 && str_starts_with((string) ($chat($A)[1]['body'] ?? ''), '🆘 आपतकाल · Emergency (SOS)'));
    check('a stop_reached row never alerts agents', TripEvents::alertAgents(['id' => $ev['id'] + 2, 'kind' => 'stop_reached'] + $ev, $ctx) === 0);

    // A row that only knows its live trip finds the schedule through it.
    $tripIds[] = $trip = (int) Database::insert('live_trips', [
        'trip_ref' => 'W5-' . $tag, 'schedule_id' => $sid, 'route_id' => (int) $route['id'], 'departure_at' => date('Y-m-d H:i:s'),
    ]);
    $viaTrip = ['id' => $ev['id'] + 3, 'schedule_id' => null, 'live_trip_id' => $trip] + $ev;
    check('a row with only live_trip_id reaches the same sellers', TripEvents::alertAgents($viaTrip, $ctx) === 2 && count($chat($A)) === 3);

    // Through alertOffice (the driver path): bell + office + agents in one call.
    $sent = [];
    TripEvents::$send = static function (string $to, string $text, string $purpose) use (&$sent): bool { $sent[] = [$to, $purpose]; return true; };
    $set('driver_sos_whatsapp_on', '1', 'bool', 'driver');
    $out = TripEvents::alertOffice(['id' => $ev['id'] + 4] + $ev, $ctx);
    $bells[] = (int) ($out['bell'] ?? 0);
    check('alertOffice: bell rings, agents counted, office WhatsApp unchanged', ($out['bell'] ?? 0) > 0 && ($out['agents'] ?? -1) === 2
        && ($out['whatsapp'] ?? 0) === count(TripEvents::officeNumbers()) && count($sent) === count(TripEvents::officeNumbers()), json_encode($out));
    $set('trip_alert_agents_on', '0', 'bool', 'notify');
    $out = TripEvents::alertOffice(['id' => $ev['id'] + 5] + $ev, $ctx);
    $bells[] = (int) ($out['bell'] ?? 0);
    check('alertOffice with the switch off: agents 0, nothing new in their chat', ($out['agents'] ?? -1) === 0 && count($chat($A)) === 4, json_encode($out));
    TripEvents::$send = null;

    /* ---------------- P8-8a ---------------- */
    $ym = date('Y-m');
    AgentTargets::set($A, $ym, 10000.0, 0, 0.0, 'w5 test', $super);
    $line = WaAgentRoute::performanceLine($A);
    $rank = AgentTargets::rankFor($A, $ym);
    check('performance line: sold vs target and %', str_starts_with($line, '📊 यो महिना · This month: ₹1,800 / ₹10,000 लक्ष्य · target (18%)'), $line);
    check('  and the rank among active agents', $rank['rank'] > 0 && str_contains($line, 'स्थान · rank #' . $rank['rank'] . '/' . $rank['of']), $line);
    $quiet = WaAgentRoute::performanceLine($D);
    check('an agent with no sale and no target gets no line (unless a company target is set)',
        Settings::getFloat('agent_target_monthly', 0.0) > 0 ? str_contains($quiet, '₹0') : $quiet === '', $quiet);
    check('a bad id gets no line', WaAgentRoute::performanceLine(0) === '');

    // goodMorning end to end: A sells on a route that runs today; senders stubbed.
    Database::insertIgnore('agent_route_permissions', ['admin_id' => $A, 'route_id' => (int) $route['id']]);
    $aRow = null;
    foreach (WaAgentRoute::agents() as $ag) {
        if ((int) $ag['id'] === $A) { $aRow = $ag; break; }
    }
    if ($aRow === null) {
        throw new RuntimeException('the test agent is not in WaAgentRoute::agents()');
    }
    foreach (WaAgentRoute::agents() as $intl => $ag) {
        if ((int) $ag['id'] === $A) {
            Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'w5test')
                           ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => (string) $intl, 'v' => (string) time()]);
            $kvMade[] = (string) $intl;
        }
    }
    $set('wa_global_daily_cap', '0', 'int', 'whatsapp');
    $texts = []; $pushed = [];
    WaAgentRoute::$transport = static function (string $to, string $text, ?string $image, ?array $ui) use (&$texts): array {
        $texts[$to] = $text;
        return ['success' => true, 'message_id' => 'w5-' . count($texts), 'error' => ''];
    };
    WaAgentRoute::$pusher = static function (int $adminId, array $payload) use (&$pushed): int { $pushed[] = $adminId; return 1; };
    $tplBefore = Settings::getBool('wa_good_morning_tpl_on', false);
    $gm = WaAgentRoute::goodMorning();
    $mine = '';
    foreach ($texts as $to => $t) {
        if (in_array((string) $to, $kvMade, true)) { $mine = $t; }
    }
    check('goodMorning: the agent got the free text', $mine !== '', json_encode($gm));
    check('  with the performance line between the buses and the question',
        str_contains($mine, "\n\n📊 यो महिना · This month: ₹1,800 / ₹10,000") && strpos($mine, '📊') < strpos($mine, 'आज टिकट पुर्‍याउन'), $mine);
    check('  the template switch was not touched', Settings::getBool('wa_good_morning_tpl_on', false) === $tplBefore);

    // P6-11e: past the daily cap a good-morning waits - the free push still goes.
    Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_gm' AND identifier LIKE :i", ['i' => (string) ($kvMade[0] ?? 'x') . '%']);
    foreach ($kvMade as $k) {
        Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_gm' AND identifier = :i", ['i' => $k . date('Ymd')]);
    }
    WaRoute::count('all');                                   // at least one today
    $set('wa_global_daily_cap', '1', 'int', 'whatsapp');
    $texts = []; $pushed = [];
    WaAgentRoute::goodMorning();
    $mineCapped = false;
    foreach (array_keys($texts) as $to) {
        if (in_array((string) $to, $kvMade, true)) { $mineCapped = true; }
    }
    check('at the daily cap the good-morning WhatsApp waits', $mineCapped === false);
    check('  and the agent gets the free push instead', in_array($A, $pushed, true), json_encode($pushed));
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    WaAgentRoute::$transport = null;
    WaAgentRoute::$pusher    = null;
    TripEvents::$send        = null;
    foreach ($admins as $aid) {
        foreach (Database::fetchAll('SELECT id FROM staff_threads WHERE agent_admin_id = :a', ['a' => $aid]) as $t) {
            try { Database::delete('staff_thread_reads', 'thread_id = :t', ['t' => (int) $t['id']]); } catch (Throwable $ignored) {}
            Database::delete('staff_messages', 'thread_id = :t', ['t' => (int) $t['id']]);
            Database::delete('staff_threads', 'id = :t', ['t' => (int) $t['id']]);
        }
        try { Database::delete('agent_targets', 'agent_admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
        try { Database::delete('agent_route_permissions', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
        try { Database::delete('wa_agent_presence', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $ignored) {}
    }
    foreach ($bookings as $bid) {
        try { Database::delete('message_logs', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $ignored) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
    }
    foreach ($admins as $aid) {
        Database::delete('admins', 'id = :a', ['a' => $aid]);
    }
    foreach ($tripIds as $t) {
        Database::delete('live_trips', 'id = :t', ['t' => $t]);
    }
    foreach (array_filter($bells) as $b) {
        Database::delete('notifications', 'id = :i', ['i' => $b]);
    }
    foreach ($kvMade as $k) {
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k AND updated_by = 'w5test'", ['k' => $k]);
    }
    // good-morning once-a-day marks this test made (other agents' rows from before are kept)
    foreach (Database::fetchAll("SELECT identifier FROM rate_limits WHERE bucket = 'wa_gm'") as $r) {
        if (!in_array((string) $r['identifier'], $rlBefore, true)) {
            Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_gm' AND identifier = :i", ['i' => (string) $r['identifier']]);
        }
    }
    Database::run("DELETE FROM audit_logs WHERE id > :s AND action IN ('trip.driver_alert_agents', 'agent.target_set', 'agent.target_clear')", ['s' => $startAudit]);
    foreach ($settings as $key => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $key]);
        if ($row !== null) {
            Database::insert('settings', $row);
        }
    }
    Settings::flush();
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE :u", ['u' => $tag . '%'], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE :p", ['p' => 'SHG-TEST-' . strtoupper($tag) . '%'], 0);
check('cleanup left nothing behind', $left === 0, (string) $left);

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
