<?php
/**
 * =====================================================================
 *  u3b-fb-inbox-test.php — Master v2 (16 Nov 2026), unit U3b:
 *    P6-6a  Messenger conversation stored in fb_inbound + the office
 *           inbox (threads, thread, unread/seen, 24-hour reply window,
 *           audited reply, failed send kept with its reason)
 *    P6-7c  AiHandoff::open for a Messenger visitor with no proven
 *           number: a help desk ticket with source 'facebook' and the
 *           PSID tag, no office message; handoff_status read back only to
 *           the same Messenger account (never to a number-less guest)
 *
 *  Uses the local test database. No Graph API call is made (transport
 *  seam), no model is asked (brain seam), nothing is sent. Settings are
 *  overridden in THIS process only (Settings cache), never written. Every
 *  row it makes is deleted at the end (marker: PSIDs starting 9900).
 *
 *    php -c .claude/php-dev.ini tests/u3b-fb-inbox-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/fbmessenger.php';
require_once INCLUDE_PATH . '/aihandoff.php';
require_once INCLUDE_PATH . '/helpdesk.php';

if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};

/* the migration (idempotent) */
foreach (preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', (string) file_get_contents('database/upgrade-2026-11-16-fb-inbound.sql')) ?? '') ?: [] as $stmt) {
    if (trim($stmt) !== '' && trim($stmt) !== 'SET NAMES utf8mb4') {
        Database::run(trim($stmt));
    }
}

/* settings for this process only */
Settings::get('company_name');
$mem = static function (string $k, string $v, string $t = 'string'): void {
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    $rp->setValue(null, $c);
};
$mem('fb_msgr_on', '1', 'bool');
$mem('fb_page_token', 'EAAU3BTEST' . str_repeat('x', 30));
$mem('fb_app_secret', 'u3b-test-secret');
$mem('fb_page_id', '');
$mem('wa_ops_handoff_on', '1', 'bool');
$mem('wa_ops_handoff_notify', '0', 'bool');

$P   = '9900' . random_int(100000000, 999999999);      // the visitor
$P2  = '9900' . random_int(100000000, 999999999);      // a visitor with a proven number
$P3  = '9900' . random_int(100000000, 999999999);      // someone else
$tag = 'u3b' . random_int(1000, 9999);
$adminId = (int) Database::scalar('SELECT MIN(id) FROM admins WHERE is_active = 1', [], 0);
$refs = [];

$sent = [];
$failNext = false;
FbMessenger::$transport = static function (string $m, string $path, array $body) use (&$sent, &$failNext): array {
    if (isset($body['message'])) {
        $sent[] = $body;
        if ($failNext) {
            $failNext = false;
            return ['ok' => false, 'http' => 400, 'body' => '{"error":{"message":"(#10) outside the allowed window"}}'];
        }
    }
    return ['ok' => true, 'http' => 200, 'body' => '{"recipient_id":"1","message_id":"m"}'];
};
$asked = [];
FbMessenger::$brain = static function (array $ctx, string $text) use (&$asked): ?array {
    $asked[] = $ctx;
    return ['text' => 'Bholi Surat bata 2 seat cha.', 'media' => null, 'actions' => [['label' => 'Book', 'href' => 'https://www.shreehariglobal.in/#/']]];   // Messenger buttons need https
};
FbMessenger::$memStore = [];

$rows = static fn(string $psid): array => Database::fetchAll('SELECT * FROM fb_inbound WHERE psid = :p ORDER BY id', ['p' => $psid]);

try {
    echo "\n1. Migration\n";
    $ok(FbMessenger::logReady(true), 'fb_inbound exists after the migration (run twice: no error)');
    $ok((string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'wa_noreply_alert_on'", [], 'x') === '0', 'wa_noreply_alert_on is seeded OFF');

    echo "\n2. A Messenger turn is written down\n";
    $mid1 = $tag . '-m1';
    FbMessenger::handle(['psid' => $P, 'page' => 'PAGE', 'text' => 'bholi surat bata 2 seat', 'payload' => '', 'mid' => $mid1, 'attachment' => false, 'kind' => 'message']);
    $r = $rows($P);
    $in = array_values(array_filter($r, static fn(array $x): bool => $x['direction'] === 'in'));
    $out = array_values(array_filter($r, static fn(array $x): bool => $x['direction'] === 'out'));
    $ok(count($in) === 1 && $in[0]['body'] === 'bholi surat bata 2 seat' && $in[0]['mid'] === $mid1 && $in[0]['kind'] === 'message', 'the person\'s words are stored with Meta\'s message id');
    $ok(count($out) === 2 && $out[0]['kind'] === 'text' && $out[0]['purpose'] === 'bot_reply' && $out[0]['status'] === 'sent', 'the AI\'s answer is stored as bot_reply, sent');
    $ok($out[1]['kind'] === 'buttons' && str_contains((string) $out[1]['body'], 'Book'), 'the buttons are stored by their titles');
    $ok(($asked[0]['psid'] ?? '') === $P, 'the assistant is handed the Messenger id (for a help desk ticket)');

    FbMessenger::$memStore = [];                          // Meta re-delivers after the bot forgot it
    FbMessenger::handle(['psid' => $P, 'page' => 'PAGE', 'text' => 'bholi surat bata 2 seat', 'payload' => '', 'mid' => $mid1, 'attachment' => false, 'kind' => 'message']);
    $ok(count(array_filter($rows($P), static fn(array $x): bool => $x['direction'] === 'in')) === 1, 'a re-delivered message id is stored once (UNIQUE mid)');

    FbMessenger::handle(['psid' => $P, 'page' => 'PAGE', 'text' => '482913', 'payload' => '', 'mid' => $tag . '-m2', 'attachment' => false, 'kind' => 'message']);
    $last = Database::fetch("SELECT body FROM fb_inbound WHERE psid = :p AND direction = 'in' ORDER BY id DESC LIMIT 1", ['p' => $P]);
    $ok(($last['body'] ?? '') === '[code hidden]', 'a typed login code is never stored');
    FbMessenger::handle(['psid' => $P, 'page' => 'PAGE', 'text' => 'card 4111 1111 1111 1111 password: hunter2', 'payload' => '', 'mid' => $tag . '-m3', 'attachment' => false, 'kind' => 'message']);
    $last = Database::fetch("SELECT body FROM fb_inbound WHERE psid = :p AND direction = 'in' ORDER BY id DESC LIMIT 1", ['p' => $P]);
    $ok(!str_contains((string) $last['body'], '4111') && !str_contains((string) $last['body'], 'hunter2'), 'card numbers and passwords are hidden');

    echo "\n3. The inbox\n";
    $seenAdmin = 990000000 + random_int(1, 99999);
    $th = array_values(array_filter(FbMessenger::threads($seenAdmin), static fn(array $t): bool => $t['psid'] === $P));
    $ok(count($th) === 1 && $th[0]['open'] === true && $th[0]['unread'] >= 3 && $th[0]['msgs'] >= 6, 'the person is one thread: window open, unread counted');
    $ok(str_starts_with($th[0]['last_text'], '↩ '), 'the thread shows the last line (ours, marked ↩)');
    $chat = FbMessenger::thread($P);
    $ok($chat !== [] && $chat[0]['dir'] === 'in' && end($chat)['dir'] === 'out', 'the conversation reads oldest first');
    $ok(FbMessenger::unread($seenAdmin) >= 3, 'unread count before opening');
    FbMessenger::markSeen($seenAdmin);
    $ok(FbMessenger::unread($seenAdmin) === 0, 'opening the inbox marks it seen for that admin');

    echo "\n4. Replying by hand\n";
    $sent = [];
    $ok(FbMessenger::windowOpen($P) === true, '24-hour window open after the person wrote');
    $r1 = FbMessenger::inboxReply($P, '   ', $adminId);
    $ok($r1['ok'] === false && $sent === [], 'an empty reply is refused, nothing sent');
    $r1 = FbMessenger::inboxReply($P, str_repeat('क', 1001), $adminId);
    $ok($r1['ok'] === false && $sent === [], 'a reply over 1000 characters is refused');
    $r1 = FbMessenger::inboxReply('bad psid!', 'hello', $adminId);
    $ok($r1['ok'] === false && $sent === [], 'a malformed Messenger id is refused');
    $r1 = FbMessenger::inboxReply($P, 'Namaste, office bata.', $adminId);
    $mine = Database::fetch("SELECT * FROM fb_inbound WHERE psid = :p AND purpose = 'inbox_reply' ORDER BY id DESC LIMIT 1", ['p' => $P]);
    $ok($r1['ok'] === true && count($sent) === 1 && ($sent[0]['messaging_type'] ?? '') === 'RESPONSE', 'inside the window the reply goes as a normal RESPONSE');
    $ok($mine !== null && (int) $mine['admin_id'] === $adminId && $mine['body'] === 'Namaste, office bata.', 'the reply is stored as inbox_reply with who sent it');
    $aud = Database::fetch("SELECT * FROM audit_logs WHERE action = 'fb.inbox_reply' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => substr(hash('sha256', $P), 0, 10)]);
    $ok($aud !== null && str_contains((string) $aud['new_value'], '"ok":true'), 'the reply is audited (fb.inbox_reply, hashed id)');
    $byName = array_values(array_filter(FbMessenger::thread($P), static fn(array $m): bool => $m['purpose'] === 'inbox_reply'));
    $ok($byName !== [] && $byName[0]['by'] !== '', 'the conversation names the staff member who replied');

    $sent = [];
    $failNext = true;
    $r2 = FbMessenger::inboxReply($P, 'Second line', $adminId);
    $bad = Database::fetch("SELECT * FROM fb_inbound WHERE psid = :p AND direction = 'out' ORDER BY id DESC LIMIT 1", ['p' => $P]);
    $ok($r2['ok'] === false && ($bad['status'] ?? '') === 'failed' && str_contains((string) $bad['error'], 'outside the allowed window'), 'a refused send is kept as failed with Facebook\'s reason');

    Database::run("UPDATE fb_inbound SET created_at = NOW() - INTERVAL 25 HOUR WHERE psid = :p", ['p' => $P]);
    $sent = [];
    $r3 = FbMessenger::inboxReply($P, 'Late reply', $adminId);
    $ok(FbMessenger::windowOpen($P) === false && $r3['ok'] === false && $sent === [], 'after 24 hours the window is closed and nothing is sent');
    $ok(str_contains($r3['why'], '24'), 'the office is told why');

    echo "\n5. A proven number shows in the inbox\n";
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('fb_msgr', :k, :v, 'u3b-test')",
        ['k' => $P2, 'v' => json_encode(['phone' => '9812345678', 'country' => 'NP'])]);
    $ok(FbMessenger::provenPhone($P2) === '9779812345678', 'a Nepal number proven on Messenger reads as +977');
    $ok(FbMessenger::provenPhone($P3) === '', 'no proven number reads empty');

    echo "\n6. Help desk ticket from Messenger without a number (P6-7c)\n";
    $fbCtx = ['role' => 'customer', 'phone' => '', 'intl' => '', 'channel' => 'web', 'via' => 'facebook', 'psid' => $P,
              'stageKey' => 'fb-' . $P, 'messageText' => 'mero paisa katiyo tara ticket aayena'];
    $h = AiHandoff::open($fbCtx, ['category' => 'complaint', 'summary' => 'Paisa katiyo tara ticket aayena ' . $tag]);
    $ref = (string) ($h['data']['ref'] ?? '');
    if ($ref !== '') { $refs[] = $ref; }
    $t = $ref !== '' ? Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => $ref]) : null;
    $ok($h['ok'] === true && $t !== null, 'the complaint is opened as a help desk ticket');
    $ok($t !== null && $t['source'] === 'facebook' && $t['phone'] === '' && $t['name'] === 'Facebook Messenger', 'source facebook, no phone, named Facebook Messenger');
    $ok($t !== null && str_starts_with((string) $t['message'], AiHandoff::psidMarker($P)), 'the message starts with the Messenger PSID tag');
    $ok(($h['data']['office_notified'] ?? true) === false && str_contains($h['say'], 'NOT been alerted'), 'no office message; the assistant is told so');
    $h2 = AiHandoff::open($fbCtx, ['category' => 'complaint', 'summary' => 'Paisa katiyo tara ticket aayena ' . $tag]);
    $ok(($h2['data']['duplicate'] ?? false) === true && ($h2['data']['ref'] ?? '') === $ref, 'the same complaint twice is one ticket');

    $st = AiHandoff::status($fbCtx, ['ref' => $ref]);
    $ok($st['ok'] === true && ($st['data']['status'] ?? '') === 'open', 'the same Messenger account can read its status');
    $other = ['psid' => $P3, 'stageKey' => 'fb-' . $P3] + $fbCtx;
    $ok(AiHandoff::status($other, ['ref' => $ref])['ok'] === false, 'another Messenger account cannot');
    $guest = ['role' => 'customer', 'phone' => '', 'channel' => 'web', 'stageKey' => 'web-guest-x'];
    $ok(AiHandoff::status($guest, ['ref' => $ref])['ok'] === false, 'a website guest with no number cannot (no empty-phone match)');
    $ok(AiHandoff::open($guest, ['category' => 'complaint', 'summary' => 'website guest complaint'])['ok'] === false, 'a website guest with no number still cannot open one');
    $ok(AiHandoff::psidOf(['channel' => 'web', 'psid' => $P]) === '', 'a PSID only counts on the Messenger channel');

    echo "\n7. The WhatsApp path is unchanged\n";
    $waCtx = ['role' => 'customer', 'phone' => '9800000077', 'intl' => '919800000077', 'channel' => 'whatsapp', 'messageText' => 'complaint'];
    $hw = AiHandoff::open($waCtx, ['category' => 'complaint', 'summary' => 'WhatsApp complaint ' . $tag]);
    $wref = (string) ($hw['data']['ref'] ?? '');
    if ($wref !== '') { $refs[] = $wref; }
    $tw = $wref !== '' ? Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => $wref]) : null;
    $ok($tw !== null && $tw['source'] === 'whatsapp_ai' && $tw['phone'] === '919800000077' && !str_contains((string) $tw['message'], 'PSID'), 'a WhatsApp ticket keeps its number and source');
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL  exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    /* put everything back */
    foreach ([$P, $P2, $P3] as $p) {
        Database::run('DELETE FROM fb_inbound WHERE psid = :p', ['p' => $p]);
        Database::run("DELETE FROM kv_store WHERE kscope IN ('fb_msgr', 'ai_web_hist', 'wa_stage') AND kkey IN (:a, :b)", ['a' => $p, 'b' => 'fb-' . $p]);
        Database::run("DELETE FROM rate_limits WHERE identifier IN (:a, :b)", ['a' => $p, 'b' => 'fb-' . $p]);
        Database::run("DELETE FROM audit_logs WHERE action = 'fb.inbox_reply' AND entity_id = :e", ['e' => substr(hash('sha256', $p), 0, 10)]);
    }
    if (isset($seenAdmin)) {
        Database::run("DELETE FROM kv_store WHERE kscope = 'fb_inbox_seen' AND kkey = :k", ['k' => (string) $seenAdmin]);
    }
    foreach ($refs as $r) {
        $id = (int) Database::scalar('SELECT id FROM support_tickets WHERE ticket_ref = :r', ['r' => $r], 0);
        if ($id > 0) {
            Database::run('DELETE FROM support_messages WHERE ticket_id = :i', ['i' => $id]);
            Database::run('DELETE FROM support_tickets WHERE id = :i', ['i' => $id]);
        }
        Database::run("DELETE FROM audit_logs WHERE entity_id = :r AND action IN ('support.handoff', 'support.autoassign')", ['r' => $r]);
    }
}

$left = (int) Database::scalar("SELECT COUNT(*) FROM fb_inbound WHERE psid IN (:a, :b, :c)", ['a' => $P, 'b' => $P2, 'c' => $P3], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE message LIKE :t", ['t' => '%' . $tag . '%'], 0);
$ok($left === 0, 'every test row was removed');

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
