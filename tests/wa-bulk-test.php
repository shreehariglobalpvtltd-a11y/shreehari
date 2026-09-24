<?php
/**
 * =====================================================================
 *  wa-bulk-test.php — many tickets from one WhatsApp list (24 Sep 2026).
 *
 *  includes/wabulk.php reads a pasted list by CODE and sells it through
 *  QuickTicket::sell() after one "ho". This suite pins down:
 *
 *    • READING   every name and mobile comes out exactly as typed, +977 is
 *                kept, a family on one number is one booking, a bad line is
 *                reported by number and never guessed at, a place is not a
 *                passenger, a 9-digit typo with an age glued on is refused
 *    • GATES     customers never see it; staff only while wa_bulk_on
 *    • QUOTE     nothing is sold; the read-back carries every name, number
 *                and the fare; the quote is pinned
 *    • SELL      one ho sells every booking on the passengers' own numbers,
 *                credited to the seller, each berth under its own name; a
 *                second ho sells nothing; a changed fare is refused
 *    • TOOLS     bulk_quote / bulk_issue obey the two-message rule
 *
 *  Creates real bookings on throwaway dates and cleans up after itself.
 *      php tests/wa-bulk-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/wabulk.php';

const WB_AGENT = '9100008001';   // the seller's own mobile
const WB_CUST  = '9100008002';   // a customer number (must never see bulk)
const WB_P1    = '9100008011';   // passengers
const WB_P2    = '9100008012';
const WB_P3    = '9100008013';
const WB_P4    = '9100008014';
const WB_LIKE  = '91000080';    // covers the seller (…8001), the customer (…8002) and the passengers (…8011-14)

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Bulk tickets — the list, the quote, the one ho ===\n\n";

$PINNED = ['wa_bulk_on', 'wa_bulk_max_rows', 'wa_agent_on', 'wa_agent_sell', 'wa_agent_oneshot', 'daily_service_on', 'allow_cod',
           'quick_ticket_customer_on', 'whatsapp_driver', 'whatsapp_notify_customer', 'whatsapp_notify_admin', 'agent_daily_booking_limit'];
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
                Database::update('settings', [
                    'svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                    'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public'],
                ], 'skey = :k', ['k' => $k]);
            } catch (Throwable $e) {}
        }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

Settings::set('wa_bulk_on', true, 'bool', 'ai', false);
Settings::set('wa_bulk_max_rows', 30, 'int', 'ai', false);
Settings::set('wa_agent_on', true, 'bool', 'ai', false);
Settings::set('wa_agent_sell', true, 'bool', 'ai', false);
Settings::set('wa_agent_oneshot', false, 'bool', 'ai', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);   // journal only, no network
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::set('agent_daily_booking_limit', 0, 'int', 'agent', false);
Settings::flush();
// Registered NOW: a failure before the try must not leave wa_bulk_on / selling on.
register_shutdown_function($restoreSettings);

$route = null;
foreach (QuickTicket::routes() as $r) {
    if ($r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $route = $r; break; }
}
if ($route === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; $restoreSettings(); exit(0); }

$today = todayISO();
$D1 = addDaysISO($today, 24);
$D2 = addDaysISO($today, 25);

$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', [
            'username' => $username, 'password_hash' => password_hash('Wa@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name, 'must_change_pw' => 0], 'id = :i', ['i' => $id]);
    return $id;
};
$agentId = $mkStaff('wa-bulk-agent', 'WA Bulk Agent', 'agent', WB_AGENT);

$cleanup = static function () use ($D1, $D2): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'message_logs'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('ai_agent_calls', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        try { Database::delete('bookings', 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('ai_agent_calls', "phone LIKE '" . WB_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_bulk','wa_stage','wa_agent','wa_turn') AND kkey LIKE '" . WB_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '" . WB_LIKE . "%'", []); } catch (Throwable $e) {}
    foreach ([$D1, $D2] as $d) {
        try { Database::delete('seat_locks', 'schedule_id IN (SELECT id FROM schedules WHERE travel_date = :d)', ['d' => $d]); } catch (Throwable $e) {}
    }
};
$cleanup();

$ctxFor = static function (string $phone, int $turn = 0, string $said = ''): array {
    $c = AiTools::whoIs($phone);
    $c['turn'] = $turn;
    $c['channel'] = 'whatsapp';
    $c['messageText'] = $said;
    return $c;
};

$list = "TICKET\nDate: " . $D1 . "\nPay: cash\n"
      . "1. ram thapa " . WB_P1 . " M 34\n"
      . "2. Sita Thapa " . WB_P2 . " F 32\n"
      . "3. Maya Thapa, " . WB_P2 . " F 8\n"
      . "4. Hari Gurung +977 " . WB_P3 . " M\n";

try {
    /* ================================================================
     *  1. Reading the list
     * ================================================================ */
    echo "== the list is read by code ==\n";
    $p = WaBulk::parse($list);
    check('four lines, three bookings (two share a number)', count($p['bookings']) === 3 && $p['pax'] === 4, count($p['bookings']) . ' bookings, ' . $p['pax'] . ' pax');
    check('  no line was rejected', $p['errors'] === [], implode(' | ', $p['errors']));
    check('  the date is read from the header', $p['header']['date'] === $D1, $p['header']['date']);
    check('  payment too', $p['header']['pay'] === 'cash');
    $b1 = $p['bookings'][0]; $b2 = $p['bookings'][1]; $b3 = $p['bookings'][2];
    check('  a lowercase name is title-cased for the ticket', $b1['passengers'][0]['name'] === 'Ram Thapa', $b1['passengers'][0]['name']);
    check('  the mobile is exactly the ten digits typed', $b1['phone'] === WB_P1 && $b2['phone'] === WB_P2 && $b3['phone'] === WB_P3);
    check('  gender and age are read', $b1['passengers'][0]['gender'] === 'Male' && $b1['passengers'][0]['age'] === 34);
    check('  the family rides on one booking, each with their own name',
        count($b2['passengers']) === 2 && $b2['passengers'][0]['name'] === 'Sita Thapa' && $b2['passengers'][1]['name'] === 'Maya Thapa' && $b2['passengers'][1]['age'] === 8);
    check('  +977 marks the Nepali passenger', $b3['country'] === 'NP' && $b1['country'] === '');

    $bad = WaBulk::parse("TICKET\nRam Thapa 98765\nMehsana " . WB_P1 . "\nSita 98765432 1\nGita Rai " . WB_P4 . "\nGita Rai " . WB_P4 . "\n2 seat " . WB_P2);
    check('a short mobile is reported by line, never repaired', count(array_filter($bad['errors'], static fn($e) => str_starts_with($e, 'लाइन 2'))) === 1, implode(' | ', $bad['errors']));
    check('a bus stop is not a passenger', count(array_filter($bad['errors'], static fn($e) => str_starts_with($e, 'लाइन 3') && str_contains($e, 'bus stop'))) === 1);
    check('a 9-digit typo with a number glued on is refused, not joined', count(array_filter($bad['errors'], static fn($e) => str_starts_with($e, 'लाइन 4'))) === 1);
    check('the same person twice is reported', count(array_filter($bad['errors'], static fn($e) => str_starts_with($e, 'लाइन 6'))) === 1);
    check('"2 seat" is not a name', count(array_filter($bad['errors'], static fn($e) => str_starts_with($e, 'लाइन 7'))) === 1);
    check('  only the one good line survives', count($bad['bookings']) === 1 && $bad['bookings'][0]['passengers'][0]['name'] === 'Gita Rai');

    $dev = WaBulk::parse("TICKET\n१. राम थापा " . WB_P1 . " पु ३५\n२. कमला गुरुङ " . WB_P2 . " म\nIndira Nepal " . WB_P3 . " F\nSita Rai " . WB_P4 . " nepal");
    check('a Devanagari list is read: digits, gender words, the name intact',
        count($dev['bookings']) === 4 && $dev['bookings'][0]['passengers'][0]['name'] === 'राम थापा' && $dev['bookings'][0]['passengers'][0]['gender'] === 'Male'
        && $dev['bookings'][0]['passengers'][0]['age'] === 35 && $dev['bookings'][1]['passengers'][0]['name'] === 'कमला गुरुङ' && $dev['bookings'][1]['passengers'][0]['gender'] === 'Female',
        json_encode(array_column(array_map(static fn($b) => $b['passengers'][0], $dev['bookings']), 'name'), JSON_UNESCAPED_UNICODE) . ' ' . implode(' | ', $dev['errors']));
    check('  "Nepal" before the number is a surname, after it a country',
        $dev['bookings'][2]['passengers'][0]['name'] === 'Indira Nepal' && $dev['bookings'][2]['country'] === '' && $dev['bookings'][3]['country'] === 'NP');

    $joined = WaBulk::parse("TICKET\nRam Thapa " . WB_P1 . "\nSita Thapa\nMaya Thapa 6");
    check('a name line without a number joins the booking above it',
        count($joined['bookings']) === 1 && count($joined['bookings'][0]['passengers']) === 3 && $joined['bookings'][0]['passengers'][2]['age'] === 6);

    $free = WaBulk::parse("ticket\nbholi Mehsana bata\nRam Thapa " . WB_P1 . "\nSita Rai " . WB_P2);
    $chatter = WaBulk::parse("TICKET\n1. Ram Thapa " . WB_P1 . "\n2. Sita Thapa " . WB_P2 . "\nbholi Mehsana bata\nsabai ko ticket kaatnu hai\nTotal 2 jana\ndhanyabad\nDate 5 Oct\nMaya Thapa");
    check('chatter after the passengers never becomes a berth: only the bare name joins',
        $chatter['pax'] === 3 && count($chatter['bookings'][1]['passengers']) === 2 && $chatter['bookings'][1]['passengers'][1]['name'] === 'Maya Thapa',
        $chatter['pax'] . ' pax; ' . implode(' | ', $chatter['errors']));
    check('  and each such line is reported', count($chatter['errors']) === 5, (string) count($chatter['errors']));

    $devKeys = WaBulk::parse("TICKET\n1. Ram Thapa " . WB_P1 . "\nमिति: " . $D2 . "\nभुक्तानी: upi");
    check('Devanagari header keys are read (after a passenger too)', $devKeys['header']['date'] === $D2 && $devKeys['header']['pay'] === 'upi' && $devKeys['errors'] === [], implode(' | ', $devKeys['errors']));

    $umar = WaBulk::parse("TICKET\nUmar Khan " . WB_P1 . " M\nRam Thapa " . WB_P2 . " umer 35\nSita Rai " . WB_P3 . " 40 yrs");
    check('Umar keeps his name; "umer 35" and "40 yrs" are ages', $umar['bookings'][0]['passengers'][0]['name'] === 'Umar Khan'
        && $umar['bookings'][1]['passengers'][0]['age'] === 35 && $umar['bookings'][1]['passengers'][0]['name'] === 'Ram Thapa'
        && $umar['bookings'][2]['passengers'][0]['age'] === 40 && $umar['bookings'][2]['passengers'][0]['name'] === 'Sita Rai',
        json_encode(array_map(static fn($b) => $b['passengers'][0], $umar['bookings']), JSON_UNESCAPED_UNICODE));

    $oneLine = WaBulk::parse("ticket\n1) Hari Rai " . WB_P1 . ", 2) Gopal Rai " . WB_P2 . "; 3) Mina Rai " . WB_P3 . " F");
    check('several numbered people on ONE line are split apart', count($oneLine['bookings']) === 3 && $oneLine['bookings'][2]['passengers'][0]['gender'] === 'Female', implode(' | ', $oneLine['errors']));

    check('a header without keys is still understood', $free['header']['date'] === addDaysISO($today, 1) && str_contains(mb_strtolower($free['header']['boarding']), 'mehsana'), $free['header']['date'] . ' / ' . $free['header']['boarding']);

    check('one bare line is NOT a bulk message', !WaBulk::looksLikeBulk('Ram Thapa ' . WB_P1));
    check('  two lines with numbers are', WaBulk::looksLikeBulk("Ram Thapa " . WB_P1 . "\nSita Rai " . WB_P2));
    check('  so is TICKET + one line', WaBulk::looksLikeBulk("TICKET\nRam Thapa " . WB_P1));
    check('  a sentence with a number in it is not', !WaBulk::looksLikeBulk('call Ram on ' . WB_P1 . ' about the payment'));
    check('"FORMAT" asks for the template', WaBulk::isFormatRequest('FORMAT') && WaBulk::isFormatRequest('format pathau') && !WaBulk::isFormatRequest('formatting'));
    check('  and the template names the rules', str_contains(WaBulk::template(), 'TICKET') && str_contains(WaBulk::template(), '+977'));

    Settings::set('wa_bulk_max_rows', 3, 'int', 'ai', false); Settings::flush();
    $over = WaBulk::parse($list);
    check('more passengers than allowed are cut off and said so', $over['pax'] <= 3 && count(array_filter($over['errors'], static fn($e) => str_contains($e, 'बढीमा'))) === 1, $over['pax'] . ' kept');
    Settings::set('wa_bulk_max_rows', 30, 'int', 'ai', false); Settings::flush();

    /* ================================================================
     *  2. Gates
     * ================================================================ */
    echo "\n== who may use it ==\n";
    $cust = $ctxFor(WB_CUST);
    check('a customer number gets nothing from the bulk path', WaBulk::handle($cust, $list) === null && WaBulk::handle($cust, 'FORMAT') === null);
    check('  and no bulk tool in the catalogue', !in_array('bulk_quote', array_column(AiTools::catalogue($cust), 'name'), true));
    $agent = $ctxFor(WB_AGENT);
    check('a seller gets bulk_quote and bulk_issue', in_array('bulk_quote', array_column(AiTools::catalogue($agent), 'name'), true)
        && in_array('bulk_issue', array_column(AiTools::catalogue($agent), 'name'), true));
    Settings::set('wa_bulk_on', false, 'bool', 'ai', false); Settings::flush();
    check('with wa_bulk_on OFF the seller gets nothing either', WaBulk::handle($agent, $list) === null
        && !in_array('bulk_quote', array_column(AiTools::catalogue($agent), 'name'), true));
    Settings::set('wa_bulk_on', true, 'bool', 'ai', false); Settings::flush();

    /* ================================================================
     *  3. The quote
     * ================================================================ */
    echo "\n== the quote ==\n";
    $fmt = WaBulk::handle($agent, 'format');
    check('"format" returns the template', $fmt !== null && str_contains((string) $fmt['text'], 'BULK TICKET'));

    $q = WaBulk::handle($agent, $list);
    check('the list comes back as a quote', $q !== null && str_contains((string) $q['text'], 'BULK QUOTE'), (string) ($q['text'] ?? 'null'));
    check('  every name is read back', $q !== null && str_contains((string) $q['text'], 'Ram Thapa') && str_contains((string) $q['text'], 'Maya Thapa') && str_contains((string) $q['text'], 'Hari Gurung'));
    check('  every number is read back, +977 marked', $q !== null && str_contains((string) $q['text'], substr(WB_P1, 0, 5) . ' ' . substr(WB_P1, 5)) && str_contains((string) $q['text'], '+977 ' . substr(WB_P3, 0, 5)));
    check('  the fare is on every line and the total is there', $q !== null && substr_count((string) $q['text'], '₹') >= 4);
    check('  it asks for ho', $q !== null && str_contains((string) $q['text'], '"ho"'));
    check('  nothing is booked', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'", [], 0) === 0);
    $staged = WaBulk::staged(WB_AGENT);
    check('  the quote is pinned for this seller', $staged !== null && count($staged['items']) === 3 && (float) $staged['total'] > 0);

    $hedged = WaBulk::handle($agent, 'ho tara line 2 ko number galat cha');
    check('"ho tara …" (yes, BUT) is not a yes — nothing is sold', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'", [], 0) === 0);
    check('  and the quote is dropped, so a later ho cannot sell it', WaBulk::staged(WB_AGENT) === null && $hedged === null && WaBulk::handle($agent, 'ho') === null);
    WaBulk::handle($agent, $list);
    $aside = WaBulk::handle($agent, 'SHG-2026-00001 cancel gara');
    check('an unrelated message drops an open quote too', $aside === null && WaBulk::staged(WB_AGENT) === null);
    WaBulk::handle($agent, $list);
    $no = WaBulk::handle($agent, 'no');
    check('"no" drops it', $no !== null && str_contains((string) $no['text'], 'रद्द') && WaBulk::staged(WB_AGENT) === null);
    check('  and a ho now sells nothing', WaBulk::handle($agent, 'ho') === null
        && (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'", [], 0) === 0);

    /* ================================================================
     *  4. One ho
     * ================================================================ */
    echo "\n== one ho sells them all ==\n";
    WaBulk::handle($agent, $list);
    $sold = WaBulk::handle($agent, 'ho');
    check('"ho" sells the list', $sold !== null && str_contains((string) $sold['text'], '3 टिकट काटियो'), (string) ($sold['text'] ?? 'null'));
    $rows = Database::fetchAll("SELECT id, pnr, contact_phone, contact_country_code, id_type, sold_by_admin_id, source, status FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%' ORDER BY id");
    check('  three real bookings exist', count($rows) === 3, (string) count($rows));
    $byPhone = [];
    foreach ($rows as $r) { $byPhone[(string) $r['contact_phone']] = $r; }
    check('  each on its passenger\'s OWN number', isset($byPhone[WB_P1], $byPhone[WB_P2], $byPhone[WB_P3]));
    check('  every one confirmed', count(array_filter($rows, static fn($r) => $r['status'] === 'confirmed')) === 3);
    check('  credited to the seller, filed as agent sales', count(array_filter($rows, static fn($r) => (int) $r['sold_by_admin_id'] === $agentId && $r['source'] === 'agent')) === 3);
    check('  the Nepali passenger is stamped +977', (string) ($byPhone[WB_P3]['contact_country_code'] ?? '') === '977' || str_contains(strtolower((string) ($byPhone[WB_P3]['id_type'] ?? '')), 'nepal'));
    check('  the Indian one is not', (string) ($byPhone[WB_P1]['contact_country_code'] ?? '') !== '977');
    $family = Database::fetchAll('SELECT full_name, age FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $byPhone[WB_P2]['id']]);
    check('  the family has two berths, each under its own name',
        count($family) === 2 && $family[0]['full_name'] === 'Sita Thapa' && $family[1]['full_name'] === 'Maya Thapa' && (int) $family[1]['age'] === 8,
        implode(', ', array_column($family, 'full_name')));
    check('  the reply carries every PNR', $sold !== null && substr_count((string) $sold['text'], 'SHG-') === 3);
    $auditN = (int) Database::scalar("SELECT COUNT(*) FROM ai_agent_calls WHERE tool = 'bulk_issue' AND ok = 1 AND phone = :p AND booking_id IS NOT NULL", ['p' => WB_AGENT], 0);
    check('  each sale is in the audit trail as bulk_issue', $auditN === 3, (string) $auditN);
    check('  the quote is spent', WaBulk::staged(WB_AGENT) === null);
    check('a second ho sells nothing', WaBulk::handle($agent, 'ho') === null
        && (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . WB_LIKE . "%'", [], 0) === 3);

    /* ---- a mixed family is sold as a party, never as "Female" ------ */
    $mixed = "TICKET\nDate: " . $D2 . "\n1. Sita Thapa " . WB_P4 . " F 32\nRam Thapa M 8\n";
    $mq = WaBulk::handle($agent, $mixed);
    $ms = WaBulk::handle($agent, 'ho');
    $mixedRow = Database::fetch("SELECT id FROM bookings WHERE contact_phone = :p AND status = 'confirmed'", ['p' => WB_P4]);
    check('a mixed family sells', $mixedRow !== null, (string) ($ms['text'] ?? ''));
    if ($mixedRow !== null) {
        $seatsMixed = array_column(Database::fetchAll('SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => (int) $mixedRow['id']]), 'seat_no');
        $womenOnly = [];
        try { $womenOnly = array_map('strtoupper', array_map('strval', Seats::femaleSeats('sleeper'))); } catch (Throwable $e) {}
        check('  and not onto a women-only berth', array_intersect(array_map('strtoupper', $seatsMixed), $womenOnly) === [], implode(',', $seatsMixed) . ' vs ' . implode(',', $womenOnly));
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications', 'message_logs', 'ai_agent_calls'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => (int) $mixedRow['id']]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => (int) $mixedRow['id']]);
    }

    /* ---- a booking that lands on another day is said, not hidden --- */
    WaBulk::handle($agent, "TICKET\nDate: " . $D2 . "\nGita Rai " . WB_P4 . " F\n");
    $st2 = WaBulk::staged(WB_AGENT);
    check('(fixture) a one-booking quote is staged', $st2 !== null && count($st2['items']) === 1);
    WaBulk::clear(WB_AGENT);

    /* ---- a changed fare is refused ------------------------------- */
    $again = "TICKET\nDate: " . $D2 . "\nGita Rai " . WB_P4 . " F\n";
    WaBulk::handle($agent, $again);
    $st = WaBulk::staged(WB_AGENT);
    $st['items'][0]['expect']['total'] = (float) $st['items'][0]['expect']['total'] + 500;
    Database::update('kv_store', ['kvalue' => json_encode($st, JSON_UNESCAPED_UNICODE)], 'kscope = :s AND kkey = :k', ['s' => 'wa_bulk', 'k' => WB_AGENT]);
    $changed = WaBulk::handle($agent, 'ho');
    check('a booking whose fare moved since the quote is refused, not sold',
        $changed !== null && str_contains((string) $changed['text'], 'बदलियो')
        && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_P4]), (string) ($changed['text'] ?? ''));

    /* ================================================================
     *  5. The tools obey the two-message rule
     * ================================================================ */
    echo "\n== through the assistant's tools ==\n";
    $tq = AiTools::run('bulk_quote', ['text' => $again], $ctxFor(WB_AGENT, 7));
    check('bulk_quote reads the list', $tq['ok'] === true && count($tq['data']['bookings'] ?? []) === 1, $tq['say']);
    $same = AiTools::run('bulk_issue', ['confirm' => true], $ctxFor(WB_AGENT, 7, 'ho'));
    check('  bulk_issue in the SAME message is refused', $same['ok'] === false && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_P4]), $same['say']);
    // The refusal consumed nothing: quote again, then sell in the next turn.
    AiTools::run('bulk_quote', ['text' => $again], $ctxFor(WB_AGENT, 8));
    $flagOnly = AiTools::run('bulk_issue', ['confirm' => true], $ctxFor(WB_AGENT, 9, 'thik cha tara pahila fare bhannus'));
    check('  a confirm flag without a plain ho in the message is refused', $flagOnly['ok'] === false && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WB_P4]), $flagOnly['say']);
    $next = AiTools::run('bulk_issue', ['confirm' => true], $ctxFor(WB_AGENT, 10, 'ho'));
    check('  and in the NEXT message, with a plain ho, it sells', $next['ok'] === true && Database::exists("SELECT 1 FROM bookings WHERE contact_phone = :p AND status = 'confirmed'", ['p' => WB_P4]), $next['say']);
    check('  a customer cannot reach bulk_issue by name', AiTools::run('bulk_issue', ['confirm' => true], $ctxFor(WB_CUST, 2, 'ho'))['ok'] === false);

    /* ================================================================
     *  6. The wiring
     * ================================================================ */
    echo "\n== the wiring ==\n";
    $root  = dirname(__DIR__);
    $wabot = (string) file_get_contents($root . '/includes/wabot.php');
    check('wabot.php hands a staff list to WaBulk before the local customer engine',
        strpos($wabot, 'WaBulk::handle') !== false && strpos($wabot, 'WaBulk::handle') < strpos($wabot, 'WaBooking::handle'));
    check('  and only for staff', str_contains($wabot, '$isStaff && Settings::getBool(\'wa_bulk_on\''));
    $sql = (string) file_get_contents($root . '/database/upgrade-2026-09-24-wa-manager.sql');
    check('the migration ships bulk OFF', str_contains($sql, "('wa_bulk_on',         '0'"));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'wa-bulk-test.php'));

} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    try { Database::delete('message_logs', "to_number LIKE '%" . WB_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('admins', 'id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "  \033[32m$PASS passed\033[0m, " . ($FAIL > 0 ? "\033[31m$FAIL failed\033[0m" : "0 failed") . "\n\n";
exit($FAIL > 0 ? 1 : 0);
