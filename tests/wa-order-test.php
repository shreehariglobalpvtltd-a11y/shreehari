<?php
/**
 * =====================================================================
 *  wa-order-test.php — the WhatsApp order menu (5 Oct 2026).
 *
 *  "Order a ticket like ordering food": hello -> menu buttons -> which
 *  way -> which day -> how many -> which pickup -> names -> summary with
 *  the payment buttons -> ticket (or the UPI QR). And the same order for
 *  a SELLER's number, sold under their own account to the passenger's
 *  number.
 *
 *  Everything goes through WaBot::reply(), the one door every transport
 *  uses, with no AI key configured: an order must never need a model.
 *
 *    php tests/wa-order-test.php
 *
 *  Writes real bookings, so it refuses anything but a test database and
 *  removes every row it creates.
 * =====================================================================
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

// 15 Oct 2026: the per-builder clones (lt_f1..lt_f5) are test databases too.
if (stripos((string) DB_NAME, 'test') === false && !str_starts_with((string) DB_NAME, 'lt_')) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function ids(?array $ui): array {
    if ($ui === null) { return []; }
    return array_column(($ui['kind'] ?? '') === 'list' ? (array) ($ui['rows'] ?? []) : (array) ($ui['buttons'] ?? []), 'id');
}

const WO_LIKE  = '97000';
const WO_C1    = '9700011101';
const WO_C2    = '9700011102';
const WO_C3    = '9700011103';
const WO_AGENT = '9700011190';
const WO_PAX   = '9700011104';
const WO_C4    = '9700011105';

$keep = [];
/* 15 Oct 2026: a customer's Book button now asks ONE combined question
   (wa_book_combo_on, default on - tests/wa-booking-upgrade-test.php). This
   suite walks the guided one-question-at-a-time order, which stays for
   sellers and behind the switch, so it runs with the switch off and turns
   it on for its own combined-question section at the end. */
foreach (['gemini_api_key' => '', 'anthropic_api_key' => '', 'wa_local_first' => '1', 'wa_booking_on' => '1', 'wa_faq_on' => '1',
          'wa_agent_on' => '0', 'wa_bulk_on' => '0', 'wa_login_on' => '0', 'wa_order_menu_on' => '1', 'wa_order_staff_on' => '1',
          'wa_seat_photo_on' => '0', 'allow_cod' => '1', 'upi_id' => 'test@upi', 'quick_ticket_customer_on' => '1',
          'wa_book_combo_on' => '0'] as $k => $v) {
    // null = the row did not exist: it is removed again afterwards, never left behind as ''.
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();

$agentId = (int) Database::scalar("SELECT id FROM admins WHERE username = 'wa-order-agent'", [], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'wa-order-agent', 'password_hash' => password_hash('Wa@123456', PASSWORD_BCRYPT),
        'full_name' => 'WA Order Agent', 'role' => 'agent', 'phone' => WO_AGENT, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
}

$cleanup = static function () use ($agentId): void {
    $rows = Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WO_LIKE . "%' OR sold_by_admin_id = :a", ['a' => $agentId]);
    foreach ($rows as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'message_logs', 'wa_ticket_queue'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('kv_store', "kkey LIKE '" . WO_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . WO_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '%" . WO_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('seat_locks', "session_id LIKE 'qt%' OR session_id LIKE 'wa%'", []); } catch (Throwable $e) {}
};
$cleanup();

/** Walk the guided questions; returns the reply that is no longer a way/day/seats/pickup question. */
$walk = static function (string $from, array $answers): array {
    $r = WaBot::reply($from, 'ticket book ne');
    foreach ($answers as $a) {
        $r = WaBot::reply($from, $a);
    }
    return $r;
};

try {
    echo "\n— the order command\n";
    check('"ticket" is the order command (Nepali desk)', WaBooking::orderCommand('ticket') === 'ne');
    check('"टिकट बुक" too', WaBooking::orderCommand('टिकट बुक') === 'ne');
    check('the menu button carries its language', WaBooking::orderCommand('ticket book hi') === 'hi');
    check('"book ticket" is English', WaBooking::orderCommand('Book ticket') === 'en');
    check('a sentence is NOT the command', WaBooking::orderCommand('bholi 2 ticket nepal') === null);
    check('a complaint is NOT the command', WaBooking::orderCommand('mero ticket ma naam galat') === null);

    echo "\n— hello opens the menu\n";
    $r = WaBot::reply('+91' . WO_C1, 'hi');
    check('a hello carries three buttons', count(ids($r['ui'] ?? null)) === 3, json_encode($r['ui'] ?? null, JSON_UNESCAPED_UNICODE));
    check('  the first one starts an order', (ids($r['ui'] ?? null)[0] ?? '') === 'shg:ticket book ne');
    check('  the text says the same in words', str_contains($r['text'], 'ticket') && str_contains($r['text'], 'mero ticket'));
    $r = WaBot::reply('+91' . WO_C1, 'menu');
    check('"menu" shows it again', count(ids($r['ui'] ?? null)) === 3);

    echo "\n— a passenger orders: way, day, how many, pickup, names\n";
    $r = WaBot::reply('+91' . WO_C1, 'ticket book ne');
    check('first question: which way, two buttons', ($r['ui']['kind'] ?? '') === 'buttons' && ids($r['ui']) === ['shg:1', 'shg:2'], $r['text']);
    check('  with numbered answers in the text', str_contains($r['text'], '1. ') && str_contains($r['text'], '2. '));
    check('  nothing is booked by asking', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_C1]));

    $r = WaBot::reply('+91' . WO_C1, '1');
    check('then the day: a list of seven', ($r['ui']['kind'] ?? '') === 'list' && count(ids($r['ui'])) === 7, $r['text']);
    $r = WaBot::reply('+91' . WO_C1, '2');
    $toSeats = ($r['ui']['kind'] ?? '') === 'list' && str_contains($r['text'], 'जना');
    check('then how many', $toSeats, $r['text']);
    $r = WaBot::reply('+91' . WO_C1, '2');
    check('then the pickup, from the live run', $r['ui'] !== null && count(ids($r['ui'])) >= 1 && str_contains($r['text'], 'चढ्नुहुन्छ'), $r['text']);
    $r = WaBot::reply('+91' . WO_C1, '1');
    check('then the names of both travellers', str_contains($r['text'], '2') && $r['ui'] === null, $r['text']);
    $r = WaBot::reply('+91' . WO_C1, 'Ram Bahadur 35, Sita Gurung 30');
    check('the summary names both, with the total', str_contains($r['text'], 'Ram Bahadur') && str_contains($r['text'], 'Sita Gurung') && str_contains($r['text'], '₹'), $r['text']);
    // 15 Oct 2026: + CHANGE - four choices, so a list (buttons stop at three).
    check('  and offers pay-on-bus / UPI / change / cancel as a list', ($r['ui']['kind'] ?? '') === 'list'
        && ids($r['ui'] ?? null) === ['shg:pay cash', 'shg:pay upi', 'shg:change', 'shg:cancel'], json_encode(ids($r['ui'] ?? null)));
    check('  still nothing booked', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_C1]));

    $r = WaBot::reply('+91' . WO_C1, 'pay cash');
    $b = Database::fetch('SELECT id, pnr, status, is_cod, sold_by_admin_id FROM bookings WHERE contact_phone = :p', ['p' => WO_C1]);
    check('"pay on the bus" books it, confirmed', $b !== null && (string) $b['status'] === 'confirmed' && (int) $b['is_cod'] === 1, $r['text']);
    check('  two travellers on the manifest', $b !== null && (int) Database::scalar('SELECT COUNT(*) FROM booking_passengers WHERE booking_id = :b', ['b' => (int) $b['id']], 0) === 2);
    check('  the ticket picture comes back', $b !== null && str_contains((string) ($r['media'] ?? ''), (string) $b['pnr']), (string) ($r['media'] ?? ''));

    echo "\n— my ticket\n";
    $r = WaBot::reply('+91' . WO_C1, 'mero ticket ne');
    check('"my ticket" lists the booking just made', $b !== null && str_contains($r['text'], (string) $b['pnr']), $r['text']);
    $r = WaBot::reply('+91' . WO_C3, 'mero ticket');
    check('a number with no ticket is offered the order button', ids($r['ui'] ?? null) === ['shg:ticket book ne'] && !str_contains($r['text'], 'SHG-'), $r['text']);

    echo "\n— pay by UPI now\n";
    $r = $walk('+91' . WO_C2, ['1', '3', '1', '1', 'Maya Thapa']);
    check('one traveller reaches the summary', ids($r['ui'] ?? null) === ['shg:pay cash', 'shg:pay upi', 'shg:change', 'shg:cancel'], $r['text']);
    $r = WaBot::reply('+91' . WO_C2, 'pay upi');
    $b2 = Database::fetch('SELECT id, pnr, status, is_cod FROM bookings WHERE contact_phone = :p', ['p' => WO_C2]);
    check('"UPI now" holds the seat as PENDING', $b2 !== null && (string) $b2['status'] === 'pending' && (int) $b2['is_cod'] === 0, $r['text']);
    check('  and answers with the pay link + QR', $b2 !== null && str_contains($r['text'], 'pay.php?pnr=') && str_contains((string) ($r['media'] ?? ''), 'pay-image.php'));

    echo "\n— cancel is one tap, at any step\n";
    WaBot::reply('+91' . WO_C3, 'ticket book ne');
    WaBot::reply('+91' . WO_C3, '1');
    $r = WaBot::reply('+91' . WO_C3, 'cancel');
    check('cancel ends the order', !WaBooking::isOpen(WO_C3) && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_C3]), $r['text']);

    echo "\n— a sentence is still read, and its question is tappable\n";
    $r = WaBot::reply('+91' . WO_C3, 'malai 2 seat chahiyo');
    check('a sentence opens the reading path', WaBooking::isOpen(WO_C3), $r['text']);
    WaBot::reply('+91' . WO_C3, 'cancel');

    echo "\n— a seller orders for a passenger\n";
    $r = WaBot::reply('+91' . WO_AGENT, 'hi');
    check('a seller is greeted with the seller menu', in_array('shg:mero bikri ne', ids($r['ui'] ?? null), true), json_encode(ids($r['ui'] ?? null)));
    $r = WaBot::reply('+91' . WO_AGENT, 'malai 2 seat chahiyo bholi');
    check('a seller\'s sentence opens a SELLER order, never a ticket on their own number',
        WaBooking::isOpen(WO_AGENT) && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_AGENT]), $r['text']);
    $r = $walk('+91' . WO_AGENT, ['1', '4', '1', '1']);
    check('after the trip the seller is asked whose number', str_contains($r['text'], 'WhatsApp') && WaBooking::isOpen(WO_AGENT), $r['text']);
    $r = WaBot::reply('+91' . WO_AGENT, 'Hari Thapa');
    check('  a name is not a number: asked again', str_contains($r['text'], '0') && !str_contains($r['text'], '₹'), $r['text']);
    $r = WaBot::reply('+91' . WO_AGENT, WO_PAX);
    check('  then the passenger name', $r['ui'] === null && !str_contains($r['text'], '₹'), $r['text']);
    $r = WaBot::reply('+91' . WO_AGENT, 'Hari Thapa');
    check('the seller summary shows passenger number + seller', str_contains($r['text'], WO_PAX) && str_contains($r['text'], 'WA Order Agent'), $r['text']);
    check('  with issue / cancel buttons (no payment choice)', ids($r['ui'] ?? null) === ['shg:ho', 'shg:cancel']);
    $r = WaBot::reply('+91' . WO_AGENT, 'ho');
    $b3 = Database::fetch('SELECT id, pnr, status, sold_by_admin_id FROM bookings WHERE contact_phone = :p', ['p' => WO_PAX]);
    check('yes issues it to the PASSENGER\'s number', $b3 !== null && (string) $b3['status'] === 'confirmed', $r['text']);
    check('  sold under the seller\'s own account', $b3 !== null && (int) $b3['sold_by_admin_id'] === $agentId);
    check('  nothing is booked on the seller\'s own number', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_AGENT]));
    check('  the seller gets the picture and a "next ticket" button', $b3 !== null && str_contains((string) ($r['media'] ?? ''), (string) $b3['pnr']) && ids($r['ui'] ?? null) === ['shg:ticket book ne']);
    $r = WaBot::reply('+91' . WO_AGENT, 'mero bikri ne');
    check('"my sales" counts it', str_contains($r['text'], '1'), $r['text']);

    echo "\n— a walk-in with no phone\n";
    $r = $walk('+91' . WO_AGENT, ['1', '5', '1', '1', '0', 'Gita Rai']);
    check('"0" is accepted as no phone', ids($r['ui'] ?? null) === ['shg:ho', 'shg:cancel'] && str_contains($r['text'], 'नम्बर छैन'), $r['text']);
    $r = WaBot::reply('+91' . WO_AGENT, 'cancel');
    check('  and cancel sells nothing', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId], 0) === 1);

    echo "\n— 15 Oct 2026: the Book button asks a customer ONE combined question\n";
    Settings::set('wa_book_combo_on', '1', 'string', 'whatsapp', false);
    Settings::flush();
    $r = WaBot::reply('+91' . WO_C4, 'ticket book ne');
    check('one message: name, boarding, date, how many, with an example', str_contains($r['text'], 'Ram, Surat, bholi, 2'), $r['text']);
    check('  with Today / Tomorrow to tap', ids($r['ui'] ?? null) === ['shg:today', 'shg:tomorrow'], json_encode(ids($r['ui'] ?? null)));
    $r = WaBot::reply('+91' . WO_C4, 'Hari Thapa, Surat, bholi, 1');
    check('the combined answer goes straight to the summary', str_contains($r['text'], 'Hari Thapa') && str_contains($r['text'], '₹')
        && ids($r['ui'] ?? null) === ['shg:pay cash', 'shg:pay upi', 'shg:change', 'shg:cancel'], $r['text']);
    check('  nothing booked before the yes', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WO_C4]));
    WaBot::reply('+91' . WO_C4, 'cancel');
    $r = WaBot::reply('+91' . WO_AGENT, 'ticket book ne');
    check('a SELLER keeps the guided order (which way, two buttons)', ids($r['ui'] ?? null) === ['shg:1', 'shg:2'], $r['text']);
    WaBot::reply('+91' . WO_AGENT, 'cancel');

    echo "\n— wiring\n";
    $root = dirname(__DIR__);
    $hook = (string) file_get_contents($root . '/whatsapp/webhook.php');
    check('the Meta webhook reads our button ids', str_contains($hook, "str_starts_with(\$id, 'shg:')"));
    check('  and sends buttons with a plain-text fallback', str_contains($hook, 'sendWhatsAppInteractive') && str_contains($hook, 'sendWhatsAppText($from, $reply[\'text\'])'));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'wa-order-test.php'));
} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    try { Database::delete('admins', 'id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    foreach ($keep as $k => $v) {
        if ($v === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, $v, 'string', 'whatsapp', false);
        }
    }
    Settings::flush();
}

echo "\nwa-order: $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
