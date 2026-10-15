<?php
/**
 * =====================================================================
 *  wa-booking-upgrade-test.php — WhatsApp super-fast booking, second wave
 *  (15 Oct 2026).
 *
 *  Pins, against the TEST database, with no AI key and a stubbed Meta
 *  transport (nothing ever leaves this machine):
 *    2A  the Book button asks ONE combined question; "Ram, Surat, bholi, 2"
 *        (Devanagari / Gujarati digits too) is read whole, the bare count
 *        included, without touching the desk's TicketBot::parse; whatever is
 *        missing is asked for in ONE message; the summary carries
 *        [Confirm][Change][Cancel] (a list with pay-on-bus / UPI); CHANGE
 *        keeps the slots; returning passengers are greeted by name and
 *        offered "same as last time" - and still need an explicit yes;
 *    2C  the chat sale carries View / PDF / Share links, the ticket picture
 *        is never put into the chat twice, the WhatsApp confirmation carries
 *        the links, the email of a pay-at-boarding ticket never says "paid";
 *    2D  the daily paid cap (empty = none, 0 = never pay) and Meta's
 *        billable / free counts;
 *    2E  a booking already on the number is offered back, not duplicated;
 *        the website line parses as a sellable request;
 *    2F  wa_inbound (dedupe + replay cron, max 3 attempts, never stale),
 *        the provider_ref index, backoff in the retry cron, masked logs;
 *    2G  Meta delivered / read reach the WhatsApp Manager's book, the
 *        chat-sale figures, the switches and Resend wiring.
 *
 *    php tests/wa-booking-upgrade-test.php
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
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/msgledger.php';
require_once INCLUDE_PATH . '/notify.php';

if (stripos((string) DB_NAME, 'test') === false && !str_starts_with((string) DB_NAME, 'lt_')) {
    fwrite(STDERR, "Refusing: DB_NAME must be a test database (contains 'test' or starts with 'lt_').\n");
    exit(1);
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 400) : '') . "\n"; }
}
/** Reach a private helper without loosening its visibility in production. */
function priv(string $method, array $args) {
    $m = new ReflectionMethod('WaBooking', $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}
function ids(?array $ui): array {
    if ($ui === null) { return []; }
    return array_column(($ui['kind'] ?? '') === 'list' ? (array) ($ui['rows'] ?? []) : (array) ($ui['buttons'] ?? []), 'id');
}
/** The open conversation for a number, as WaBooking stored it. */
function convo(string $digits): ?array {
    $v = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_book' AND kkey = :k", ['k' => $digits], null);
    $j = is_string($v) ? json_decode($v, true) : null;
    return is_array($j) ? $j : null;
}

const WB_LIKE = '901550';                 // every number this suite touches starts with it
const WB_A    = '9015500001';             // combined question, fresh
const WB_B    = '9015500002';             // sale + links + dedupe (Meta window open)
const WB_C    = '9015500003';             // sale without a window (picture rides on the reply)
const WB_D    = '9015500004';             // pending UPI booking, then resume
const WB_E    = '9015500005';             // CHANGE
const WB_F    = '9015500006';             // Devanagari / Gujarati, misc
const WB_G    = '9015500007';             // replay cron
const WB_TAG  = 'wamid.WBTEST';

$tomorrow = addDaysISO(todayISO(), 1);

/* ---- settings: local, no AI, buttons on, no seat picture ---- */
$keep = [];
$want = [];
foreach (['gemini_api_key' => '', 'anthropic_api_key' => '', 'wa_local_first' => '1', 'wa_booking_on' => '1', 'wa_faq_on' => '1',
          'wa_agent_on' => '0', 'wa_bulk_on' => '0', 'wa_login_on' => '0', 'wa_order_menu_on' => '1', 'wa_order_staff_on' => '1',
          'wa_seat_photo_on' => '0', 'allow_cod' => '1', 'upi_id' => '', 'quick_ticket_customer_on' => '1',
          'wa_book_combo_on' => '1', 'wa_policy' => 'meta_first', 'wa_meta_templates_on' => '0', 'wa_paid_daily_limit' => '',
          'wa_meta_free_allowance' => '0', 'whatsapp_default_country' => '91', 'wam_on' => '1', 'aisensy_utility_only' => '1',
          'whatsapp_notify_customer' => '1', 'sms_notify_customer' => '0', 'email_enabled' => '0'] as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    $want[$k] = $v;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();

/* The wa_route counters of today / this month are read and written below: keep the originals. */
$routeKeep = Database::fetchAll("SELECT kkey, kvalue FROM kv_store WHERE kscope = 'wa_route' AND kkey LIKE :m", ['m' => date('Y-m') . '-%']);

$cleanup = static function () use ($keep, $routeKeep): void {
    WaRoute::$transport = null;
    $rows = Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'");
    foreach ($rows as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications',
                  'message_logs', 'wa_ticket_queue', 'delivery_log', 'ticket_links', 'msg_ledger'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id = :p", ['p' => (string) $r['pnr']]); } catch (Throwable $e) {}
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
    }
    foreach (["DELETE FROM kv_store WHERE kkey LIKE '%" . WB_LIKE . "%'",
              "DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE '91" . WB_LIKE . "%'",
              "DELETE FROM message_logs WHERE to_number LIKE '%" . WB_LIKE . "%' OR provider_ref LIKE '" . WB_TAG . "%'",
              "DELETE FROM msg_ledger WHERE to_number LIKE '%" . WB_LIKE . "%' OR provider_ref LIKE '" . WB_TAG . "%'",
              "DELETE FROM wa_inbound WHERE wamid LIKE '" . WB_TAG . "%' OR from_digits LIKE '%" . WB_LIKE . "%'",
              "DELETE FROM rate_limits WHERE identifier LIKE '%" . WB_LIKE . "%' OR identifier LIKE '" . WB_TAG . "%'",
              "DELETE FROM seat_locks WHERE session_id LIKE 'qt%' OR session_id LIKE 'wa%'",
              "DELETE FROM kv_store WHERE kscope = 'wa_route' AND kkey LIKE '" . date('Y-m') . "-%'"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    foreach ($routeKeep as $r) {
        try {
            Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_route', :k, :v, 'test')
                           ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => $r['kkey'], 'v' => $r['kvalue'], 'v2' => $r['kvalue']]);
        } catch (Throwable $e) {}
    }
    foreach ($keep as $k => $v) {
        try {
            if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
    Settings::flush();
};
$cleanup();
/* The first cleanup also restored the original settings - put the suite's own back. */
foreach ($want as $k => $v) { Settings::set($k, $v, 'string', 'whatsapp', false); }
Settings::flush();
// Restore wiped the month's counters back; start this suite from a clean month for its own counters.
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_route' AND kkey LIKE :m", ['m' => date('Y-m') . '-%']);

try {
    /* ================================================================ */
    echo "\n— 2A: the Book button asks ONE combined question\n";
    $r = WaBot::reply('+91' . WB_A, 'ticket');
    check('one message names all four, with an example', str_contains($r['text'], 'Ram, Surat, bholi, 2'), $r['text']);
    check('  Today / Tomorrow to tap (word ids, so "2" stays a count)', ids($r['ui'] ?? null) === ['shg:today', 'shg:tomorrow'], json_encode(ids($r['ui'] ?? null)));
    check('  the conversation waits for the combined answer', (convo(WB_A)['awaiting'] ?? '') === 'combo');
    check('  nothing booked by asking', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_A]));
    $r = WaBot::reply('+91' . WB_A, 'ticket chahiye');
    check('Hindi asks in Hindi', str_contains($r['text'], 'कितने लोग'), $r['text']);
    $r = WaBot::reply('+91' . WB_A, 'ટિકિટ');
    check('Gujarati asks in Gujarati', str_contains($r['text'], 'કેટલા લોકો'), $r['text']);
    $r = WaBot::reply('+91' . WB_A, 'book ticket');
    check('English asks in English', str_contains($r['text'], 'how many') && str_contains($r['text'], 'Ram, Surat, tomorrow, 2'), $r['text']);

    echo "\n— 2D: whatever is missing comes back in ONE message\n";
    $r = WaBot::reply('+91' . WB_A, 'Ram, Surat');
    $st = convo(WB_A);
    check('name and boarding are kept', ($st['slots']['name'] ?? '') === 'Ram' && ($st['slots']['boarding'] ?? '') !== '', json_encode($st['slots'] ?? null));
    check('  date AND passengers asked together', str_contains($r['text'], 'Date') && str_contains($r['text'], 'Passengers') && substr_count($r['text'], "\n") <= 3, $r['text']);
    check('  with Today / Tomorrow buttons', ids($r['ui'] ?? null) === ['shg:today', 'shg:tomorrow']);
    $r = WaBot::reply('+91' . WB_A, 'tomorrow');
    check('a tap fills the date; the one gap left gets its own tappable question', (convo(WB_A)['slots']['date'] ?? '') === $tomorrow
        && ($r['ui']['kind'] ?? '') === 'list' && str_contains($r['text'], 'How many'), $r['text']);
    $r = WaBot::reply('+91' . WB_A, '1');
    check('"1" answers that question - the summary, still unbooked', str_contains($r['text'], 'Please check this booking') && str_contains($r['text'], 'Ram')
        && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_A]), $r['text']);
    check('  [Confirm][Change][Cancel] with pay-on-bus only', ids($r['ui'] ?? null) === ['shg:ho', 'shg:change', 'shg:cancel'], json_encode(ids($r['ui'] ?? null)));
    WaBot::reply('+91' . WB_A, 'cancel');
    check('cancel ends it', !WaBooking::isOpen(WB_A));

    echo "\n— 2A: the combined answer, read whole (desk parser untouched)\n";
    check('TicketBot::parse still reads no count without a seat word', (int) (TicketBot::parse('Ram, Surat, bholi, 2')['seats'] ?? -1) === 0);
    check('bare count: "Ram, Surat, bholi, 2" → 2', priv('bareCount', ['Ram, Surat, bholi, 2', false]) === 2);
    check('bare count: Devanagari digit २', priv('bareCount', ['राम, सूरत, भोलि, २', false]) === 2);
    check('bare count: Gujarati digit ૩', priv('bareCount', ['રામ, સુરત, કાલે, ૩', false]) === 3);
    check('bare count: the last lone number wins ("Ram, Surat, 20 Oct, 3")', priv('bareCount', ['Ram, Surat, 20 Oct, 3', false]) === 3);
    check('bare count: a message that is only the number', priv('bareCount', ['2', false]) === 2);
    check('bare count: more than a customer may book is not a count', priv('bareCount', ['Ram, Surat, bholi, 40', false]) === 0);
    check('bare count: no commas only inside the question ("Ram Surat bholi 2")',
        priv('bareCount', ['Ram Surat bholi 2', true]) === 2 && priv('bareCount', ['Ram Surat bholi 2', false]) === 0);
    check('bare count: never the day after a month word ("Surat Nov 5")', priv('bareCount', ['Surat Nov 5', true]) === 0);
    check('combined line recognised', priv('comboRead', ['Ram, Surat, bholi, 2']) === true);
    check('  not a complaint about a ticket', priv('comboRead', ['mero ticket, naam wrong xa, bholi']) === false);
    check('  not a question', priv('comboRead', ['kati parcha, Surat, bholi?']) === false);
    check('  not three words with no day and no stop', priv('comboRead', ['Ram, Shyam, Hari']) === false);

    $r = WaBot::reply('+91' . WB_F, 'Ram Bahadur, Surat, bholi, 2');
    $st = convo(WB_F);
    check('fresh "Ram Bahadur, Surat, bholi, 2": all four understood', (int) ($st['slots']['seats'] ?? 0) === 2 && ($st['slots']['name'] ?? '') === 'Ram Bahadur'
        && ($st['slots']['date'] ?? '') === $tomorrow && ($st['slots']['boarding'] ?? '') !== '', json_encode($st['slots'] ?? null, JSON_UNESCAPED_UNICODE));
    check('  a party is then asked for every name (the manifest)', str_contains($r['text'], '2'), $r['text']);
    WaBot::reply('+91' . WB_F, 'cancel');
    WaBot::reply('+91' . WB_F, 'राम, सूरत, भोलि, २');
    $st = convo(WB_F);
    check('Devanagari line: count 2, name राम, tomorrow', (int) ($st['slots']['seats'] ?? 0) === 2 && ($st['slots']['name'] ?? '') === 'राम'
        && ($st['slots']['date'] ?? '') === $tomorrow, json_encode($st['slots'] ?? null, JSON_UNESCAPED_UNICODE));
    WaBot::reply('+91' . WB_F, 'cancel');
    WaBot::reply('+91' . WB_F, 'રામ, સુરત, કાલે, ૧');
    $st = convo(WB_F);
    check('Gujarati line: count 1, name રામ, tomorrow', (int) ($st['slots']['seats'] ?? 0) === 1 && ($st['slots']['name'] ?? '') === 'રામ'
        && ($st['slots']['date'] ?? '') === $tomorrow, json_encode($st['slots'] ?? null, JSON_UNESCAPED_UNICODE));
    WaBot::reply('+91' . WB_F, 'cancel');

    /* review fixes (15 Oct 2026) */
    check('"Kartik 5" (a Nepali date) is never 5 seats', priv('bareCount', ['Kartik 5', true]) === 0 && priv('bareCount', ['मंसिर 5', true]) === 0);
    check('…while "Surat 2" still is', priv('bareCount', ['Surat 2', true]) === 2);
    WaBot::reply('+91' . WB_F, 'Hari Prasad, Surat, bholi, 1');
    $before = (string) (convo(WB_F)['slots']['name'] ?? '');
    foreach (['cash ma tirchu', 'online garchu', 'date badalnu'] as $chat) {
        WaBot::reply('+91' . WB_F, $chat);
    }
    check('at the summary, a chatty line never renames the passenger', $before === 'Hari Prasad' && (convo(WB_F)['slots']['name'] ?? '') === 'Hari Prasad',
        json_encode(convo(WB_F)['slots'] ?? null, JSON_UNESCAPED_UNICODE));
    WaBot::reply('+91' . WB_F, 'cancel');

    Settings::set('wa_book_combo_on', '0', 'string', 'whatsapp', false);
    $r = WaBot::reply('+91' . WB_F, 'ticket');
    check('wa_book_combo_on = 0: the guided order is back (which way, two buttons)', ids($r['ui'] ?? null) === ['shg:1', 'shg:2'], $r['text']);
    WaBot::reply('+91' . WB_F, 'cancel');
    Settings::set('wa_book_combo_on', '1', 'string', 'whatsapp', false);

    /* ================================================================ */
    echo "\n— 2A: CHANGE keeps everything already said\n";
    WaBot::reply('+91' . WB_E, 'Sita Gurung, Surat, bholi, 1');
    $r = WaBot::reply('+91' . WB_E, 'change');
    $st = convo(WB_E);
    check('CHANGE shows the current values and waits for the combined answer', str_contains($r['text'], 'Sita Gurung') && ($st['awaiting'] ?? '') === 'combo'
        && ($st['slots']['date'] ?? '') === $tomorrow && (int) ($st['slots']['seats'] ?? 0) === 1, $r['text']);
    check('  with a No-change button', in_array('shg:ok', ids($r['ui'] ?? null), true));
    $r = WaBot::reply('+91' . WB_E, 'Kamrej');
    check('one word changes one thing - the summary is back with it', str_contains($r['text'], 'Kamrej') && str_contains($r['text'], 'Sita Gurung'), $r['text']);
    WaBot::reply('+91' . WB_E, 'change');
    $r = WaBot::reply('+91' . WB_E, 'ok');
    check('"No change" puts the same summary back', (convo(WB_E)['awaiting'] ?? '') === 'confirm' && str_contains($r['text'], 'Kamrej'), $r['text']);
    WaBot::reply('+91' . WB_E, 'change');
    $r = WaBot::reply('+91' . WB_E, 'Hari Prasad');
    check('a new name in the combined answer replaces the old one', str_contains($r['text'], 'Hari Prasad') && !str_contains($r['text'], 'Sita Gurung'), $r['text']);
    WaBot::reply('+91' . WB_E, 'change');
    $r = WaBot::reply('+91' . WB_E, 'change');
    check('"change" is never taken for a passenger\'s name', (convo(WB_E)['slots']['name'] ?? '') === 'Hari Prasad', json_encode(convo(WB_E)['slots'] ?? null));
    check('  nothing was booked by any of it', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_E]));
    WaBot::reply('+91' . WB_E, 'cancel');

    echo "\n— 2A: pay-on-bus AND UPI both on: a list, and the pay choice still closes the sale\n";
    Settings::set('upi_id', 'test@upi', 'string', 'payment', false);
    WaBot::reply('+91' . WB_D, 'Maya Thapa, Surat, ' . $tomorrow . ', 1');
    $st = convo(WB_D);
    check('an ISO date in the combined line is read', ($st['slots']['date'] ?? '') === $tomorrow, json_encode($st['slots'] ?? null));
    $r = WaBot::reply('+91' . WB_D, 'Maya Thapa, Surat, ' . $tomorrow . ', 1');
    check('the summary is a list: pay on bus / UPI / change / cancel', ($r['ui']['kind'] ?? '') === 'list'
        && ids($r['ui'] ?? null) === ['shg:pay cash', 'shg:pay upi', 'shg:change', 'shg:cancel'], json_encode($r['ui'] ?? null, JSON_UNESCAPED_UNICODE));
    $r = WaBot::reply('+91' . WB_D, 'pay upi');
    $bD = Database::fetch('SELECT id, pnr, status FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => WB_D]);
    check('"pay upi" holds the seat as PENDING with the pay link + QR', $bD !== null && (string) $bD['status'] === 'pending'
        && str_contains($r['text'], 'pay.php?pnr=') && str_contains((string) ($r['media'] ?? ''), 'pay-image.php'), $r['text']);

    echo "\n— 2E: that booking is offered back, not refused or duplicated\n";
    $r = WaBot::reply('+91' . WB_D, 'Maya Thapa, Surat, ' . $tomorrow . ', 1');
    check('the same trip again: "use this booking / book another"', $bD !== null && str_contains($r['text'], (string) $bD['pnr'])
        && ids($r['ui'] ?? null) === ['shg:1', 'shg:2'] && (convo(WB_D)['awaiting'] ?? '') === 'dup', $r['text']);
    $r = WaBot::reply('+91' . WB_D, '1');
    check('"use this booking" answers with it (pay link + QR) and closes the chat', $bD !== null && str_contains($r['text'], (string) $bD['pnr'])
        && str_contains($r['text'], 'pay.php?pnr=') && str_contains((string) ($r['media'] ?? ''), 'pay-image.php') && !WaBooking::isOpen(WB_D), $r['text']);
    check('  no second booking', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => WB_D], 0) === 1);
    WaBot::reply('+91' . WB_D, 'Maya Thapa, Surat, ' . $tomorrow . ', 1');
    $r = WaBot::reply('+91' . WB_D, '2');
    check('"book another" goes on to the summary - still waiting for a yes', str_contains($r['text'], 'Please check this booking')
        && (convo(WB_D)['awaiting'] ?? '') === 'confirm', $r['text']);
    check('  still one booking', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => WB_D], 0) === 1);
    WaBot::reply('+91' . WB_D, 'cancel');
    Settings::set('upi_id', '', 'string', 'payment', false);

    /* ================================================================ */
    echo "\n— 2C: the sale carries View / PDF / Share; the picture rides on the reply when nothing sent it\n";
    WaBot::reply('+91' . WB_C, 'Gita Rai, Surat, bholi, 1');
    $r = WaBot::reply('+91' . WB_C, 'ho');
    $bC = Database::fetch('SELECT id, pnr, status FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => WB_C]);
    check('"ho" sells it (pay on the bus: confirmed)', $bC !== null && (string) $bC['status'] === 'confirmed', $r['text']);
    check('  View link (the short ticket link)', str_contains($r['text'], 'ticket/view.php?code=') || str_contains($r['text'], 'img=1'), $r['text']);
    check('  PDF link', str_contains($r['text'], 'download-ticket.php?pnr='));
    check('  Share link', str_contains($r['text'], 'https://wa.me/?text='));
    check('  no Meta window: the picture rides on the reply', $bC !== null && str_contains((string) ($r['media'] ?? ''), (string) $bC['pnr']));
    $audit = $bC !== null ? Database::fetch("SELECT new_value FROM audit_logs WHERE action = 'booking.wa_sale' AND entity_id = :p ORDER BY id DESC LIMIT 1", ['p' => (string) $bC['pnr']]) : null;
    $av = is_array($audit) ? json_decode((string) $audit['new_value'], true) : null;
    check('2G: the sale is audited with seconds and messages', is_array($av) && is_numeric($av['seconds'] ?? null) && (int) ($av['messages'] ?? 0) === 2,
        (string) ($audit['new_value'] ?? 'no audit row'));

    echo "\n— 2C: inside the Meta window the fan-out sends the picture - the reply does not send it again\n";
    $calls = [];
    $seq   = 0;
    WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$calls, &$seq): array {
        $seq++;
        $calls[] = ['to' => $to, 'text' => $text, 'image' => $image];
        return ['success' => true, 'message_id' => WB_TAG . 'OUT' . $seq, 'error' => ''];
    };
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'test')
                   ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => '91' . WB_B, 'v' => (string) time(), 'v2' => (string) time()]);
    WaBot::reply('+91' . WB_B, 'Ram Kumar, Surat, tomorrow, 1');
    $r = WaBot::reply('+91' . WB_B, 'yes');
    $bB = Database::fetch('SELECT id, pnr, status FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => WB_B]);
    $pics = array_values(array_filter($calls, static fn (array $c): bool => $c['to'] === '91' . WB_B && $c['image'] !== null && str_contains((string) $c['image'], (string) ($bB['pnr'] ?? '#'))));
    check('the confirmation sent the ticket picture once, free on Meta', $bB !== null && count($pics) === 1, json_encode(array_column($calls, 'to')));
    check('  the chat reply carries no second copy', ($r['media'] ?? null) === null, (string) ($r['media'] ?? ''));
    check('  and says it is above, with the links', str_contains($r['text'], 'just above') && str_contains($r['text'], 'download-ticket.php?pnr='), $r['text']);
    $conf = $pics[0]['text'] ?? '';
    check('the WhatsApp confirmation carries the View and PDF links', str_contains($conf, 'View:') && str_contains($conf, 'PDF: ') && str_contains($conf, 'download-ticket.php'), $conf);
    $logged = $bB !== null ? (string) Database::scalar("SELECT body FROM message_logs WHERE booking_id = :b AND purpose = 'ticket' ORDER BY id DESC LIMIT 1", ['b' => (int) $bB['id']], '') : '';
    check('2F: the logged copy hides the ticket keys', $logged !== '' && !preg_match('~[?&]k=[A-Za-z0-9]{8,}~', $logged)
        && !preg_match('~view\.php\?code=[A-Za-z0-9]{6,}~', $logged) && str_contains($logged, '[hidden]'), $logged);
    WaRoute::$transport = null;
    $src = (string) file_get_contents(INCLUDE_PATH . '/notify.php');
    check('the confirmation email of a pay-at-boarding ticket says what is owed, never "paid"',
        str_contains($src, "to pay in cash at the counter or when boarding") && str_contains($src, '$codDue > 0'));

    echo "\n— 2A: a returning passenger: greeted by name, offered the last trip, still asked to confirm\n";
    check('knownName reads the manifest (bookings has no name column)', priv('knownName', [WB_C]) === 'Gita Rai', (string) priv('knownName', [WB_C]));
    $r = WaBot::reply('+91' . WB_C, 'ticket');
    check('the combined question offers "same as last time"', in_array('shg:same as last time', ids($r['ui'] ?? null), true)
        && str_contains($r['text'], 'Gita Rai'), $r['text']);
    $r = WaBot::reply('+91' . WB_C, 'same as last time');
    $st = convo(WB_C);
    check('one tap fills name, boarding and party - the date is still asked', ($st['slots']['name'] ?? '') === 'Gita Rai'
        && ($st['slots']['boarding'] ?? '') !== '' && (int) ($st['slots']['seats'] ?? 0) === 1 && empty($st['slots']['date'])
        && ($st['slots']['_comboField'] ?? '') === 'date', json_encode($st['slots'] ?? null, JSON_UNESCAPED_UNICODE));
    $r = WaBot::reply('+91' . WB_C, '3');
    check('then the summary - the dup offer or the yes, never a sale by itself',
        (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => WB_C], 0) === 1
        && in_array((string) (convo(WB_C)['awaiting'] ?? ''), ['confirm', 'dup'], true), $r['text']);
    WaBot::reply('+91' . WB_C, 'cancel');

    /* ================================================================ */
    echo "\n— 2D: the daily cap on paid messages\n";
    check('empty = no cap', WaRoute::paidLimit() === null && WaRoute::paidRefusal() === '');
    check('  a utility template passes', WaRoute::aisensyRefusal('shg_ticket_confirmed') === '');
    Settings::set('wa_paid_daily_limit', '0', 'string', 'whatsapp', false);
    check('0 = never pay: every AiSensy template is refused', str_contains(WaRoute::aisensyRefusal('shg_ticket_confirmed'), 'wa_paid_daily_limit'));
    check('  …except the login code (a customer must always be able to sign in)', WaRoute::aisensyRefusal('shg_login') === '' && WaRoute::isLoginTemplate('shg_otp'));
    Settings::set('wa_meta_templates_on', '1', 'string', 'whatsapp', false);
    $tplCalls = 0;
    WaRoute::$transport = static function () use (&$tplCalls): array { $tplCalls++; return ['success' => true, 'message_id' => WB_TAG . 'TPL', 'error' => '']; };
    check('  and a Meta template is not even attempted', WaRoute::metaTemplate('91' . WB_G, 'shg_ticket', 'en', [], null, 'ticket') === null && $tplCalls === 0);
    Settings::set('wa_paid_daily_limit', '2', 'string', 'whatsapp', false);
    $mt = WaRoute::metaTemplate('91' . WB_G, 'shg_ticket', 'en', [], null, 'ticket');
    check('under the cap a Meta template goes and is counted', $mt !== null && $tplCalls === 1 && WaRoute::paidToday() === 1, (string) WaRoute::paidToday());
    MsgLedger::recordAiSensy('91' . WB_G, 'shg_ticket_confirmed', ['type' => 'template'], ['success' => true, 'message_id' => '']);
    check('an accepted AiSensy template is counted as paid', WaRoute::paidToday() === 2);
    MsgLedger::recordAiSensy('91' . WB_G, 'text', ['type' => 'text', 'text' => ['body' => 'x']], ['success' => true, 'message_id' => '']);
    MsgLedger::recordAiSensy('91' . WB_G, 'shg_ticket_confirmed', ['type' => 'template'], ['success' => false, 'message_id' => '']);
    check('  a free text, or a refused template, is not', WaRoute::paidToday() === 2);
    check('at the cap: refused with the count', str_contains(WaRoute::aisensyRefusal('shg_ticket_confirmed'), '2/2'));
    check('  and the Meta template too', WaRoute::metaTemplate('91' . WB_G, 'shg_ticket', 'en', [], null, 'ticket') === null && $tplCalls === 1);
    Settings::set('wa_paid_daily_limit', '', 'string', 'whatsapp', false);
    Settings::set('wa_meta_templates_on', '0', 'string', 'whatsapp', false);
    WaRoute::$transport = null;
    check('back to empty: no cap again', WaRoute::aisensyRefusal('shg_ticket_confirmed') === '');

    echo "\n— 2D: Meta's billable / free, once per message\n";
    $b0 = WaRoute::monthCount('billable');
    $f0 = WaRoute::monthCount('metafree');
    WaRoute::noteMetaBilling(WB_TAG . 'B1', 'delivered', ['billable' => true, 'category' => 'utility']);
    WaRoute::noteMetaBilling(WB_TAG . 'B1', 'read', ['billable' => true, 'category' => 'utility']);
    WaRoute::noteMetaBilling(WB_TAG . 'F1', 'delivered', ['billable' => false, 'category' => 'service']);
    WaRoute::noteMetaBilling(WB_TAG . 'S1', 'sent', ['billable' => false]);
    check('a billable delivery counts once (its read does not count again)', WaRoute::monthCount('billable') === $b0 + 1);
    check('a free one counts as free; a "sent" counts nothing', WaRoute::monthCount('metafree') === $f0 + 1);
    Settings::set('wa_meta_free_allowance', '1000', 'string', 'whatsapp', false);
    $rs = WaRoute::stats();
    check('the Routing card: allowance minus billable', $rs['allowance'] === 1000 && $rs['allowanceLeft'] === 1000 - $rs['billableMonth'], json_encode($rs));
    Settings::set('wa_meta_free_allowance', '0', 'string', 'whatsapp', false);

    echo "\n— the Routing card no longer counts a paid Meta template as free\n";
    $s0 = WaRoute::stats();
    Notify::logOutbound('91' . WB_G, 'free ticket text', 'sent', ['provider' => 'cloud_api', 'sid' => WB_TAG . 'L1']);                       // no purpose (NULL)
    Notify::logOutbound('91' . WB_G, 'Template shg_ticket (Meta)', 'sent', ['provider' => 'cloud_api', 'sid' => WB_TAG . 'L2', 'purpose' => 'ticket']);
    Notify::logOutbound('91' . WB_G, 'bot says hi', 'sent', ['provider' => 'cloud_api', 'sid' => WB_TAG . 'L3', 'purpose' => 'bot_reply']);
    $s1 = WaRoute::stats();
    check('a row with NO purpose is counted (it used to vanish)', $s1['freeToday'] === $s0['freeToday'] + 1, $s0['freeToday'] . ' → ' . $s1['freeToday']);
    check('  a Meta template is counted as paid, not free', $s1['metaTplToday'] === $s0['metaTplToday'] + 1);
    check('  a bot reply is neither', $s1['free7'] === $s0['free7'] + 1);

    /* ================================================================ */
    echo "\n— 2G: Meta delivered / read reach the WhatsApp Manager's book\n";
    define('SHG_WA_INBOUND_REPLAY', true);
    ob_start();
    require_once ROOT_PATH . '/whatsapp/webhook.php';
    $printed = (string) ob_get_clean();
    check('the webhook loads as a library for the replay cron (no HTTP part runs)', $printed === ''
        && function_exists('wh_process_inbound') && function_exists('wh_status') && function_exists('wh_queue_add'), $printed);
    Notify::logOutbound('91' . WB_G, 'a free ticket on Meta', 'sent', ['provider' => 'cloud_api', 'sid' => WB_TAG . 'D1', 'purpose' => 'ticket']);
    wh_status(['id' => WB_TAG . 'D1', 'status' => 'delivered', 'recipient_id' => '91' . WB_G, 'pricing' => ['billable' => false, 'category' => 'service']]);
    $led = Database::fetch('SELECT status, category FROM msg_ledger WHERE provider_ref = :r', ['r' => WB_TAG . 'D1']);
    check('delivered: the book says delivered (copied if the sync had not yet)', ($led['status'] ?? '') === 'delivered', json_encode($led));
    check('  Meta said free: no cost on it', ($led['category'] ?? '') === 'service');
    wh_status(['id' => WB_TAG . 'D1', 'status' => 'read', 'recipient_id' => '91' . WB_G]);
    check('read: the book says read', (string) Database::scalar('SELECT status FROM msg_ledger WHERE provider_ref = :r', ['r' => WB_TAG . 'D1'], '') === 'read');
    wh_status(['id' => WB_TAG . 'D1', 'status' => 'delivered', 'recipient_id' => '91' . WB_G]);
    MsgLedger::sync(2000);
    check('a late delivered, and the next sync, never move it back', (string) Database::scalar('SELECT status FROM msg_ledger WHERE provider_ref = :r', ['r' => WB_TAG . 'D1'], '') === 'read');
    check('message_logs keeps "sent" (its own vocabulary)', (string) Database::scalar('SELECT status FROM message_logs WHERE provider_ref = :r', ['r' => WB_TAG . 'D1'], '') === 'sent');

    echo "\n— 2F: the inbound queue - dedupe, replay, never twice, never stale\n";
    check('a new message is queued', wh_queue_add(WB_TAG . 'Q1', '91' . WB_G, 'text', ['id' => WB_TAG . 'Q1']) === true);
    check('  the same wamid again is a duplicate', wh_queue_add(WB_TAG . 'Q1', '91' . WB_G, 'text', ['id' => WB_TAG . 'Q1']) === false);
    wh_queue_mark(WB_TAG . 'Q1', 'done');
    check('  and is marked done', (string) Database::scalar('SELECT status FROM wa_inbound WHERE wamid = :w', ['w' => WB_TAG . 'Q1'], '') === 'done');
    check('the provider_ref index exists', Database::exists("SHOW INDEX FROM message_logs WHERE Key_name = 'ix_msg_ref'"));

    // A message left pending by a crash 5 minutes ago, one 2 hours ago, and one out of attempts.
    $mk = static function (string $id, string $text, int $agoMin, int $attempts, string $status = 'pending'): void {
        $msg = ['from' => '91' . WB_G, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]];
        Database::run(
            "INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, attempts, next_at, created_at)
             VALUES (:w, :f, 'text', :p, :s, :a, NOW() - INTERVAL 1 MINUTE, NOW() - INTERVAL :m MINUTE)",
            ['w' => $id, 'f' => '91' . WB_G, 'p' => json_encode($msg), 's' => $status, 'a' => $attempts, 'm' => $agoMin]
        );
    };
    // review fix: the inline answer is given 10 minutes before a replay may touch it
    $mk(WB_TAG . 'R1', 'menu', 12, 1);
    $mk(WB_TAG . 'R2', 'menu', 120, 1);
    $mk(WB_TAG . 'R3', 'menu', 12, 3, 'failed');
    $mk(WB_TAG . 'R0', 'menu', 4, 1);                  // still inside the inline hold
    /* an older stuck message from a number whose NEWER message was answered */
    $msgF = ['from' => '91' . WB_F, 'id' => WB_TAG . 'R4', 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'menu']];
    Database::run("INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, attempts, next_at, created_at)
                   VALUES (:w, :f, 'text', :p, 'pending', 1, NOW() - INTERVAL 1 MINUTE, NOW() - INTERVAL 15 MINUTE)",
        ['w' => WB_TAG . 'R4', 'f' => '91' . WB_F, 'p' => json_encode($msgF)]);
    Database::run("INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, attempts, next_at, created_at)
                   VALUES (:w, :f, 'text', NULL, 'done', 1, NULL, NOW() - INTERVAL 14 MINUTE)", ['w' => WB_TAG . 'R5', 'f' => '91' . WB_F]);
    $runCron = static function (): string {
        $cmd = escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg((string) php_ini_loaded_file()) . ' '
             . escapeshellarg(ROOT_PATH . '/cron/wa-inbound.php') . ' 2>&1';
        putenv('SHG_DB=' . DB_NAME);
        return (string) shell_exec($cmd);
    };
    $out = $runCron();
    $row = static fn (string $w): array => Database::fetch('SELECT status, attempts, last_error FROM wa_inbound WHERE wamid = :w', ['w' => $w]) ?? [];
    check('the stuck message is answered by the replay', ($row(WB_TAG . 'R1')['status'] ?? '') === 'done' && (int) ($row(WB_TAG . 'R1')['attempts'] ?? 0) === 2, $out);
    check('  and the bot reply went to the sender (logged)', Database::exists(
        "SELECT 1 FROM message_logs WHERE to_number LIKE :t AND purpose = 'bot_reply'", ['t' => '%' . WB_G]), $out);
    check('a message two hours old is never answered late', ($row(WB_TAG . 'R2')['status'] ?? '') === 'failed' && (int) ($row(WB_TAG . 'R2')['attempts'] ?? 0) === 1);
    check('a message still inside the 10-minute inline hold is not replayed', ($row(WB_TAG . 'R0')['status'] ?? '') === 'pending' && (int) ($row(WB_TAG . 'R0')['attempts'] ?? 0) === 1);
    check('an older message superseded by a newer answered one is closed, not replayed', ($row(WB_TAG . 'R4')['status'] ?? '') === 'done'
        && str_contains((string) ($row(WB_TAG . 'R4')['last_error'] ?? ''), 'superseded'));
    check('a message out of attempts is left alone',($row(WB_TAG . 'R3')['status'] ?? '') === 'failed' && (int) ($row(WB_TAG . 'R3')['attempts'] ?? 0) === 3);
    $n0 = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number LIKE :t AND purpose = 'bot_reply'", ['t' => '%' . WB_G], 0);
    $runCron();
    check('a second run answers nothing twice', (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE to_number LIKE :t AND purpose = 'bot_reply'", ['t' => '%' . WB_G], 0) === $n0);

    echo "\n— 2F: backoff in the ticket retry, masked file logs\n";
    $retry = (string) file_get_contents(ROOT_PATH . '/cron/whatsapp-retry.php');
    check('the retry cron waits 15, 30, 60 … minutes (12 h at most)', str_contains($retry, 'LEAST(720, 15 * POW(2, GREATEST(tries - 1, 0))) MINUTE')
        && str_contains($retry, 'm.created_at AS last_at'));
    check('waMaskPhone: India', waMaskPhone('919876543210') === '91••••••3210', waMaskPhone('919876543210'));
    check('waMaskPhone: Nepal', waMaskPhone('9779812345678') === '977••••••5678', waMaskPhone('9779812345678'));
    check('waMaskPhone: not a number is left as it is', waMaskPhone('-') === '-');
    logWhatsAppEvent('wbtest', '919015500099', [], ['status' => 'X']);
    // The log is shared with every other process on this machine: find OUR line.
    $last = '';
    foreach (@file(WHATSAPP_LOG_DIR . '/' . date('Y-m-d') . '.log') ?: [] as $ln) {
        if (str_contains($ln, '] WBTEST |')) {
            $last = $ln;
        }
    }
    check('the file log keeps only the code and the last 4 digits', str_contains($last, '91••••••0099') && !str_contains($last, '919015500099'), $last);
    $mask = new ReflectionMethod('MsgLedger', 'mask');
    $mask->setAccessible(true);
    $masked = (string) $mask->invoke(null, 'PDF: https://x/download-ticket.php?pnr=SHG-1&k=abcdef0123456789 View: https://x/ticket/view.php?code=Xk3p9QaT2z');
    check('the WhatsApp Manager book hides &k= and the short code', str_contains($masked, '&k=[hidden]') && str_contains($masked, 'view.php?code=[hidden]'), $masked);

    /* ================================================================ */
    echo "\n— 2G: the chat-sale figures, the switches, Resend\n";
    $ws = WaBooking::stats();
    check('stats: chat sales of 7 days with average messages and time', $ws['chatSales7'] >= 2 && $ws['avgMessages'] !== null && $ws['avgSeconds'] !== null, json_encode($ws));
    WaBot::reply('+91' . WB_A, 'ticket');
    check('stats: an open booking chat is counted', WaBooking::stats()['openChats'] >= 1);
    WaBot::reply('+91' . WB_A, 'cancel');
    $wm = (string) file_get_contents(ROOT_PATH . '/admin/wa-manager.php');
    check('switches: Settings permission + CSRF + audit', str_contains($wm, "\$act === 'switches'") && str_contains($wm, 'Security::verifyCsrf()')
        && str_contains($wm, "if (!\$canEdit)") && str_contains($wm, "Logger::audit('wam.switches'"));
    check('switches cover chat selling, the combined question, AI, routing, policy and the paid cap',
        str_contains($wm, "'wa_booking_on'") && str_contains($wm, "'wa_book_combo_on'") && str_contains($wm, "'wa_agent_on'")
        && str_contains($wm, "'wa_route_on'") && str_contains($wm, 'name="wa_policy"') && str_contains($wm, 'name="wa_paid_daily_limit"'));
    check('a failed feed row offers Resend through booking-view (no new send path)', str_contains($wm, 'value="resend_wa"') && str_contains($wm, '/admin/booking-view.php?pnr='));
    $wp = (string) file_get_contents(ROOT_PATH . '/admin/wa-pending.php');
    check('the hand-over list offers the same Resend, behind payments.verify', str_contains($wp, 'value="resend_wa"') && str_contains($wp, "Auth::can('payments.verify')"));

    echo "\n— 2E: the website line is a request the bot sells from\n";
    $js   = (string) file_get_contents(ROOT_PATH . '/assets/js/05-router.js');
    check('the sheet builds "ticket N seat <stop> <ISO date> to nepal|to india"', str_contains($js, "'ticket ' + pax + ' seat '") && str_contains($js, "'to nepal'"));
    $line = 'ticket 2 seat Surat ' . $tomorrow . ' to nepal';
    $p    = TicketBot::parse($line);
    check('  it parses: 2 seats, the date, Surat, towards Nepal', (int) $p['seats'] === 2 && $p['date'] === $tomorrow && $p['boarding'] !== '' && $p['direction'] === 'toNepal', json_encode($p));
    $r = WaBot::reply('+91' . WB_A, $line);
    check('  and opens a sale that asks only the names', WaBooking::isOpen(WB_A) && (convo(WB_A)['awaiting'] ?? '') === 'name', $r['text']);
    WaBot::reply('+91' . WB_A, 'cancel');
    $back = TicketBot::parse('ticket 1 seat rupaidiha ' . $tomorrow . ' to india');
    check('  coming back: boarded at the border, towards India', $back['direction'] === 'toIndia' && $back['boarding'] !== '', json_encode($back));

    echo "\n— settings shipped by the migration\n";
    foreach (['wa_book_combo_on', 'wa_paid_daily_limit', 'wa_meta_free_allowance', 'wa_booking_on'] as $k) {
        check('settings row ' . $k, Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]));
    }
    check('wa_inbound table', Database::exists("SHOW TABLES LIKE 'wa_inbound'"));
    $mig = (string) file_get_contents(ROOT_PATH . '/database/upgrade-2026-10-15-wa-booking.sql');
    check('the migration never touches wa_faq_on (an owner decision)', !preg_match('/UPDATE\s+`?settings`?.*wa_faq_on/is', $mig));
} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
}

echo "\nwa-booking-upgrade: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
