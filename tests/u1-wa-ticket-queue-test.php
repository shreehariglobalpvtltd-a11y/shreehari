<?php
/**
 * =====================================================================
 *  u1-wa-ticket-queue-test.php — WA-1 (19 Nov 2026): a ticket the Meta
 *  number could not deliver waits in the "hi" queue, for India AND Nepal.
 *
 *  Live 18 Nov: the Nepal customer's ticket failed (template 132001, then
 *  the free picture outside the window), the customer wrote a minute later
 *  and still got nothing - with AiSensy off no queue row was ever made.
 *
 *    A. WaRoute::intl / waCleanPhone take a country hint (NP / 977 / IN / 91)
 *    B. Nepal booking, window closed, template refused (132001):
 *       - exactly ONE template call for the ticket (no second one from the
 *         Cloud API driver), the refusal is remembered (WaTplMeta)
 *       - WaQueue::afterNotify leaves a pending customer row on 977… and a
 *         pending row for the second number (alt) on its own country (91…)
 *       - the customer writes "hi" (window opens): onInbound(…, 'meta')
 *         delivers the ticket free to the 977 number, row 'sent'
 *    C. India booking, window open: delivered free, recorded 'sent' once,
 *       no template at all
 *    D. wa_queue_meta_on = 0: no rows
 *
 *  Settings are overridden in-process only (the shared test DB's settings
 *  table is not touched). Rows: PNR SHG-2099-901xx, phones 90000123xx / 9890000123.
 *    php -c .claude/php-dev.ini tests/u1-wa-ticket-queue-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

// Fake Meta credentials for THIS process (no network: every call is stubbed below).
define('META_ACCESS_TOKEN', 'U1_TEST_TOKEN');
define('META_PHONE_NUMBER_ID', '555000111000999');
define('META_WABA_ID', '555000111000888');
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waqueue.php';
require_once INCLUDE_PATH . '/watplmeta.php';
require_once ROOT_PATH . '/whatsapp/api.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** In-process setting override (never written to the shared settings table). */
function tset(string $k, mixed $v): void {
    Settings::get('__load__');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $r->getValue();
    $type = is_bool($v) ? 'bool' : (is_int($v) ? 'int' : 'string');
    $c[$k] = ['value' => is_bool($v) ? ($v ? '1' : '0') : (string) $v, 'type' => $type, 'public' => false];
    $r->setValue(null, $c);
}

const U1_PNR_NP  = 'SHG-2099-90101';
const U1_PNR_IN  = 'SHG-2099-90102';
const U1_NP      = '9890000123';   // stamped Nepal (977); a Nepal mobile starts 96/97/98
const U1_ALT     = '9000012302';   // the second number, India (91)
const U1_IN      = '9000012303';   // stamped India (91)
const U1_PNR_NP2 = 'SHG-2099-90103';
const U1_NP2     = '9890000125';   // E: Nepal, Meta accepts then fails later

$cleanup = static function (): void {
    $ids = array_map(static fn($r) => (int) $r['id'], Database::fetchAll('SELECT id FROM bookings WHERE pnr IN (:a, :b, :c)', ['a' => U1_PNR_NP, 'b' => U1_PNR_IN, 'c' => U1_PNR_NP2]));
    foreach ($ids as $id) {
        Database::run('DELETE FROM wa_ticket_queue WHERE booking_id = :b', ['b' => $id]);
        Database::run('DELETE FROM message_logs WHERE booking_id = :b', ['b' => $id]);
        Database::run('DELETE FROM bookings WHERE id = :b', ['b' => $id]);
    }
    foreach (['%90000123%', '%9890000123%', '%9890000125%'] as $like) {
        Database::run('DELETE FROM message_logs WHERE to_number LIKE :l', ['l' => $like]);
        Database::run('DELETE FROM wa_ticket_queue WHERE phone LIKE :l', ['l' => $like]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE :l", ['l' => $like]);
    }
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta'");
};
$cleanup();

/* ---- the switches of the live set-up (18 Nov), in this process only ---- */
tset('whatsapp_driver', 'cloud_api');
tset('wa_policy', 'meta_only');
tset('wa_meta_templates_on', true);
tset('aisensy_mode', 'off');
tset('whatsapp_template_name', 'shg_ticket_image');
tset('whatsapp_template_lang', 'en');
tset('whatsapp_default_country', '91');
tset('wa_hi_queue_on', true);
tset('wa_queue_meta_on', true);
tset('wa_ticket_alt_on', true);
tset('wa_seller_copy_on', false);
tset('wa_cloud_fallback_on', false);
tset('whatsapp_notify_customer', true);
tset('whatsapp_notify_admin', false);
tset('sms_notify_customer', false);
tset('email_attach_pdf', false);
tset('wa_global_daily_cap', 0);
tset('wa_paid_daily_limit', '');
tset('wa_tpl_meta_on', true);

/* ---- stubs: the WaRoute transport and the Graph call ---- */
$calls = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$calls): array {
    $calls[] = ['via' => 'route', 'to' => $to, 'what' => $text, 'image' => $image];
    if (str_starts_with($text, 'template:')) {
        return ['success' => false, 'message_id' => '', 'error' => '(#132001) Template name does not exist in the translation', 'code' => 132001];
    }
    return ['success' => true, 'message_id' => 'wamid.U1FREE' . count($calls), 'error' => ''];
};
$GLOBALS['WA_GRAPH_HOOK'] = static function (string $to, array $body, string $type) use (&$calls): array {
    $calls[] = ['via' => 'graph', 'to' => $to, 'what' => $type, 'body' => $body];
    // Outside the window Meta refuses free messages; a template would be 132001.
    return ['success' => false, 'message_id' => '', 'error' => $type === 'template' ? '(#132001) does not exist' : '(#131047) Re-engagement message', 'http' => 400, 'code' => $type === 'template' ? 132001 : 131047];
};

$mkBooking = static function (string $pnr, string $phone, string $cc, ?string $alt, ?string $altCc): array {
    $id = Database::insert('bookings', [
        'pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => $cc,
        'alt_phone' => $alt, 'alt_country_code' => $altCc,
        'status' => 'confirmed', 'total_amount' => 2800, 'source' => 'web',
    ]);
    return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $id]) ?? [];
};

echo "\n=== WA-1: tickets the Meta number missed wait for the customer's next message ===\n\n";

/* ---------------- A. country hints ---------------- */
echo "-- A. country hint on a bare 10-digit number --\n";
check('WaRoute::intl NP hint → 977',   WaRoute::intl('9812345678', 'NP') === '9779812345678');
check('WaRoute::intl 977 hint → 977',  WaRoute::intl('9812345678', '977') === '9779812345678');
check('WaRoute::intl IN hint → 91',    WaRoute::intl('9812345678', 'IN') === '919812345678');
check('WaRoute::intl no hint → default 91', WaRoute::intl('9812345678') === '919812345678');
check('WaRoute::intl keeps a full 977 number', WaRoute::intl('+977 98123 45678', 'IN') === '9779812345678');
check("waCleanPhone '977' hint → 977", waCleanPhone('9812345678', '977') === '9779812345678');
check("waCleanPhone 'NP' hint → 977",  waCleanPhone('9812345678', 'NP') === '9779812345678');

/* ---------------- B. Nepal booking, window closed ---------------- */
echo "\n-- B. Nepal booking, no open window, template refused --\n";
$b = $mkBooking(U1_PNR_NP, U1_NP, '977', U1_ALT, '91');
check('test booking created', ($b['id'] ?? 0) > 0);
$from = count(Notify::whatsappJournal());
Notify::bookingConfirmed($b);
$res = WaQueue::afterNotify($b, $from);

$np = '977' . U1_NP;
$alt = '91' . U1_ALT;
/* 20 Nov 2026 (px3): a refused ticket template is carried at once by an older working template
   (WaRoute::ticketRescueSpec) - that rescue call is counted on its own below. */
$isRescue = static fn(array $c): bool => in_array(substr((string) $c['what'], 9), ['shg_booking_received', 'shg_office_alert'], true)
    || in_array((string) ($c['body']['template']['name'] ?? ''), ['shg_booking_received', 'shg_office_alert'], true);
$tplToNp = array_values(array_filter($calls, static fn($c) => $c['to'] === $np && !$isRescue($c) && (str_starts_with((string) $c['what'], 'template:') || $c['what'] === 'template')));
check('exactly ONE template call for the Nepal ticket', count($tplToNp) === 1, count($tplToNp) . ' call(s)');
$rescueNp = array_values(array_filter($calls, static fn($c) => $c['to'] === $np && $isRescue($c)));
check('px3: the refused ticket was tried ONCE through the older working template (shg_booking_received)', count($rescueNp) === 1 && ($rescueNp[0]['what'] ?? '') === 'template:shg_booking_received', count($rescueNp) . ' call(s)');
check('that call was shg_ticket_image on the WaRoute path', ($tplToNp[0]['what'] ?? '') === 'template:shg_ticket_image');
check('the Cloud API driver did not build the template again', array_filter($calls, static fn($c) => $c['via'] === 'graph' && $c['to'] === $np && $c['what'] === 'template') === []);
check('the refusal is remembered for a few hours', WaTplMeta::refusedRecently('shg_ticket_image'));
check('every send to the customer used 977 (never 91…' . U1_NP . ')', array_filter($calls, static fn($c) => $c['to'] === '91' . U1_NP) === []);

$row = Database::fetch('SELECT * FROM wa_ticket_queue WHERE booking_id = :b AND role = :r', ['b' => (int) $b['id'], 'r' => 'customer']);
check('afterNotify says queued for the customer', $res['customer'] === 'queued', $res['customer']);
check('customer row is pending on the 977 number', $row !== null && $row['status'] === 'pending' && $row['phone'] === $np, $row !== null ? $row['phone'] . ' ' . $row['status'] : 'no row');
$altRow = Database::fetch('SELECT * FROM wa_ticket_queue WHERE booking_id = :b AND role = :r', ['b' => (int) $b['id'], 'r' => 'alt']);
check('the second number was tried on its own country (91)', array_filter($calls, static fn($c) => $c['to'] === $alt) !== []);
check('second-number row is pending on 91…', $altRow !== null && $altRow['status'] === 'pending' && $altRow['phone'] === $alt, $altRow !== null ? $altRow['phone'] . ' ' . $altRow['status'] : 'no row');

// A re-fired approval does not make a second row or a second alt send.
$before = count($calls);
WaQueue::afterNotify($b, count(Notify::whatsappJournal()));
check('afterNotify again keeps one row per number', (int) Database::scalar('SELECT COUNT(*) FROM wa_ticket_queue WHERE booking_id = :b', ['b' => (int) $b['id']], 0) === 2);
check('afterNotify itself sends nothing', count($calls) === $before);

// The customer writes "hi": the Meta number's window opens (webhook kv) and the queue flushes.
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u1test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
    ['k' => $np, 'v' => (string) time()]);
$calls = [];
$sent = WaQueue::onInbound($np, 'hi', 'meta');
$row = Database::fetch('SELECT * FROM wa_ticket_queue WHERE booking_id = :b AND role = :r', ['b' => (int) $b['id'], 'r' => 'customer']);
check('"hi" from the Nepal number delivers the waiting ticket', $sent === 1, 'sent ' . $sent);
check('row is now sent, via the free reply', $row !== null && $row['status'] === 'sent' && $row['via'] === 'meta free', $row !== null ? $row['status'] . ' / ' . $row['via'] : 'no row');
check('the free ticket went to 977… with the ticket picture', array_filter($calls, static fn($c) => $c['to'] === $np && $c['image'] !== null && str_contains((string) $c['image'], 'ticket')) !== []);
check('nothing went to the second number on the customer\'s "hi"', array_filter($calls, static fn($c) => $c['to'] === $alt) === []);

/* ---------------- C. India booking, window open ---------------- */
echo "\n-- C. India booking, open window --\n";
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta'");
$in = '91' . U1_IN;
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u1test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
    ['k' => $in, 'v' => (string) time()]);
$b2 = $mkBooking(U1_PNR_IN, U1_IN, '91', null, null);
$calls = [];
$from = count(Notify::whatsappJournal());
Notify::bookingConfirmed($b2);
$res2 = WaQueue::afterNotify($b2, $from);
$r2 = Database::fetchAll('SELECT * FROM wa_ticket_queue WHERE booking_id = :b', ['b' => (int) $b2['id']]);
check('India ticket went free inside the window', array_filter($calls, static fn($c) => $c['to'] === $in && !str_starts_with((string) $c['what'], 'template:')) !== []);
check('no template for an open window', array_filter($calls, static fn($c) => str_starts_with((string) $c['what'], 'template:') || $c['what'] === 'template') === []);
check('afterNotify: sent', $res2['customer'] === 'sent', $res2['customer']);
check('one queue row, status sent (a later "hi" does not resend it)', count($r2) === 1 && $r2[0]['status'] === 'sent' && $r2[0]['phone'] === $in);

/* ---------------- E. the real Meta behaviour: accepted now, failed later ----------------
   Meta ACCEPTS a free picture to a closed window (HTTP 200 + wamid) and fails
   it a minute later through the status webhook with 131047 (live: 285 such
   message_logs rows). The row must go back to 'pending' so the next "hi"
   delivers it - the 18 Nov incident. */
echo "\n-- E. Nepal booking: free picture accepted, failed later by the webhook (131047) --\n";
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta'");
$GLOBALS['WA_GRAPH_HOOK'] = static function (string $to, array $body, string $type) use (&$calls): array {
    $calls[] = ['via' => 'graph', 'to' => $to, 'what' => $type, 'body' => $body];
    if ($type === 'template') {
        return ['success' => false, 'message_id' => '', 'error' => '(#132001) does not exist', 'http' => 404, 'code' => 132001];
    }
    return ['success' => true, 'message_id' => 'wamid.U1LATE' . count($calls), 'error' => '', 'http' => 200];
};
$b3 = $mkBooking(U1_PNR_NP2, U1_NP2, '977', null, null);
$np2 = '977' . U1_NP2;
$calls = [];
$from = count(Notify::whatsappJournal());
Notify::bookingConfirmed($b3);
$res3 = WaQueue::afterNotify($b3, $from);
$r3 = Database::fetch('SELECT * FROM wa_ticket_queue WHERE booking_id = :b AND role = :r', ['b' => (int) $b3['id'], 'r' => 'customer']);
check('Meta accepted the free picture: afterNotify says sent', $res3['customer'] === 'sent', $res3['customer']);
check("row recorded as a Meta send ('meta notify') with Meta's wamid", $r3 !== null && $r3['status'] === 'sent' && $r3['via'] === WaQueue::VIA_NOTIFY && str_starts_with((string) $r3['wamid'], 'wamid.U1LATE'),
    $r3 !== null ? $r3['status'] . ' / ' . $r3['via'] . ' / ' . (string) $r3['wamid'] : 'no row');
$tpl3 = array_filter($calls, static fn($c) => $c['to'] === $np2 && !$isRescue($c) && (str_starts_with((string) $c['what'], 'template:') || $c['what'] === 'template'));
check('still only one template call for this ticket', count($tpl3) <= 1, count($tpl3) . ' call(s)');

// (checkStatuses() skips any via starting 'meta', so this row is never sent to AiSensy's status API;
//  not called here because it also expires / drains rows of the shared test DB.)

// The status webhook: failed, 131047.
$calls = [];
$back = WaQueue::onMetaFailed((string) ($r3['wamid'] ?? ''), 131047);
$r3c = Database::fetch('SELECT * FROM wa_ticket_queue WHERE id = :i', ['i' => (int) ($r3['id'] ?? 0)]);
check('webhook 131047 puts the ticket back to pending', $back === false && $r3c !== null && $r3c['status'] === 'pending', $r3c !== null ? $r3c['status'] . ' · ' . (string) $r3c['last_error'] : 'no row');
check('no second template call on the webhook failure', array_filter($calls, static fn($c) => str_starts_with((string) $c['what'], 'template:') || $c['what'] === 'template') === []);
check('alreadyHas() is false again for that number', !WaQueue::alreadyHas((int) $b3['id'], $np2));

// The customer writes "hi" -> delivered free.
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u1test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
    ['k' => $np2, 'v' => (string) time()]);
$calls = [];
$sent3 = WaQueue::onInbound($np2, 'hi', 'meta');
$r3d = Database::fetch('SELECT * FROM wa_ticket_queue WHERE id = :i', ['i' => (int) ($r3['id'] ?? 0)]);
check('"hi" after the late failure delivers the ticket', $sent3 === 1 && $r3d !== null && $r3d['status'] === 'sent', 'sent ' . $sent3 . ' / ' . ($r3d['status'] ?? '-'));

// Another code (not a window one) on a non-ticket wamid changes nothing.
check('an unknown wamid is ignored', WaQueue::onMetaFailed('wamid.U1NOPE', 131047) === false);

/* ---------------- D. switch off ---------------- */
echo "\n-- D. wa_queue_meta_on = 0 --\n";
Database::run('DELETE FROM wa_ticket_queue WHERE booking_id = :b', ['b' => (int) $b['id']]);
tset('wa_queue_meta_on', false);
$off = WaQueue::afterNotify($b, 0);
check('switched off: nothing queued', $off === ['customer' => '', 'alt' => ''] && (int) Database::scalar('SELECT COUNT(*) FROM wa_ticket_queue WHERE booking_id = :b', ['b' => (int) $b['id']], 0) === 0);
tset('wa_queue_meta_on', true);
tset('aisensy_mode', 'always');
tset('aisensy_api_key', 'u1-fake-key');
check('AiSensy active: afterNotify leaves it to the AiSensy hook', !WaQueue::metaOnly() && WaQueue::afterNotify($b, 0) === ['customer' => '', 'alt' => '']);

$cleanup();
unset($GLOBALS['WA_GRAPH_HOOK']);
WaRoute::$transport = null;

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
