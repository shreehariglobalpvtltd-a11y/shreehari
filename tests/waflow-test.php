<?php
/**
 * tests/waflow-test.php — the WhatsApp booking FORM (15 Oct 2026).
 *
 *   php tests/waflow-test.php      (a *test* database only)
 *
 * The form JSON Meta stores, the screen data each send carries, the token
 * that ties a filled form to its number, the sale a filled form makes (and
 * never twice), the seller path, and the office's agent / counter board.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/wabooking.php';
require_once INCLUDE_PATH . '/waflow.php';
require_once INCLUDE_PATH . '/walocal.php';

if (!defined('DB_NAME') || stripos((string) DB_NAME, 'test') === false) { fwrite(STDERR, "refusing: not a test database\n"); exit(1); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "== form JSON ==\n";
$j = WaFlow::flowJson();
$sc = $j['screens'][0] ?? [];
check('one terminal BOOK screen', ($sc['id'] ?? '') === 'BOOK' && !empty($sc['terminal']));
$kids = $sc['layout']['children'] ?? [];
$limits = ['TextInput' => 20, 'TextArea' => 20, 'Dropdown' => 20, 'DatePicker' => 40, 'RadioButtonsGroup' => 30, 'Footer' => 35];
$names = [];
foreach ($kids as $k) {
    $t = (string) ($k['type'] ?? '');
    if (isset($limits[$t])) {
        check("$t label ≤ {$limits[$t]} chars", mb_strlen((string) $k['label']) <= $limits[$t], (string) $k['label']);
    }
    if (isset($k['name'])) { $names[] = $k['name']; }
    if ($t === 'Footer') { $payload = $k['on-click-action']['payload'] ?? []; }
}
check('every field reaches the payload', array_diff($names, array_keys($payload ?? [])) === [], implode(',', $names));
check('data declares an example for each key', count(array_filter($sc['data'], static fn($d) => !array_key_exists('__example__', $d))) === 0);

echo "== screen data ==\n";
$d = WaFlow::screenData('ne', false);
$dirs = array_unique(array_map(static fn($p) => explode('|', $p['id'])[0], $d['pickups']));
check('pickups both ways', in_array('toNepal', $dirs, true) && in_array('toIndia', $dirs, true), count($d['pickups']) . ' pickups');
check('pickup titles ≤ 30', max(array_map(static fn($p) => mb_strlen($p['title']), $d['pickups'])) <= 30);
check('date window from today', $d['min_date'] === todayISO() && $d['max_date'] > $d['min_date']);
check('seat options', count($d['seat_opts']) >= 1);

echo "== token ==\n";
$run = (string) random_int(100000, 999999);
$who = '977981' . $run . '1';
$tok = WaFlow::token($who);
check('own token ok', WaFlow::tokenOk($tok, $who));
check('another number refused', !WaFlow::tokenOk($tok, '919800000000'));
check('forged refused', !WaFlow::tokenOk(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a'), $who));

echo "== a filled form books ==\n";
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa_flow%'");
Settings::set('allow_cod', '1', 'bool', 'payments');
$plan = QuickTicket::plan(['direction' => 'toNepal', 'seats' => 2, 'customer' => true]);
$date = (string) $plan['date'];
$stop = (string) ($plan['stops'][0]['name'] ?? 'Surat');
foreach ($plan['stops'] as $s) { if (!empty($s['ahead'])) { $stop = (string) $s['name']; break; } }
$contact = '981' . $run . '2';
$before = (int) Database::scalar('SELECT COUNT(*) FROM bookings');
$resp = ['flow_token' => $tok, 'pickup' => 'toNepal|' . $stop, 'date' => $date, 'seats' => '2',
         'name' => 'Ram Bahadur Thapa', 'phone' => $contact, 'others' => 'Sita Thapa', 'pay' => 'cod'];
$r = WaFlow::handleReply($who, ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => json_encode($resp)]], null, 'NP');
preg_match('/SHG-\d{4}-\d+/', $r['text'], $m);
$pnr = $m[0] ?? '';
check('reply carries a PNR', $pnr !== '', mb_substr(str_replace("\n", ' | ', $r['text']), 0, 160));
$b = $pnr !== '' ? Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $pnr]) : null;
check('booking confirmed (cash on bus)', ($b['status'] ?? '') === 'confirmed', (string) ($b['status'] ?? '-'));
check('booked for the form mobile', str_ends_with((string) ($b['contact_phone'] ?? ''), $contact), (string) ($b['contact_phone'] ?? ''));
$pax = $b ? Database::fetchAll('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => (int) $b['id']]) : [];
check('two travellers, own names', array_column($pax, 'full_name') === ['Ram Bahadur Thapa', 'Sita Thapa'], implode(', ', array_column($pax, 'full_name')));
check('tagged WHATSAPP', $b && (string) Database::scalar("SELECT COALESCE(channel_tag,'') FROM bookings WHERE id = :b", ['b' => (int) $b['id']], '') === 'WHATSAPP');

$r2 = WaFlow::handleReply($who, ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => json_encode($resp)]], null, 'NP');
check('same form twice = no second sale', (int) Database::scalar('SELECT COUNT(*) FROM bookings') === $before + 1, mb_substr($r2['text'], 0, 60));

$bad = WaFlow::handleReply('919811111111', ['type' => 'nfm_reply', 'nfm_reply' => ['response_json' => json_encode(['flow_token' => $tok] + $resp)]], null, 'IN');
check('a form sent to another number never sells', (int) Database::scalar('SELECT COUNT(*) FROM bookings') === $before + 1, mb_substr($bad['text'], 0, 50));

echo "== seller form ==\n";
$seller = Database::fetch("SELECT * FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY id LIMIT 1");
if ($seller) {
    $sw  = '91990' . $run . '7';
    $st  = WaFlow::token($sw);
    $res = ['flow_token' => $st, 'pickup' => 'toNepal|' . $stop, 'date' => $date, 'seats' => '1', 'name' => 'Hari Prasad', 'phone' => '981' . $run . '3', 'others' => '', 'pay' => 'cod'];
    $r3  = WaFlow::handleReply($sw, ['nfm_reply' => ['response_json' => json_encode($res)]], ['adminId' => (int) $seller['id'], 'admin' => $seller], 'IN');
    preg_match('/SHG-\d{4}-\d+/', $r3['text'], $m3);
    $sb = isset($m3[0]) ? Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $m3[0]]) : null;
    check('seller sale under the seller', (int) ($sb['sold_by_admin_id'] ?? 0) === (int) $seller['id'], mb_substr(str_replace("\n", ' | ', $r3['text']), 0, 120));
    check('ticket to the customer mobile', str_ends_with((string) ($sb['contact_phone'] ?? ''), '981' . $run . '3'));
} else {
    check('an agent exists for the seller test', false);
}

echo "== office: agent / counter board ==\n";
foreach (['agent report', 'aaja agent ko hisab', 'counter report mahina', 'एजेन्ट हिसाब'] as $q) {
    $a = WaLocal::answer('+919999999999', $q, ['role' => 'admin', 'adminId' => 1]);
    check('"' . $q . '" → board', ($a['intent'] ?? '') === 'staff_board', mb_substr(str_replace("\n", ' | ', (string) ($a['text'] ?? '-')), 0, 140));
}
$c = WaLocal::answer('+919999999998', 'agent report', ['role' => 'customer']);
check('a customer never gets the board', ($c['intent'] ?? '') !== 'staff_board');
$one = WaLocal::answer('+919999999999', 'SHG-0007 ko hisab', ['role' => 'admin', 'adminId' => 1]);
check('one agent code stays one agent', ($one['intent'] ?? '') !== 'staff_board', (string) ($one['intent'] ?? 'none'));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
