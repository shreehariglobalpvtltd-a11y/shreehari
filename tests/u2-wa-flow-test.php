<?php
/**
 * =====================================================================
 *  u2-wa-flow-test.php — the WhatsApp booking FORM v2 (19 Nov 2026, WA-3).
 *
 *    - the Flow JSON: only name + mobile required, mobile prefilled, a
 *      +91 / +977 picker, the next 7 days, pickup / seats / pay preselected;
 *    - screenData: the sender's own number and country, direction-aware
 *      pickups (a +977 sender sees the way to India first);
 *    - readForm / handleReply: a bad field is asked for AGAIN on its own
 *      (chat question + the filled-in form), nothing sold; a typed answer
 *      then continues the same order; one form = one sale;
 *    - ownership / publish: a form from another WhatsApp account is dropped
 *      and a fresh one made here; nothing is changed when Meta cannot be
 *      asked; the form goes ON only when Meta says PUBLISHED;
 *    - the unpaid answer as ONE "Pay ₹X" call-to-action (fake sender), the
 *      form ticket's edit buttons noted for the routing cron.
 *
 *  No Meta call: WaFlow::$graphStub and WaBooking::$ctaFn stand in. Writes
 *  real bookings to the TEST database only (numbers 90000xxxxx) and removes
 *  every row and setting it touched.
 *
 *    php tests/u2-wa-flow-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
defined('META_ACCESS_TOKEN') || define('META_ACCESS_TOKEN', 'U2-TEST-TOKEN');
defined('META_WABA_ID')      || define('META_WABA_ID', '777000111');
defined('META_API_BASE')     || define('META_API_BASE', 'https://graph.invalid/v21.0');

require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/wabooking.php';
require_once INCLUDE_PATH . '/waflow.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function wbState(string $who): ?array {
    $m = new ReflectionMethod('WaBooking', 'load');
    $m->setAccessible(true);
    return $m->invoke(null, $who);
}

/* ---- settings this test touches: remembered and put back exactly ---- */
$keys = ['wa_flow_on', 'wa_flow_book_id', 'wa_flow_draft', 'wa_flow_waba', 'wa_flow_template_status', 'wa_flow_dropped',
         'wa_pay_cta_on', 'wa_booking_on', 'wa_instant_sale', 'gemini_api_key', 'anthropic_api_key', 'esewa_id'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
foreach (['gemini_api_key', 'anthropic_api_key'] as $k) {
    Settings::set($k, '', 'string', 'ai', false);
}
Settings::set('wa_booking_on', '1', 'bool', 'whatsapp');
Settings::flush();

$madePhones = [];
$cleanup = static function () use (&$madePhones, $saved): void {
    foreach ($madePhones as $p) {
        try {
            foreach (array_column(Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]), 'id') as $id) {
                foreach (['booking_passengers', 'booking_legs', 'tickets', 'message_logs', 'payments'] as $t) {
                    try { Database::run("DELETE FROM {$t} WHERE booking_id = :b", ['b' => (int) $id]); } catch (Throwable $e) {}
                }
                try { Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tkbtn_due' AND kkey LIKE :k", ['k' => (int) $id . ':%']); } catch (Throwable $e) {}
                Database::run('DELETE FROM bookings WHERE id = :b', ['b' => (int) $id]);
            }
            Database::run("DELETE FROM kv_store WHERE kkey IN (:a, :b) AND kscope IN ('wa_book','wa_sold')", ['a' => $p, 'b' => '91' . $p]);
            Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_flow_tok%' OR bucket LIKE 'wa_tkbtn%'");
        } catch (Throwable $e) {
            echo "  (cleanup note: " . $e->getMessage() . ")\n";
        }
    }
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]);
        }
    }
    Settings::flush();
};
$seq = 0;
$freshPhone = static function () use (&$seq, &$madePhones): string {
    for ($try = 0; $try < 60; $try++) {
        $seq++;
        $cand = '90000' . str_pad((string) (37000 + $seq * 7 + $try), 5, '0', STR_PAD_LEFT);
        if (!in_array($cand, $madePhones, true)
            && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p LIMIT 1', ['p' => $cand])) {
            $madePhones[] = $cand;
            Database::run("DELETE FROM kv_store WHERE kkey = :p AND kscope IN ('wa_book','wa_sold')", ['p' => '91' . $cand]);
            return $cand;
        }
    }
    throw new RuntimeException('no free test phone');
};
/** A filled form as the webhook hands it over. */
$form = static function (string $who, array $answers): array {
    return ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => json_encode(['flow_token' => WaFlow::token($who)] + $answers)]];
};

try {
    echo "\n— the Flow JSON\n";
    $fj = WaFlow::flowJson();
    $screen = $fj['screens'][0];
    $byName = [];
    foreach ($screen['layout']['children'] as $c) {
        if (isset($c['name'])) { $byName[$c['name']] = $c; }
    }
    check('version 6.0, one terminal screen', $fj['version'] === '6.0' && !empty($screen['terminal']));
    check('name and mobile are the ONLY required fields',
        !empty($byName['name']['required']) && !empty($byName['phone']['required'])
        && array_keys(array_filter($byName, static fn(array $c): bool => !empty($c['required']))) === ['name', 'phone']);
    check('mobile is prefilled from the data (the sender\'s number)', ($byName['phone']['init-value'] ?? '') === '${data.phone_init}');
    check('a country picker with its own starting value', ($byName['country']['type'] ?? '') === 'RadioButtonsGroup'
        && ($byName['country']['init-value'] ?? '') === '${data.country_init}');
    check('the day is a list and is never preselected', ($byName['date']['type'] ?? '') === 'Dropdown' && !isset($byName['date']['init-value']));
    check('pickup / seats / pay start filled in', isset($byName['pickup']['init-value'], $byName['seats']['init-value'], $byName['pay']['init-value']));
    $footer = end($screen['layout']['children']);
    check('Book sends every field, the country too', array_keys($footer['on-click-action']['payload']) === ['name', 'phone', 'country', 'date', 'pickup', 'seats', 'others', 'pay']);
    $json = json_encode($fj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    preg_match_all('/\$\{data\.([a-z_]+)\}/', $json, $m);
    $missing = array_diff(array_unique($m[1]), array_keys($screen['data']));
    check('every ${data.x} the layout reads is declared', $missing === [], implode(',', $missing));
    $noEx = array_keys(array_filter($screen['data'], static fn(array $d): bool => !array_key_exists('__example__', $d)));
    check('every data field has an example (Meta requires it)', $noEx === [], implode(',', $noEx));

    echo "\n— the screen's data\n";
    $np = WaFlow::screenData('ne', false, '9779812345678');
    check('a +977 sender: mobile prefilled without the code', $np['phone_init'] === '9812345678');
    check('… and Nepal picked', $np['country_init'] === 'NP');
    $dirOf = static fn(array $p): string => explode('|', (string) $p['id'])[0];
    check('… the way to India is listed first', $np['pickups'] !== [] && $dirOf($np['pickups'][0]) === 'toIndia', (string) ($np['pickups'][0]['id'] ?? 'none'));
    $in = WaFlow::screenData('ne', false, '919876543210');
    check('a +91 sender: India picked, the way to Nepal first', $in['country_init'] === 'IN' && $in['phone_init'] === '9876543210'
        && $in['pickups'] !== [] && $dirOf($in['pickups'][0]) === 'toNepal');
    $ids = array_map(static fn(array $o): string => (string) $o['id'], $in['pickups']);
    check('the preselected pickup is one of the list', in_array($in['pickup_init'], $ids, true));
    check('dates: at most 7, today first, all ISO days', count($in['date_opts']) >= 1 && count($in['date_opts']) <= 7
        && (string) $in['date_opts'][0]['id'] >= todayISO()
        && count(array_filter($in['date_opts'], static fn(array $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['id']) === 1)) === count($in['date_opts']));
    check('seats 1..6 at most, 1 preselected', count($in['seat_opts']) >= 1 && count($in['seat_opts']) <= 6 && $in['seats_init'] === '1');
    $long = array_filter(array_merge($in['pickups'], $in['date_opts'], $in['country_opts']), static fn(array $o): bool => mb_strlen((string) $o['title']) > 30);
    check('no option title longer than Meta\'s 30 characters', $long === []);
    $seller = WaFlow::screenData('ne', true, '919876543210');
    check('a seller\'s form never carries the seller\'s own number', $seller['phone_init'] === '');
    $again = WaFlow::screenData('ne', false, '919876543210', ['name' => 'Ram Thapa', 'phone' => '', 'country' => 'NP', 'seats' => '3'], 'Mobile not clear');
    check('a refresh: what was sent comes back, the problem on top', $again['name_init'] === 'Ram Thapa' && $again['phone_init'] === ''
        && $again['country_init'] === 'NP' && $again['seats_init'] === '3' && str_starts_with($again['intro'], '⚠️'));

    echo "\n— reading a filled form\n";
    $tomorrow = addDaysISO(todayISO(), 1);
    $pick = (string) ($in['pickups'][0]['id'] ?? 'toNepal|Surat');
    $ok = ['name' => 'Ram Thapa', 'phone' => '9812345678', 'country' => 'NP', 'date' => $tomorrow, 'pickup' => $pick, 'seats' => '2', 'pay' => 'cod'];
    $r = WaFlow::readForm($ok, '919876543210', false);
    check('10 digits + Nepal = +977', $r['bad'] === '' && $r['fields']['contact'] === '9779812345678' && $r['fields']['country'] === 'NP');
    $r = WaFlow::readForm(['phone' => '+977 98123 45678', 'country' => 'IN'] + $ok, '919876543210', false);
    check('a typed +977 wins over the picker', $r['fields']['contact'] === '9779812345678' && $r['fields']['country'] === 'NP');
    $r = WaFlow::readForm(['phone' => ''] + $ok, '919876543210', false);
    check('mobile left blank = the sender\'s own WhatsApp', $r['bad'] === '' && $r['fields']['contact'] === '919876543210');
    $r = WaFlow::readForm(['phone' => ''] + $ok, '919876543210', true);
    check('a seller must type the customer\'s mobile', $r['bad'] === 'phone');
    $r = WaFlow::readForm(['phone' => '12345'] + $ok, '919876543210', false);
    check('a short number is the bad field', $r['bad'] === 'phone' && $r['prefill']['phone'] === '' && $r['prefill']['name'] === 'Ram Thapa');
    $r = WaFlow::readForm(['name' => ''] + $ok, '919876543210', false);
    check('no name = the bad field', $r['bad'] === 'name');
    $r = WaFlow::readForm(['date' => ''] + $ok, '919876543210', false);
    check('no day = the bad field (never a guessed day)', $r['bad'] === 'date' && $r['fields']['date'] === '');
    $r = WaFlow::readForm(['date' => addDaysISO(todayISO(), -1)] + $ok, '919876543210', false);
    check('a past day = the bad field', $r['bad'] === 'date');
    $r = WaFlow::readForm(['pickup' => 'nowhere'] + $ok, '919876543210', false);
    check('an unknown pickup = the bad field', $r['bad'] === 'pickup');
    $r = WaFlow::readForm(['seats' => '9'] + $ok, '919876543210', false);
    check('seats are capped at 6', $r['fields']['seats'] === 6);
    $r = WaFlow::readForm(['phone' => 'x', 'name' => ''] + $ok, '919876543210', false);
    check('two bad fields: the mobile is asked first, only that one', $r['bad'] === 'phone');
    // review 19 Nov: the prefilled own number never becomes another country's number by the picker
    $r = WaFlow::readForm(['phone' => '9876543210', 'country' => 'NP'] + $ok, '919876543210', false);
    check('own number left as prefilled + picker flipped to Nepal = still the sender\'s +91 number',
        $r['bad'] === '' && $r['fields']['contact'] === '919876543210' && $r['fields']['country'] === 'IN', $r['fields']['contact']);
    $r = WaFlow::readForm(['phone' => '9812345678', 'country' => 'IN'] + $ok, '9779812345678', false);
    check('… and a +977 sender who picks India keeps the +977 number', $r['fields']['contact'] === '9779812345678' && $r['fields']['country'] === 'NP');
    $r = WaFlow::readForm(['phone' => '9812345678', 'country' => 'NP'] + $ok, '919876543210', true);
    check('a seller\'s typed customer number still follows the picker', $r['fields']['contact'] === '9779812345678');

    echo "\n— a bad field is asked again, nothing sold\n";
    WaFlow::$metaChannel = false;
    $p1  = $freshPhone();
    $who = '91' . $p1;
    $before = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone IN (:a, :b)', ['a' => $p1, 'b' => '9812300001'], 0);
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => '123'] + $ok));
    check('the answer names the problem', str_contains($rep['text'], 'मोबाइल नम्बर मिलेन'), mb_substr($rep['text'], 0, 60));
    check('… and asks for the mobile only', str_contains($rep['text'], '+977 98XXXXXXXX'));
    $st = wbState($who);
    check('the order waits for that one answer, the rest kept', is_array($st) && ($st['awaiting'] ?? '') === 'contact'
        && ($st['slots']['name'] ?? '') === 'Ram Thapa' && ($st['slots']['date'] ?? '') === $tomorrow && (int) ($st['slots']['seats'] ?? 0) === 2);
    check('nothing was sold', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :a', ['a' => $p1], 0) === $before);
    check('no form ui off the Meta number', $rep['ui'] === null);
    $rep2 = WaBooking::handle($who, '9779812300001', 'IN');
    $st = wbState($who);
    check('the typed mobile is taken, the order goes on', is_array($rep2) && ($st['slots']['contact'] ?? '') === '9779812300001'
        && ($st['awaiting'] ?? '') !== 'contact', (string) ($st['awaiting'] ?? 'none'));
    WaBooking::clear($who);

    // On the Meta number with a live form, the filled-in form comes back too.
    Settings::set('wa_flow_book_id', '555000555', 'string', 'whatsapp');
    Settings::set('wa_flow_on', '1', 'bool', 'whatsapp');
    Settings::set('wa_flow_draft', '0', 'bool', 'whatsapp');
    Settings::set('wa_flow_waba', META_WABA_ID, 'string', 'whatsapp');
    Settings::flush();
    WaFlow::$metaChannel = true;
    check('a form verified in this account is enabled', WaFlow::enabled());
    sleep(1);   // a form token is per number and second: this is a NEW form, not the one above sent again
    $rep = WaFlow::handleReply($who, $form($who, ['date' => ''] + $ok));
    check('no day: the day question, as a list', str_contains($rep['text'], 'यात्रा मिति छानिएन') && (wbState($who)['awaiting'] ?? '') === 'date');
    check('… and the form again, filled in, the problem on top', is_array($rep['ui']) && ($rep['ui']['kind'] ?? '') === 'flow'
        && ($rep['ui']['flow']['data']['name_init'] ?? '') === 'Ram Thapa' && str_starts_with((string) ($rep['ui']['flow']['data']['intro'] ?? ''), '⚠️'));
    WaBooking::clear($who);
    Settings::set('wa_flow_waba', '999', 'string', 'whatsapp');
    Settings::flush();
    check('a form verified under ANOTHER account is never enabled', !WaFlow::enabled());
    Settings::set('wa_flow_waba', META_WABA_ID, 'string', 'whatsapp');
    Settings::flush();

    echo "\n— a good form sells once; the unpaid answer is one Pay button\n";
    $ctaCalls = [];
    $GLOBALS['u2_cta'] = &$ctaCalls;
    function u2_fake_cta(string $phone, string $bodyText, string $buttonText, string $url, ?string $headerImageUrl = null, ?string $countryHint = null): array {
        $GLOBALS['u2_cta'][] = compact('phone', 'bodyText', 'buttonText', 'url', 'headerImageUrl', 'countryHint');
        return ['success' => true, 'message_id' => 'wamid.U2', 'error' => '', 'http' => 200, 'code' => 0];
    }
    WaBooking::$ctaFn = 'u2_fake_cta';
    $p2  = $freshPhone();
    $who = '91' . $p2;
    $good = ['phone' => $p2, 'country' => 'IN', 'seats' => '1', 'pay' => 'upi', 'name' => 'Sita Sharma'] + $ok;
    $fa = $form($who, $good);
    $rep = WaFlow::handleReply($who, $fa);
    $sold = WaBooking::$lastSale;
    $row = Database::fetch('SELECT id, pnr, status FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $p2]);
    if ($row === null) {
        check('a UPI form sale was made (skipped checks: ' . mb_substr($rep['text'], 0, 120) . ')', false);
    } else {
        check('a UPI form sale waits for the money (never confirmed here)', in_array((string) $row['status'], ['pending', 'payment_pending', 'awaiting_payment'], true) || (string) $row['status'] !== 'confirmed', (string) $row['status']);
        check('the sale is remembered for the form', is_array($sold) && $sold['pnr'] === (string) $row['pnr']);
        check('ONE call-to-action went: pay QR picture + Pay button', count($ctaCalls) === 1
            && str_contains((string) $ctaCalls[0]['url'], 'pay.php?pnr=' . urlencode((string) $row['pnr']))
            && str_contains((string) $ctaCalls[0]['headerImageUrl'], 'pay-image.php?pnr=')
            && str_starts_with((string) $ctaCalls[0]['buttonText'], 'Pay ₹'));
        check('… to this chat, with its country', ($ctaCalls[0]['phone'] ?? '') === $who && ($ctaCalls[0]['countryHint'] ?? '') === 'IN');
        check('the reply after it is one line: send the UTR / screenshot', $rep['media'] === null && str_contains($rep['text'], (string) $row['pnr']) && str_contains($rep['text'], 'UTR'));
    }
    $rep = WaFlow::handleReply($who, $fa);
    check('the same form sent twice is never sold twice', str_contains($rep['text'], 'already booked')
        && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $p2], 0) === ($row === null ? 0 : 1));

    // Without a CTA sender the old text + QR answer stands.
    WaBooking::$ctaFn = 'u2_no_such_function';
    $p3  = $freshPhone();
    $who = '91' . $p3;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => $p3, 'country' => 'IN', 'seats' => '1', 'pay' => 'upi', 'name' => 'Hari Prasad'] + $ok));
    if (WaBooking::$lastSale !== null) {
        check('no CTA sender: the pay link + QR picture, as before', str_contains((string) $rep['media'], 'pay-image.php') && str_contains($rep['text'], 'pay.php?pnr='));
    } else {
        check('no CTA sender: sale made (skipped: ' . mb_substr($rep['text'], 0, 100) . ')', false);
    }

    // A pay-on-the-bus form ticket gets the edit buttons noted for the routing cron.
    WaBooking::$ctaFn = 'u2_fake_cta';
    $p4  = $freshPhone();
    $who = '91' . $p4;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => '', 'country' => 'IN', 'seats' => '1', 'pay' => 'cod', 'name' => 'Gita Rai'] + $ok));
    $s4 = WaBooking::$lastSale;
    if (is_array($s4) && $s4['status'] === 'confirmed') {
        $due = Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope = 'wa_tkbtn_due' AND kkey = :k", ['k' => $s4['bookingId'] . ':' . $who], 0);
        check('a confirmed form ticket: ✏️ / 📅 buttons queued for this chat', (int) $due === 1 || (class_exists('WaEdit') && !WaEdit::ready()), 'rows ' . $due);
    } else {
        check('pay-on-bus form sale confirmed (skipped: ' . mb_substr((string) $rep['text'], 0, 100) . ')', is_array($s4), is_array($s4) ? $s4['status'] : 'none');
    }

    echo "\n— review 19 Nov: a chat re-ask keeps the typed mobile and the UPI choice\n";
    WaBooking::$ctaFn = 'u2_fake_cta';
    $ctaCalls = [];
    $p5  = $freshPhone();
    $p6  = $freshPhone();
    $who = '91' . $p5;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => $p6, 'country' => 'IN', 'seats' => '1', 'pay' => 'upi', 'name' => 'Sita Kumari', 'date' => ''] + $ok));
    $st  = wbState($who);
    check('bad day + another typed mobile: the day is asked, the typed mobile and UPI are kept',
        is_array($st) && ($st['awaiting'] ?? '') === 'date' && ($st['slots']['contact'] ?? '') === '91' . $p6 && ($st['slots']['_pay'] ?? '') === 'upi',
        json_encode(['aw' => $st['awaiting'] ?? null, 'c' => $st['slots']['contact'] ?? null, 'p' => $st['slots']['_pay'] ?? null]));
    WaBooking::$lastSale = null;
    $turn = WaBooking::handle($who, $tomorrow, 'IN');
    for ($i = 0; $i < 3 && WaBooking::$lastSale === null && is_array($turn) && is_array(wbState($who)); $i++) {
        $turn = WaBooking::handle($who, 'ho', 'IN');
    }
    $rowTyped  = Database::fetch('SELECT id, pnr, status FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $p6]);
    $rowSender = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $p5], 0);
    check('after the day is answered in the chat, the ticket is on the TYPED mobile, not the sender\'s',
        $rowTyped !== null && $rowSender === 0, $rowTyped === null ? mb_substr((string) ($turn['text'] ?? 'no reply'), 0, 140) : (string) $rowTyped['pnr']);
    if ($rowTyped !== null) {
        check('… and the UPI choice held: the booking waits for the money', (string) $rowTyped['status'] !== 'confirmed', (string) $rowTyped['status']);
        $lastCta = end($ctaCalls);
        check('… the Pay message names where the ticket goes', is_array($lastCta) && str_contains((string) $lastCta['bodyText'], $p6));
    }
    WaBooking::clear($who);

    echo "\n— review 19 Nov: never two Pay messages in one chat\n";
    require_once INCLUDE_PATH . '/events.php';
    require_once INCLUDE_PATH . '/notify.php';
    $u2Journal = new ReflectionProperty('Notify', 'journal');
    $u2Journal->setAccessible(true);
    $GLOBALS['u2_fake_placed'] = '';
    EventBus::on('booking.created', static function () use ($u2Journal): void {
        if ($GLOBALS['u2_fake_placed'] !== '') {     // stands in for Notify::bookingPlaced's Pay message on the Meta number
            $j   = $u2Journal->getValue();
            $j[] = ['to' => $GLOBALS['u2_fake_placed'], 'ok' => true, 'link' => null, 'driver' => 'cloud_api', 'reason' => 'meta_free', 'ref' => 'wamid.placed'];
            $u2Journal->setValue(null, $j);
        }
    });
    $ctaCalls = [];
    $p7  = $freshPhone();
    $who = '91' . $p7;
    $GLOBALS['u2_fake_placed'] = $who;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => '', 'country' => 'IN', 'seats' => '1', 'pay' => 'upi', 'name' => 'Ramesh Yadav'] + $ok));
    $GLOBALS['u2_fake_placed'] = '';
    if (WaBooking::$lastSale !== null) {
        check('the booking\'s own Pay message already came: no second Pay button', $ctaCalls === [], (string) count($ctaCalls));
        check('… the reply points up to it and asks for the UTR / screenshot, no second QR',
            $rep['media'] === null && str_contains($rep['text'], 'माथि') && str_contains($rep['text'], 'UTR'), mb_substr($rep['text'], 0, 80));
    } else {
        check('dedupe sale made (skipped: ' . mb_substr($rep['text'], 0, 100) . ')', false);
    }

    echo "\n— eSewa with the NPR amount for a Nepal sale\n";
    Settings::set('esewa_id', 'zztest-u2@esewa', 'string', 'payment');
    Settings::flush();
    $ctaCalls = [];
    $p8  = $freshPhone();
    $who = '977' . $p8;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => '', 'country' => 'NP', 'seats' => '1', 'pay' => 'upi', 'name' => 'Bimala Tharu'] + $ok), null, 'NP');
    if (WaBooking::$lastSale !== null && $ctaCalls !== []) {
        $body = (string) $ctaCalls[0]['bodyText'];
        check('a Nepal UPI sale: the Pay message carries eSewa and the रू amount', str_contains($body, 'eSewa: ') && str_contains($body, 'रू '), mb_substr($body, 0, 160));
        check('… sent with the Nepal hint to the +977 number', ($ctaCalls[0]['countryHint'] ?? '') === 'NP' && ($ctaCalls[0]['phone'] ?? '') === $who);
    } else {
        check('Nepal UPI sale made with a Pay message (skipped: ' . mb_substr($rep['text'], 0, 100) . ')', false);
    }
    WaBooking::clear($who);
    try { Database::run("DELETE FROM kv_store WHERE kkey = :k AND kscope IN ('wa_book','wa_sold')", ['k' => $who]); } catch (Throwable $e) {}
    $ctaCalls = [];
    $p9  = $freshPhone();
    $who = '91' . $p9;
    $rep = WaFlow::handleReply($who, $form($who, ['phone' => '', 'country' => 'IN', 'seats' => '1', 'pay' => 'upi', 'name' => 'Mohan Patel'] + $ok));
    if (WaBooking::$lastSale !== null && $ctaCalls !== []) {
        check('an India UPI sale: no eSewa line', !str_contains((string) $ctaCalls[0]['bodyText'], 'eSewa'));
    }

    echo "\n— which account the form lives in\n";
    $calls = [];
    $flows = [];          // what the current account lists
    $statusOf = [];       // GET /{id}
    $createReply = ['http' => 200, 'json' => ['id' => '888000888', 'success' => true]];
    WaFlow::$graphStub = static function (string $m, string $path, array $f) use (&$calls, &$flows, &$statusOf, &$createReply): array {
        $calls[] = $m . ' ' . strtok($path, '?');
        if ($m === 'GET' && str_starts_with($path, '/' . META_WABA_ID . '/flows')) {
            return $flows === null ? ['http' => 500, 'json' => ['error' => ['message' => 'down']]] : ['http' => 200, 'json' => ['data' => $flows]];
        }
        if ($m === 'POST' && $path === '/' . META_WABA_ID . '/flows') {
            return $createReply;
        }
        if ($m === 'GET' && preg_match('#^/(\d+)\?#', $path, $mm) === 1) {
            return ['http' => 200, 'json' => ['id' => $mm[1], 'status' => $statusOf[$mm[1]] ?? 'DRAFT']];
        }
        if ($m === 'POST' && str_ends_with($path, '/publish')) {
            return ['http' => 400, 'json' => ['error' => ['message' => 'Integrity requirements not met']]];
        }
        return ['http' => 404, 'json' => []];
    };
    Settings::set('wa_flow_book_id', '1440536441555084', 'string', 'whatsapp');
    Settings::set('wa_flow_draft', '1', 'bool', 'whatsapp');
    Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
    Settings::flush();

    $flows = [['id' => '123', 'status' => 'DRAFT']];
    check('a form missing from this account\'s list is foreign', WaFlow::ownership()['state'] === 'foreign');
    $flows = [['id' => '1440536441555084', 'status' => 'PUBLISHED']];
    $o = WaFlow::ownership();
    check('listed here = ours, with its status', $o['state'] === 'own' && $o['status'] === 'PUBLISHED');
    $flows = [['id' => '1440536441555084', 'status' => 'DEPRECATED']];
    check('retired by Meta = deprecated', WaFlow::ownership()['state'] === 'deprecated');

    $flows = null;   // Meta cannot be asked
    $calls = [];
    $p = WaFlow::publish();
    check('Meta unreachable: nothing changed, nothing created', !$p['ok'] && WaFlow::flowId() === '1440536441555084'
        && !in_array('POST /' . META_WABA_ID . '/flows', $calls, true));

    $flows = [['id' => '42', 'status' => 'PUBLISHED']];     // the old draft is not in this account
    $statusOf = ['888000888' => 'PUBLISHED'];
    $calls = [];
    $p = WaFlow::publish();
    check('the foreign draft is dropped, never "published" again', $p['replaced'] === '1440536441555084' && $p['why'] === 'foreign'
        && !in_array('POST /1440536441555084/publish', $calls, true));
    check('a fresh form is made in THIS account and goes ON (Meta says PUBLISHED)', $p['ok'] && WaFlow::flowId() === '888000888'
        && Settings::getBool('wa_flow_on', false) && Settings::getString('wa_flow_waba', '') === META_WABA_ID && WaFlow::enabled());
    check('the dropped id is kept for the record', str_starts_with(Settings::getString('wa_flow_dropped', ''), '1440536441555084'));

    // Meta keeps the new one as a draft: it stays OFF.
    $createReply = ['http' => 200, 'json' => ['id' => '888000999', 'success' => true]];
    $statusOf['888000999'] = 'DRAFT';
    $p = WaFlow::publish(true);   // the owner's "Re-create form"
    check('re-create: a new id, but DRAFT at Meta = stays OFF', !$p['ok'] && WaFlow::flowId() === '888000999'
        && !Settings::getBool('wa_flow_on', false) && Settings::getBool('wa_flow_draft', false) && !WaFlow::enabled());
    // Later Meta shows it PUBLISHED: the next try switches it on without making another.
    $flows = [['id' => '888000999', 'status' => 'PUBLISHED']];
    $calls = [];
    $p = WaFlow::publish();
    check('own + PUBLISHED: switched on with no new form', $p['ok'] && WaFlow::flowId() === '888000999'
        && !in_array('POST /' . META_WABA_ID . '/flows', $calls, true) && WaFlow::enabled());

    check('the form template is UTILITY unless the office says otherwise', WaFlow::templateCategory() === 'UTILITY');
    check('the form template is shg_booking_form (one name with the template registry)', WaFlow::TEMPLATE === 'shg_booking_form');
} catch (Throwable $e) {
    $FAIL++;
    echo "  UNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    WaFlow::$graphStub = null;
    WaFlow::$metaChannel = false;
    WaBooking::$ctaFn = 'sendWhatsAppCtaUrl';
    $cleanup();
}

$left = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE :p', ['p' => '90000370%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE :p', ['p' => '90000371%'], 0);
check('cleanup: no test booking left', $left === 0, (string) $left);

echo "\nu2-wa-flow: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
