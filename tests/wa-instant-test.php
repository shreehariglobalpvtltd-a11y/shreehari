<?php
/**
 * tests/wa-instant-test.php — the one-message WhatsApp ticket (15 Oct 2026).
 *
 * Owner: "WhatsApp bata 1 msg ma ticket deliver hos". A COMPLETE order in
 * ONE message is sold at once, no summary + yes (wa_instant_sale):
 *   staff  agents / counter numbers only (default)
 *   all    customers too
 *   off    always the summary + yes
 * The day must be SAID (a default day never sells by itself), and a clear
 * name carries its gender onto the ticket (NameGuess::AUTO, lone traveller).
 *
 *   php tests/wa-instant-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/wabooking.php';
require_once INCLUDE_PATH . '/waorder.php';
require_once INCLUDE_PATH . '/wabot.php';

if (stripos((string) DB_NAME, 'test') === false && !str_starts_with((string) DB_NAME, 'lt_')) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const WI_LIKE  = '97000222';
const WI_AGENT = '9700022290';
const WI_PAX1  = '9700022201';
const WI_PAX2  = '9700022202';
const WI_CUST  = '9700022203';

$keep = [];
foreach (['gemini_api_key' => '', 'anthropic_api_key' => '', 'wa_local_first' => '1', 'wa_booking_on' => '1', 'wa_faq_on' => '1',
          'wa_agent_on' => '0', 'wa_bulk_on' => '0', 'wa_login_on' => '0', 'wa_order_menu_on' => '1', 'wa_order_staff_on' => '1',
          'wa_seat_photo_on' => '0', 'allow_cod' => '1', 'quick_ticket_customer_on' => '1', 'wa_book_combo_on' => '1',
          'wa_instant_sale' => 'staff', 'ai_name_guess_on' => '1'] as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();

$agentId = (int) Database::scalar("SELECT id FROM admins WHERE username = 'wa-instant-agent'", [], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'wa-instant-agent', 'password_hash' => password_hash('Wa@123456', PASSWORD_BCRYPT),
        'full_name' => 'WA Instant Agent', 'role' => 'agent', 'phone' => WI_AGENT, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
}
$cleanup = static function () use ($agentId): void {
    $rows = Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WI_LIKE . "%' OR sold_by_admin_id = :a", ['a' => $agentId]);
    foreach ($rows as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'message_logs', 'wa_ticket_queue'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('kv_store', "kkey LIKE '%" . WI_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . WI_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '%" . WI_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('seat_locks', "session_id LIKE 'qt%' OR session_id LIKE 'wa%'", []); } catch (Throwable $e) {}
};
$cleanup();

/* A pickup this test DB really sells (the first stop of a toNepal route). */
$town = '';
foreach (TicketBot::parse('Surat bholi 1 seat')['boarding'] !== '' ? ['Surat'] : [] as $t) { $town = $t; }
if ($town === '') {
    $sug  = TicketBot::suggest(['text' => 'bholi 1 seat nepal'], null);
    $town = (string) ($sug['plan']['boardingName'] ?? '');
}
$booked = static fn(string $p): ?array => Database::fetch('SELECT id, pnr, status, sold_by_admin_id FROM bookings WHERE contact_phone LIKE :p ORDER BY id DESC LIMIT 1', ['p' => '%' . $p]);

try {
    echo "— an agent: the whole order in ONE message\n";
    $r = WaBot::reply('+91' . WI_AGENT, 'Sita Kumari ' . WI_PAX1 . ' ' . $town . ' bholi 1 seat');
    $b = $booked(WI_PAX1);
    check('one message = a confirmed ticket, no "yes" step', $b !== null && (string) $b['status'] === 'confirmed', ($r['text'] ?? '') . ' [town=' . $town . ']');
    check('  sold under the agent', $b !== null && (int) $b['sold_by_admin_id'] === $agentId);
    check('  the conversation is closed', !WaBooking::isOpen(WI_AGENT));
    $g = $b !== null ? Database::scalar('SELECT gender FROM booking_passengers WHERE booking_id = :b AND is_primary = 1', ['b' => (int) $b['id']]) : null;
    check('  "Sita Kumari" rides as Female (name guess)', $g === 'Female', (string) $g);

    echo "\n— no day said: the summary + yes, never a sale on a default day\n";
    $r = WaBot::reply('+91' . WI_AGENT, 'Hari Thapa ' . WI_PAX2 . ' ' . $town . ' 1 seat');
    check('nothing sold yet', $booked(WI_PAX2) === null, $r['text'] ?? '');
    WaBot::reply('+91' . WI_AGENT, 'cancel');

    echo "\n— switch off: an agent gets the summary\n";
    Settings::set('wa_instant_sale', 'off', 'string', 'whatsapp', false); Settings::flush();
    $r = WaBot::reply('+91' . WI_AGENT, 'Hari Thapa ' . WI_PAX2 . ' ' . $town . ' bholi 1 seat');
    check('off = summary, nothing sold', $booked(WI_PAX2) === null && WaBooking::isOpen(WI_AGENT), $r['text'] ?? '');
    $r = WaBot::reply('+91' . WI_AGENT, 'ho');
    $b2 = $booked(WI_PAX2);
    check('  "ho" then sells it', $b2 !== null && (string) $b2['status'] === 'confirmed', $r['text'] ?? '');

    echo "\n— a customer: staff mode asks, all mode sells\n";
    Settings::set('wa_instant_sale', 'staff', 'string', 'whatsapp', false); Settings::flush();
    $r = WaBot::reply('+91' . WI_CUST, 'Ram Bahadur, ' . $town . ', bholi, 1');
    check('staff mode: a customer still confirms', $booked(WI_CUST) === null, $r['text'] ?? '');
    WaBot::reply('+91' . WI_CUST, 'cancel');
    Database::delete('kv_store', "kkey LIKE '%" . WI_CUST . "%'", []);
    Settings::set('wa_instant_sale', 'all', 'string', 'whatsapp', false); Settings::flush();
    $r = WaBot::reply('+91' . WI_CUST, 'Ram Bahadur, ' . $town . ', bholi, 1');
    $b3 = $booked(WI_CUST);
    check('all mode: the customer\'s one message is a ticket', $b3 !== null, $r['text'] ?? '');
    $g3 = $b3 !== null ? Database::scalar('SELECT gender FROM booking_passengers WHERE booking_id = :b AND is_primary = 1', ['b' => (int) $b3['id']]) : null;
    check('  "Ram Bahadur" rides as Male', $g3 === 'Male', (string) $g3);
} finally {
    $cleanup();
    foreach ($keep as $k => $v) {
        if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Settings::set($k, $v, 'string', 'whatsapp', false); }
    }
    Settings::flush();
}

echo "\nwa-instant: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
