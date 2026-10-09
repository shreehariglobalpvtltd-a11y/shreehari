<?php
/**
 * =====================================================================
 *  wa-route-test.php — Meta-first WhatsApp routing (includes/waroute.php,
 *  9 Oct 2026).
 *
 *  Pins, against the TEST database and a stubbed Meta transport (no
 *  network to graph.facebook.com):
 *    - the policy and the Meta number's own 24 h window (kv wa_inbound);
 *    - freeFirst(): free on Meta only inside the window and only under
 *      meta_first / meta_only; it logs its own message_logs row;
 *    - the AiSensy marketing guard (aisensy_utility_only) and meta_only;
 *    - AiSensyAPI::sendAny / sendLoginOtp / ticketTemplate take the Meta
 *      route first (the OTP code is masked in the log);
 *    - Notify::whatsapp() goes Meta-free first and stays idempotent;
 *    - the "hi" queue: an inbound on the Meta number flushes a waiting
 *      ticket free, never writes the AiSensy window, and a Meta async
 *      failure hands the ticket to the AiSensy template once.
 *
 *      php tests/wa-route-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waqueue.php';
require_once INCLUDE_PATH . '/msgledger.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 300) : '') . "\n"; }
}

const RT_LIKE = '96000';
const RT_A    = '9600011101';   // window open on Meta
const RT_B    = '9600011102';   // never wrote to us
const RT_C    = '9600011103';   // the booking's customer

$keep = [];
foreach (['wa_policy' => 'meta_first', 'wa_meta_templates_on' => '0', 'aisensy_utility_only' => '1', 'wa_hi_queue_on' => '1',
          'whatsapp_notify_customer' => '1', 'aisensy_mode' => 'always', 'aisensy_api_key' => 'route-test-key', 'whatsapp_driver' => 'aisensy',
          'wa_seller_copy_on' => '0', 'wa_seller_template_on' => '0', 'whatsapp_default_country' => '91'] as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();
$priorCats = (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wam' AND kkey = 'tpl_cats'", [], '');

$booking = Database::fetch("SELECT * FROM bookings WHERE status = 'confirmed' ORDER BY id DESC LIMIT 1");
$bid  = (int) ($booking['id'] ?? 0);
$pnr  = (string) ($booking['pnr'] ?? '');
$origPhone = (string) ($booking['contact_phone'] ?? '');
$origAlt   = $booking['alt_phone'] ?? null;

$cleanup = static function () use ($keep, $priorCats, $bid, $origPhone, $origAlt, $pnr): void {
    foreach ($keep as $k => $v) {
        try {
            if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
    Settings::flush();
    foreach (["DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE '91" . RT_LIKE . "%'",
              "DELETE FROM kv_store WHERE kscope = 'wa_route'",
              "DELETE FROM message_logs WHERE to_number LIKE '%" . RT_LIKE . "%'",
              "DELETE FROM wa_ticket_queue WHERE phone LIKE '91" . RT_LIKE . "%'",
              "DELETE FROM wa_windows WHERE phone LIKE '91" . RT_LIKE . "%'",
              "DELETE FROM rate_limits WHERE bucket = 'ais_ticket' AND identifier = " . Database::pdo()->quote($pnr)] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    try {
        if ($priorCats === '') { Database::run("DELETE FROM kv_store WHERE kscope = 'wam' AND kkey = 'tpl_cats'"); }
        else { Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'wam' AND kkey = 'tpl_cats'", ['v' => $priorCats]); }
    } catch (Throwable $e) {}
    if ($bid > 0) {
        try { Database::update('bookings', ['contact_phone' => $origPhone, 'alt_phone' => $origAlt], 'id = :i', ['i' => $bid]); } catch (Throwable $e) {}
    }
    WaRoute::$transport = null;
};
register_shutdown_function($cleanup);
$cleanup();
// re-pin after the cleanup restored the originals
foreach (['wa_policy' => 'meta_first', 'wa_meta_templates_on' => '0', 'aisensy_utility_only' => '1', 'wa_hi_queue_on' => '1',
          'whatsapp_notify_customer' => '1', 'aisensy_mode' => 'always', 'aisensy_api_key' => 'route-test-key', 'whatsapp_driver' => 'aisensy',
          'wa_seller_copy_on' => '0', 'wa_seller_template_on' => '0', 'whatsapp_default_country' => '91'] as $k => $v) {
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();

/* ---- the stub transport: records every Meta send, answers success ---- */
$calls = [];
$seq   = 0;
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$calls, &$seq): array {
    $calls[] = ['to' => $to, 'text' => $text, 'image' => $image];
    return ['success' => true, 'message_id' => 'wamid.ROUTETEST' . (++$seq), 'error' => ''];
};
$openWindow = static function (string $intl, int $ago = 0): void {
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'test')
                   ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => $intl, 'v' => (string) (time() - $ago), 'v2' => (string) (time() - $ago)]);
};

echo "\n=== Meta-first WhatsApp routing ===\n\n";

try {
    echo "— policy and numbers\n";
    check('default policy is meta_first', WaRoute::policy() === WaRoute::META_FIRST, WaRoute::policy());
    check('Meta is on (stub transport counts as ready)', WaRoute::metaOn());
    check('AiSensy is on under meta_first', WaRoute::aisensyOn());
    check('intl(): a 10-digit Indian number gets 91', WaRoute::intl(RT_A) === '91' . RT_A, WaRoute::intl(RT_A));
    check('intl(): +977 is kept', WaRoute::intl('+977 9812345678') === '9779812345678', WaRoute::intl('+977 9812345678'));
    check('intl(): 00 prefix is dropped', WaRoute::intl('00919812345678') === '919812345678');
    check('intl(): a 5-digit string is not a number', WaRoute::intl('12345') === '');

    echo "\n— the Meta number's own window (kv wa_inbound)\n";
    check('unknown number: window closed', !WaRoute::metaWindowOpen(RT_B));
    $openWindow('91' . RT_A);
    check('a number that wrote just now: window open', WaRoute::metaWindowOpen(RT_A));
    check('… with most of 23.5 h left', WaRoute::metaWindowLeft(RT_A) > 23 * 3600);
    $openWindow('91' . RT_B, 25 * 3600);
    check('a number that wrote 25 h ago: window closed', !WaRoute::metaWindowOpen(RT_B));
    $openWindow('91' . RT_B, 23 * 3600 + 40 * 60);
    check('23 h 40 min ago: closed (30 min safety margin)', !WaRoute::metaWindowOpen(RT_B));
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => '91' . RT_B]);
    check('openWindows() counts the open one', WaRoute::openWindows() >= 1);

    echo "\n— freeFirst()\n";
    $calls = [];
    $r = WaRoute::freeFirst(RT_B, 'hello', null, 'message');
    check('closed window: not attempted (null), transport untouched', $r === null && $calls === []);
    $r = WaRoute::freeFirst(RT_A, 'Namaste, your ticket', 'https://www.shreehariglobal.in/tickets/ticket_X.png', 'ticket', ['bookingId' => 0]);
    check('open window: sent on Meta', is_array($r) && $r['success'] && str_contains($r['how'], 'meta free'), json_encode($r));
    check('… the picture went as the picture', count($calls) === 1 && $calls[0]['image'] !== null && $calls[0]['to'] === '91' . RT_A, json_encode($calls));
    $row = Database::fetch("SELECT * FROM message_logs WHERE to_number = :t ORDER BY id DESC LIMIT 1", ['t' => '91' . RT_A]);
    check('… logged as cloud_api / sent / purpose ticket with the wamid', $row !== null && $row['provider'] === 'cloud_api' && $row['status'] === 'sent'
        && ($row['purpose'] ?? '') === 'ticket' && str_starts_with((string) $row['provider_ref'], 'wamid.ROUTETEST'), json_encode($row));
    $calls = [];
    $r = WaRoute::freeFirst(RT_A, 'a long caption ' . str_repeat('x', 1100), 'https://www.shreehariglobal.in/tickets/ticket_X.png', 'ticket');
    check('caption over 1024: still sent (text path)', is_array($r) && $r['success'] && count($calls) === 1);
    $r = WaRoute::freeFirst(RT_A, 'statement', 'https://www.shreehariglobal.in/download-statement.php?f=a.pdf', 'agent_statement');
    check('a PDF link is not sent as a picture', is_array($r) && $r['success'] && end($calls)['image'] === null && str_contains(end($calls)['text'], '.pdf'));

    Settings::set('wa_policy', 'aisensy_only', 'string', 'whatsapp', false); Settings::flush();
    $calls = [];
    check('policy aisensy_only: Meta never tried', WaRoute::freeFirst(RT_A, 'hello', null, 'message') === null && $calls === [] && !WaRoute::metaOn());
    Settings::set('wa_policy', 'meta_only', 'string', 'whatsapp', false); Settings::flush();
    check('policy meta_only: AiSensy off, Meta on', !WaRoute::aisensyOn() && WaRoute::metaOn());
    check('… and every AiSensy template is refused', WaRoute::aisensyRefusal('shg_ticket_confirmed') !== '');
    Settings::set('wa_policy', 'meta_first', 'string', 'whatsapp', false); Settings::flush();

    echo "\n— the AiSensy marketing guard\n";
    MsgLedger::kvSet('tpl_cats', json_encode(['shg_offer' => 'marketing', 'shg_ticket_confirmed' => 'utility', 'shg_login' => 'authentication']));
    check('a UTILITY template passes', WaRoute::aisensyRefusal('shg_ticket_confirmed') === '');
    check('an AUTHENTICATION template passes', WaRoute::aisensyRefusal('shg_login') === '');
    check('a MARKETING template is refused by itself', str_contains(WaRoute::aisensyRefusal('shg_offer'), 'MARKETING'));
    check('… unless the caller (Campaigns) says so', WaRoute::aisensyRefusal('shg_offer', true) === '');
    check('an unknown name with "offer" in it counts as marketing', WaRoute::aisensyRefusal('shg_dashain_offer_2') !== '');
    $x = AiSensyAPI::sendMessage(RT_B, 'shg_offer', ['Ram', '10%']);
    check('AiSensyAPI::sendMessage refuses it before any network call', !$x['success'] && str_contains($x['error'], 'MARKETING'), json_encode($x));
    Settings::set('aisensy_utility_only', '0', 'string', 'whatsapp', false); Settings::flush();
    check('guard off: allowed', WaRoute::aisensyRefusal('shg_offer') === '');
    Settings::set('aisensy_utility_only', '1', 'string', 'whatsapp', false); Settings::flush();

    echo "\n— AiSensyAPI takes the Meta route first\n";
    $calls = [];
    $x = AiSensyAPI::sendAny(RT_A, "Namaste\nyour bus leaves at 1 PM", null, 'Ram', 'Update');
    check('sendAny: open window -> Meta free, no AiSensy call', $x['success'] && str_contains($x['how'], 'meta free') && count($calls) === 1, json_encode($x));
    $calls = [];
    $x = AiSensyAPI::sendLoginOtp(RT_A, '482913', 'Ram');
    check('sendLoginOtp: open window -> Meta free', $x['success'] && str_contains($x['how'], 'meta free') && count($calls) === 1 && str_contains($calls[0]['text'], '482913'), json_encode($x));
    $row = Database::fetch("SELECT body FROM message_logs WHERE to_number = :t AND purpose = 'otp' ORDER BY id DESC LIMIT 1", ['t' => '91' . RT_A]);
    check('… the code is masked in message_logs', $row !== null && !str_contains((string) $row['body'], '482913'), (string) ($row['body'] ?? 'no row'));
    $calls = [];
    $x = AiSensyAPI::sendAny(RT_B, 'hello', null, 'Shyam', 'Update');
    check('sendAny: closed window -> AiSensy (refused here: bogus key), Meta untouched', !$x['success'] && $calls === [], json_encode($x));

    echo "\n— aisensy_scope = ticket_only (owner: AiSensy pays for the ticket to a new customer, nothing else)\n";
    check('default scope is ticket-only', AiSensyAPI::ticketOnly());
    $calls = [];
    $x = AiSensyAPI::sendAny(RT_B, 'hello', null, 'Shyam', 'Update');
    check('a compose message to a cold number: no template spent, no network', !$x['success'] && str_contains($x['error'], 'ticket-only') && $calls === [], json_encode($x));
    $x = AiSensyAPI::sendLoginOtp(RT_B, '111222', '');
    check('an OTP to a cold number: no template spent', !$x['success'] && str_contains($x['error'], 'ticket-only'), json_encode($x));
    $x = AiSensyAPI::sendMessage(RT_B, 'shg_trip_update', ['Ram', 'SHG-2026-00001', 'Delay', '-']);
    check('a bus update template: refused', !$x['success'] && str_contains($x['error'], 'ticket-only'), json_encode($x));
    $x = AiSensyAPI::asTicket(static fn(): array => AiSensyAPI::sendMessage(RT_B, 'shg_ticket_image', ['Ram', 'Surat', 'Rupaidiha', '2026-10-10', 'L1', 'SHG-2026-00001'], 'https://www.shreehariglobal.in/x.png'));
    check('the ticket template inside the ticket context goes out to AiSensy (refused here: bogus key / no network)', !str_contains((string) $x['error'], 'ticket-only'), json_encode($x));
    $x = AiSensyAPI::ticketTemplate(RT_B, 1, 'SHG-2026-00001', 'Ram (agent copy)', false);
    check('a seller copy never spends a template', !$x['success'] && str_contains($x['error'], 'ticket-only'), json_encode($x));
    Settings::set('aisensy_scope', 'all', 'string', 'whatsapp', false); Settings::flush();
    $x = AiSensyAPI::sendMessage(RT_B, 'shg_trip_update', ['Ram', 'SHG-2026-00001', 'Delay', '-']);
    check('scope all: the bus update template is attempted', !str_contains((string) $x['error'], 'ticket-only'), json_encode($x));
    Database::delete('settings', "skey = 'aisensy_scope'", []); Settings::flush();

    echo "\n— Notify::whatsapp() and the ticket\n";
    check('a confirmed booking exists to work with', $bid > 0, 'none in the test DB');
    if ($bid > 0) {
        Database::update('bookings', ['contact_phone' => RT_C, 'alt_phone' => null], 'id = :i', ['i' => $bid]);
        Database::run("DELETE FROM message_logs WHERE booking_id = :b", ['b' => $bid]);   // old rows from other suites
        $booking['contact_phone'] = RT_C;   // the row this test works on, never the real customer's number
        $booking['alt_phone']     = null;
        $openWindow('91' . RT_C);
        $calls = [];
        $ok = Notify::whatsapp(RT_C, 'Your ticket ' . $pnr, Ticket::imageUrl($pnr), 'IN', Notify::ticketVars($booking), $bid, ['purpose' => 'ticket']);
        $j  = Notify::whatsappJournal();
        $last = $j !== [] ? $j[count($j) - 1] : [];
        check('ticket to an open window: true, journal driver cloud_api / meta_free', $ok === true && ($last['driver'] ?? '') === 'cloud_api' && ($last['reason'] ?? '') === 'meta_free', json_encode($last));
        check('… one Meta send, with the PNG', count($calls) === 1 && $calls[0]['image'] !== null, json_encode($calls));
        $q = Database::fetch("SELECT * FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p ORDER BY id DESC LIMIT 1", ['b' => $bid, 'p' => '91' . RT_C]);
        check('… the hi-queue knows the customer has it (meta free)', $q !== null && $q['status'] === 'sent' && str_starts_with((string) $q['via'], 'meta'), json_encode($q));
        $calls = [];
        $ok2 = Notify::whatsapp(RT_C, 'Your ticket ' . $pnr, Ticket::imageUrl($pnr), 'IN', [], $bid, ['purpose' => 'ticket']);
        $j  = Notify::whatsappJournal();
        $last = $j[count($j) - 1];
        check('a second ticket send is idempotent (no second Meta send)', $ok2 === true && ($last['reason'] ?? '') === 'idempotent' && $calls === [], json_encode($last));

        // AiSensyAPI::onBookingConfirmed after a Meta-free ticket: no template on top.
        $calls = [];
        $before = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND provider = 'aisensy'", ['b' => $bid], 0);
        AiSensyAPI::onBookingConfirmed($booking);
        $after = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE booking_id = :b AND provider = 'aisensy'", ['b' => $bid], 0);
        check('onBookingConfirmed after a Meta-free ticket: no AiSensy template', $after === $before && $calls === [], "aisensy rows $before -> $after, meta calls " . count($calls));

        echo "\n— the hi-queue on the Meta number\n";
        Database::run("DELETE FROM wa_ticket_queue WHERE booking_id = :b", ['b' => $bid]);
        Database::run("DELETE FROM message_logs WHERE booking_id = :b AND to_number LIKE '%" . RT_LIKE . "%'", ['b' => $bid]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => '91' . RT_C]);
        $qid = WaQueue::enqueue($bid, $pnr, '91' . RT_C, 'customer');
        check('a ticket waits in the queue', $qid > 0);
        $calls = [];
        $sent = WaQueue::onInbound('91' . RT_C, 'hi', 'meta');
        check('inbound on Meta with NO recorded window: nothing free goes (AiSensy refused: bogus key)', $sent === 0 && $calls === [], "sent=$sent");
        Database::run("UPDATE wa_ticket_queue SET status = 'pending', attempts = 0, last_error = NULL WHERE id = :i", ['i' => $qid]);
        $openWindow('91' . RT_C);            // what whatsapp/webhook.php records before calling onInbound
        $calls = [];
        $sent = WaQueue::onInbound('91' . RT_C, 'hi', 'meta');
        $q = Database::fetch('SELECT * FROM wa_ticket_queue WHERE id = :i', ['i' => $qid]);
        check('inbound on Meta with the window open: the ticket goes free as the PNG', $sent === 1 && count($calls) === 1 && $calls[0]['image'] !== null
            && $q !== null && $q['status'] === 'sent' && $q['via'] === 'meta free', json_encode($q));
        check('… the AiSensy window table is NOT written for a Meta inbound', !Database::exists('SELECT 1 FROM wa_windows WHERE phone = :p', ['p' => '91' . RT_C]));
        // A seller's waiting copies never dump on a Meta inbound (owner: "passenger list magda sara ticket aayo").
        $sid1 = WaQueue::enqueue($bid, $pnr, '91' . RT_A, 'seller');
        $calls = [];
        $sentS = WaQueue::onInbound('91' . RT_A, 'yatri list', 'meta');
        check('a seller writing a question on Meta gets NO ticket copies', $sentS === 0 && $calls === []
            && Database::scalar('SELECT status FROM wa_ticket_queue WHERE id = :i', ['i' => $sid1], '') === 'pending', "sent=$sentS");
        $sentS = WaQueue::onInbound('91' . RT_A, 'copy ' . $pnr, 'meta');
        check('… but the PNR they typed goes', $sentS === 1 && count($calls) === 1, "sent=$sentS");
        Database::run('DELETE FROM wa_ticket_queue WHERE id = :i', ['i' => $sid1]);
        $row = Database::fetch("SELECT * FROM message_logs WHERE provider_ref = :r", ['r' => (string) $q['wamid']]);
        check('… one message_logs row, cloud_api, purpose ticket', $row !== null && $row['provider'] === 'cloud_api' && ($row['purpose'] ?? '') === 'ticket'
            && (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE provider_ref = :r", ['r' => (string) $q['wamid']], 0) === 1, json_encode($row));

        // Meta failed it later (131047): the AiSensy template takes over once.
        $calls = [];
        $got = WaQueue::onMetaFailed((string) $q['wamid'], 131047);
        $q2  = Database::fetch('SELECT * FROM wa_ticket_queue WHERE id = :i', ['i' => $qid]);
        check('Meta async failure: handed to the AiSensy template (refused here: bogus key), Meta not retried',
            $got === false && $calls === [] && $q2 !== null && $q2['status'] === 'failed' && (string) $q2['via'] === 'ais after 131047', json_encode($q2));
        check('… an unknown wamid is ignored', WaQueue::onMetaFailed('wamid.NOPE', 131047) === false);
    }

    echo "\n— stats for the admin card\n";
    $s = WaRoute::stats();
    check('stats(): policy, free count today, open windows', $s['policy'] === 'meta_first' && $s['freeToday'] >= 1 && $s['windows'] >= 1 && $s['rate'] > 0, json_encode($s));
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
