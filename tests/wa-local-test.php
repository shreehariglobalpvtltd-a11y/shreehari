<?php
/**
 * =====================================================================
 *  wa-local-test.php — the local brain (includes/walocal.php, 9 Oct 2026):
 *  bus live, seats left, timetable, bus & crew, the ticket again, how to
 *  pay, refund rule, Nepal office, help, and the staff summaries — all
 *  answered from the tables with no AI call, Nepali first for staff.
 *
 *  Pins: WaBot::reply really routes through it; it leaves bookings,
 *  complaints, PNRs, greetings and fares to the engines that own them;
 *  a passenger sees only their own trip; an agent only their own book;
 *  every answer is logged as local:<intent>; the switch works.
 *
 *      php tests/wa-local-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/wabot.php';
require_once INCLUDE_PATH . '/walocal.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 400) : '') . "\n"; }
}

const WL_LIKE  = '95000';
const WL_CUST  = '9500011101';   // a passenger with a ticket today
const WL_GUEST = '9500011102';   // nobody we know
const WL_AGENT = '9500011190';
const WL_BOSS  = '9500011191';

$keep = [];
foreach (['wa_local_brain_on' => '1', 'wa_faq_on' => '1', 'wa_local_first' => '1', 'wa_booking_on' => '1', 'wa_agent_on' => '0', 'wa_bulk_on' => '0',
          'wa_login_on' => '0', 'wa_order_menu_on' => '1', 'wa_order_staff_on' => '1', 'wa_seat_photo_on' => '0', 'wa_report_chart_on' => '0',
          'wa_staff_lang' => 'ne', 'wa_driver_phone_on' => '1', 'gemini_api_key' => '', 'anthropic_api_key' => '', 'openai_api_key' => '',
          'upi_id' => 'shg@upi', 'allow_cod' => '1', 'daily_service_on' => '1'] as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();

$mk = static function (string $username, string $role, string $phone, string $name): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        $id = (int) Database::insert('admins', ['username' => $username, 'password_hash' => password_hash('Wl@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0]);
    }
    return $id;
};
$agentId = $mk('wa-local-agent', 'agent', WL_AGENT, 'Local Agent');
$bossId  = $mk('wa-local-boss', 'superadmin', WL_BOSS, 'Local Boss');

$today = todayISO();
$booking = Database::fetch("SELECT b.* FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id
                             WHERE b.status = 'confirmed' ORDER BY b.id DESC LIMIT 1");
$bid  = (int) ($booking['id'] ?? 0);
$pnr  = (string) ($booking['pnr'] ?? '');
$leg  = $bid > 0 ? Database::fetch('SELECT * FROM booking_legs WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid]) : null;
$origPhone = (string) ($booking['contact_phone'] ?? '');
$origAlt   = $booking['alt_phone'] ?? null;
$origDate  = (string) ($leg['travel_date'] ?? '');
$origSched = (int) ($leg['schedule_id'] ?? 0);
// Today's schedule on the leg's route — and a bus + driver on it.
$route = $origSched > 0 ? (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => $origSched], 0) : 0;
$todaySched = $route > 0 ? Seats::scheduleFor($route, $today) : null;
$sid = (int) ($todaySched['id'] ?? 0);
$origBus = $sid > 0 ? Database::fetch('SELECT bus_id, driver_id FROM schedules WHERE id = :s', ['s' => $sid]) : null;
$busId = (int) Database::scalar('SELECT id FROM buses WHERE is_active = 1 ORDER BY id LIMIT 1', [], 0);
$drvId = (int) Database::scalar("SELECT id FROM drivers WHERE is_active = 1 AND full_name <> '' ORDER BY id LIMIT 1", [], 0);
$drv   = $drvId > 0 ? Database::fetch('SELECT * FROM drivers WHERE id = :i', ['i' => $drvId]) : null;
$bus   = $busId > 0 ? Database::fetch('SELECT * FROM buses WHERE id = :i', ['i' => $busId]) : null;

$cleanup = static function () use ($keep, $agentId, $bossId, $bid, $origPhone, $origAlt, $origDate, $origSched, $sid, $origBus): void {
    foreach ($keep as $k => $v) {
        try {
            if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
    Settings::flush();
    if ($bid > 0) {
        try { Database::update('bookings', ['contact_phone' => $origPhone, 'alt_phone' => $origAlt], 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
        try { Database::run('UPDATE booking_legs SET travel_date = :d, schedule_id = :s WHERE booking_id = :b', ['d' => $origDate, 's' => $origSched, 'b' => $bid]); } catch (Throwable $e) {}
    }
    if ($sid > 0 && $origBus !== null) {
        try { Database::update('schedules', ['bus_id' => $origBus['bus_id'], 'driver_id' => $origBus['driver_id']], 'id = :i', ['i' => $sid]); } catch (Throwable $e) {}
    }
    foreach (["DELETE FROM kv_store WHERE kkey LIKE '%" . WL_LIKE . "%'", "DELETE FROM ai_agent_calls WHERE phone LIKE '%" . WL_LIKE . "%'",
              "DELETE FROM rate_limits WHERE identifier LIKE '%" . WL_LIKE . "%'", "DELETE FROM message_logs WHERE to_number LIKE '%" . WL_LIKE . "%'",
              "DELETE FROM admins WHERE id IN ($agentId, $bossId)"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
};
register_shutdown_function($cleanup);

if ($bid > 0) {
    Database::update('bookings', ['contact_phone' => WL_CUST, 'alt_phone' => null, 'sold_by_admin_id' => $booking['sold_by_admin_id']], 'id = :i', ['i' => $bid]);
    if ($sid > 0) {
        Database::run('UPDATE booking_legs SET travel_date = :d, schedule_id = :s WHERE booking_id = :b', ['d' => $today, 's' => $sid, 'b' => $bid]);
        Database::update('schedules', ['bus_id' => $busId ?: null, 'driver_id' => $drvId ?: null], 'id = :i', ['i' => $sid]);
    }
}

$ask  = static fn(string $n, string $q): ?array => WaLocal::answer('+91' . $n, $q);
$bot  = static fn(string $n, string $q): array  => WaBot::reply('+91' . $n, $q);
$txt  = static fn(?array $r): string => (string) ($r['text'] ?? '');

echo "\n=== The local brain (no AI) ===\n\n";

try {
    echo "— help\n";
    $r = $ask(WL_GUEST, 'help');
    check('"help" is answered locally', $r !== null && $r['intent'] === 'help' && str_contains($txt($r), '🤖'), $txt($r));
    $r = $ask(WL_BOSS, 'k k garna milcha');
    check('the office gets the office list, in Nepali', $r !== null && str_contains($txt($r), 'अफिस') && str_contains($txt($r), 'pending'), $txt($r));
    $r = $ask(WL_AGENT, 'help');
    check('an agent gets the agent list (mero bikri)', $r !== null && str_contains($txt($r), 'mero bikri'), $txt($r));

    echo "\n— timetable\n";
    $r = $ask(WL_GUEST, 'timetable');
    check('"timetable" lists every active route', $r !== null && $r['intent'] === 'timetable' && str_contains($txt($r), '🚌') && str_contains($txt($r), '→'), $txt($r));
    check('… with the arrival next day', str_contains($txt($r), 'next day') || str_contains($txt($r), 'भोलिपल्ट') || str_contains($txt($r), 'अगले दिन'), $txt($r));
    $r = $ask(WL_GUEST, 'samay talika pathau');
    check('romanised Nepali is answered in Nepali', $r !== null && str_contains($txt($r), 'समय तालिका'), $txt($r));
    $r = $bot(WL_GUEST, 'time table');
    check('WaBot::reply routes "time table" through the local brain', str_contains($r['text'], '🕒'), $r['text']);

    echo "\n— seats left\n";
    $r = $ask(WL_GUEST, 'khali seat');
    check('"khali seat" (the menu button) gives the counts', $r !== null && $r['intent'] === 'seats_left' && str_contains($txt($r), '💺') && preg_match('/\d+ मध्ये \d+/u', $txt($r)) === 1, $txt($r));
    $r = $ask(WL_GUEST, 'kitni seat khali hai');
    check('Hindi gets Hindi', $r !== null && str_contains($txt($r), 'में से'), $txt($r));
    $r = $ask(WL_AGENT, 'khali seat bholi');
    check('staff: tomorrow, both ways, with the sold count', $r !== null && substr_count($txt($r), '📅') >= 2 && str_contains($txt($r), 'बिकेको'), $txt($r));
    $r = $bot(WL_AGENT, 'khali seat bholi nepal');
    check('WaBot: "khali seat bholi nepal" is seats, not a sale', str_contains($r['text'], '💺'), $r['text']);
    $r = $ask(WL_GUEST, 'bholi 2 seat nepal');
    check('a booking request is NOT claimed', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n— bus live\n";
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus'");
    $r = $ask(WL_GUEST, 'bus kaha pugyo');
    check('no ticket: every bus now (or none) + how to track your own', $r !== null && $r['intent'] === 'bus_live' && str_contains($txt($r), '🚌') && str_contains($txt($r), 'bus kaha'), $txt($r));
    $r = $ask(WL_BOSS, 'bus kata cha');
    check('the office sees the map link', $r !== null && str_contains($txt($r), 'admin/map.php'), $txt($r));
    if ($bid > 0 && $sid > 0) {
        $r = $ask(WL_CUST, 'bus kaha xa');
        check('a passenger with a ticket today: their own trip + TRACK link', $r !== null && str_contains($txt($r), $pnr) && str_contains($txt($r), 'track.php'), $txt($r));
        check('… with the trip state in words, never a made-up position', str_contains($txt($r), '📍') && !str_contains($txt($r), ' km '), $txt($r));
    }
    $r = $ask(WL_GUEST, 'kahile chhutcha');
    check('a timing question is left to WaFaq', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n— bus & crew\n";
    $r = $ask(WL_BOSS, 'driver');
    check('staff: today\'s buses and crew', $r !== null && $r['intent'] === 'bus_crew' && str_contains($txt($r), '🚌 बस र चालक'), $txt($r));
    if ($bus !== null && $sid > 0) {
        check('… names the bus on today\'s schedule', str_contains($txt($r), (string) $bus['bus_number']), $txt($r));
    }
    if ($drv !== null && $sid > 0) {
        check('… and the driver with the number', str_contains($txt($r), (string) $drv['full_name']), $txt($r));
    }
    $r = $ask(WL_GUEST, 'bus number');
    check('a stranger gets the fleet, not a driver number', $r !== null && (str_contains($txt($r), 'Our buses') || str_contains($txt($r), 'हाम्रा बस'))
        && ($drv === null || !str_contains($txt($r), substr(preg_replace('/\D/', '', (string) $drv['phone']) ?? '', -6))), $txt($r));
    if ($bid > 0 && $sid > 0 && $drv !== null) {
        $r = $ask(WL_CUST, 'driver ko number');
        check('a ticketed passenger on the travel day: bus + driver number', $r !== null && str_contains($txt($r), $pnr) && str_contains($txt($r), (string) $drv['full_name']), $txt($r));
        Settings::set('wa_driver_phone_on', '0', 'string', 'whatsapp', false); Settings::flush();
        $r = $ask(WL_CUST, 'driver ko number');
        check('… switch off: name only, number withheld', $r !== null && !str_contains($txt($r), substr(preg_replace('/\D/', '', (string) $drv['phone']) ?? '', -6)), $txt($r));
        Settings::set('wa_driver_phone_on', '1', 'string', 'whatsapp', false); Settings::flush();
    }

    echo "\n— the ticket again\n";
    if ($bid > 0) {
        $r = $ask(WL_CUST, 'ticket pathau');
        check('"ticket pathau": the PNG of their ticket', $r !== null && $r['intent'] === 'my_ticket_png' && ($r['media'] ?? '') === Ticket::imageUrl($pnr) && str_contains($txt($r), $pnr), json_encode($r, JSON_UNESCAPED_UNICODE));
        $r = $ask(WL_GUEST, 'ticket photo pathau');
        check('a stranger gets no ticket, is asked for the PNR', $r !== null && ($r['media'] ?? null) === null && str_contains($txt($r), 'PNR'), $txt($r));
        $r = $ask(WL_GUEST, 'mero ticket ma naam galat cha');
        check('a complaint is left to the assistant', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    echo "\n— pay, refund, Nepal office\n";
    $r = $ask(WL_GUEST, 'payment kasari garne');
    check('how to pay: UPI + cash + counters', $r !== null && $r['intent'] === 'pay_how' && str_contains($txt($r), 'shg@upi') && str_contains($txt($r), 'नगद'), $txt($r));
    $r = $ask(WL_GUEST, 'cancel garda kati firta aaucha');
    check('refund rule from the slabs', $r !== null && $r['intent'] === 'refund_rule' && str_contains($txt($r), '↩️') && str_contains($txt($r), '%'), $txt($r));
    if ($bid > 0) {
        $r = $ask(WL_CUST, 'refund policy');
        check('… a passenger also sees what THEIR ticket gets now', $r !== null && str_contains($txt($r), $pnr), $txt($r));
    }
    $r = $ask(WL_GUEST, 'mero ticket cancel garideu');
    check('a cancel REQUEST is not answered with a policy line', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ask(WL_GUEST, 'nepal office ko number');
    check('Nepal office numbers', $r !== null && $r['intent'] === 'nepal_office' && str_contains($txt($r), '🇳🇵') && str_contains($txt($r), '+977'), $txt($r));

    echo "\n— the office and the agent\n";
    require_once INCLUDE_PATH . '/wabooking.php';
    WaBooking::clear(WL_AGENT);
    WaBooking::clear(WL_BOSS);
    $r = $ask(WL_BOSS, 'aaja ko hisab');
    check('office "hisab": today in numbers + bus fill', $r !== null && $r['intent'] === 'summary' && str_contains($txt($r), '📊') && str_contains($txt($r), 'टिकट:'), $txt($r));
    $r = $ask(WL_BOSS, 'pending');
    check('office "pending": payments waiting', $r !== null && $r['intent'] === 'pending' && str_contains($txt($r), '⏳') && str_contains($txt($r), 'admin/payments.php'), $txt($r));
    $r = $ask(WL_BOSS, 'yatri list');
    check('office passenger list for today: counts + the chalani PICTURE, not every ticket', $r !== null && $r['intent'] === 'passengers' && str_contains($txt($r), '👥')
        && str_contains((string) ($r['media'] ?? ''), 'download-chalan.php') && str_contains((string) $r['media'], 'doc=chalani'), json_encode($r, JSON_UNESCAPED_UNICODE));
    if ($bid > 0 && $sid > 0) {
        $name = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid], '');
        check('… the office text carries counts, no names (the chalani has them)', $name === '' || !str_contains($txt($r), $name), $name . ' / ' . $txt($r));
        $r = $ask(WL_AGENT, 'yatri list');
        check('an agent sees only their own passengers in words (none here), no chalani', $r !== null && ($name === '' || !str_contains($txt($r), $name)) && ($r['media'] ?? null) === null, $txt($r));
    }
    echo "\n— company, places, agents\n";
    $r = $ask(WL_GUEST, 'ceo ko naam k ho');
    check('CEO + company card', $r !== null && $r['intent'] === 'company_info' && str_contains($txt($r), 'CEO') && str_contains($txt($r), 'google.com/maps'), $txt($r));
    $r = $ask(WL_GUEST, 'office ko google map link pathau');
    check('office / counters / pickups with map links', $r !== null && $r['intent'] === 'office_map' && substr_count($txt($r), 'google.com/maps') >= 2, $txt($r));
    $r = $ask(WL_GUEST, 'bus kaha pugyo location');
    check('"bus … location" is still the bus, not the office', $r !== null && $r['intent'] === 'bus_live', json_encode($r, JSON_UNESCAPED_UNICODE));
    $codesKeep = Database::exists("SELECT 1 FROM settings WHERE skey = 'agent_codes'") ? Settings::getString('agent_codes', '') : null;
    Settings::set('agent_codes', json_encode([(string) $agentId => 777]), 'json', 'agent', false); Settings::flush();
    $r = $ask(WL_GUEST, 'SHG-0777 kasko code ho');
    check('an agent code names the agent with number (customer)', $r !== null && $r['intent'] === 'agent_info' && str_contains($txt($r), 'Local Agent') && str_contains($txt($r), WL_AGENT), $txt($r));
    $r = $ask(WL_GUEST, 'SHG-0999');
    check('an unassigned code is said so', $r !== null && str_contains($txt($r), 'SHG-0999'), $txt($r));
    $r = $ask(WL_BOSS, 'agent list');
    check('the office gets every agent with code + number', $r !== null && $r['intent'] === 'agent_info' && str_contains($txt($r), 'SHG-0777') && str_contains($txt($r), 'agents.php'), $txt($r));
    $r = $ask(WL_BOSS, 'agent local ko number');
    check('an agent by name (staff)', $r !== null && str_contains($txt($r), 'Local Agent') && str_contains($txt($r), WL_AGENT), $txt($r));
    $r = $ask(WL_GUEST, 'agent list');
    check('a customer gets the public directory, never the office roster', $r !== null && !str_contains($txt($r), WL_AGENT), $txt($r));
    $r = $ask(WL_GUEST, 'agent le galat ticket kateko paisa firta');
    check('a complaint about an agent is not an agent lookup', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));
    if ($codesKeep === null) { Database::delete('settings', "skey = 'agent_codes'", []); } else { Settings::set('agent_codes', $codesKeep, 'json', 'agent', false); }
    Settings::flush();
    $r = $ask(WL_AGENT, 'hisab');
    check('agent "hisab": their own day, link to the desk', $r !== null && $r['intent'] === 'summary' && str_contains($txt($r), 'Local Agent') && str_contains($txt($r), 'my-desk.php'), $txt($r));
    $r = $ask(WL_GUEST, 'pending');
    check('a customer gets no office list', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));
    $r = $ask(WL_GUEST, 'yatri list');
    check('a customer gets no passenger list', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));

    echo "\n— what is NOT mine\n";
    check('a greeting', $ask(WL_GUEST, 'namaste') === null);
    check('a fare question', $ask(WL_GUEST, 'bhada kati ho') === null);
    check('a PNR', $ask(WL_GUEST, 'SHG-2026-00001 kaha pugyo') === null);
    check('small talk', $ask(WL_GUEST, 'thank you dai') === null);
    $r = $bot(WL_GUEST, 'hi');
    check('WaBot: "hi" still opens the menu', count((array) ($r['ui']['buttons'] ?? [])) === 3, json_encode($r['ui'] ?? null));

    echo "\n— logging, web, switch\n";
    $n = (int) Database::scalar("SELECT COUNT(*) FROM ai_agent_calls WHERE phone = :p AND tool LIKE 'local:%'", ['p' => WL_GUEST], 0);
    check('every local answer is logged as local:<intent>', $n >= 5, "rows=$n");
    check('… including local:timetable', Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'local:timetable'", ['p' => WL_GUEST]));
    $w = WaLocal::answerWeb(['role' => 'customer', 'phone' => '', 'name' => ''], 'timetable', 'en');
    check('the website chat gets the same timetable, in English', $w !== null && $w['intent'] === 'timetable' && str_contains($w['text'], 'Timetable'), json_encode($w, JSON_UNESCAPED_UNICODE));
    check('the website never gets the ticket picture', WaLocal::answerWeb(['role' => 'customer', 'phone' => WL_CUST], 'ticket pathau') === null);
    $w = WaLocal::answerWeb(['role' => 'admin', 'adminId' => $bossId, 'scopeAdminId' => null, 'name' => 'Boss', 'phone' => ''], 'pending', 'en');
    check('the office on the website gets "pending"', $w !== null && $w['intent'] === 'pending', json_encode($w, JSON_UNESCAPED_UNICODE));
    Settings::set('wa_local_brain_on', '0', 'string', 'whatsapp', false); Settings::flush();
    check('switch off: nothing is claimed', $ask(WL_GUEST, 'timetable') === null && WaLocal::answerWeb(['role' => 'customer'], 'timetable') === null);
    Settings::set('wa_local_brain_on', '1', 'string', 'whatsapp', false); Settings::flush();
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
