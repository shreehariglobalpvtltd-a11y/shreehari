<?php
/**
 * =====================================================================
 *  u8-W4-wa-bot-test.php — the WhatsApp bot's three new switches
 *  (16 Nov 2026, SHG Network Master Upgrade v2.0, unit W4).
 *
 *    P6-2d  WaEdit::requestCancel(): the assistant's cancel for a passenger
 *           is a REQUEST — ticket_edit_audit row, office WhatsApp (in its
 *           window) + bell with the reason and the quoted refund; nothing
 *           is cancelled; a repeat within 6 h is not re-sent; the code
 *           path's office note is unchanged. (The AiTools side is pinned in
 *           tests/wa-agent-test.php.)
 *    P6-3b  WaInbox::handoffNotice(): ONE fixed in-window line, only while
 *           wa_handoff_msg_on is ON, audited like inbox_reply; the inbox page
 *           offers the "Tell the customer" tick only behind the switch.
 *    P7-8d  WaSend::typing(): status read + typing_indicator on the wamid
 *           with the stored credentials, a 3 s cap, never throws; the webhook
 *           calls it only while wa_typing_on is ON, before WaBot::reply.
 *
 *  Nothing leaves this machine: every send goes through a test transport,
 *  switches are flipped in memory only, and every row carries a u8w4 marker
 *  and is deleted at the end.
 *
 *      php -c .claude/php-dev.ini tests/u8-W4-wa-bot-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false && !getenv('SHG_ALLOW_TEST_ON_DB')) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/waagentroute.php';
require_once INCLUDE_PATH . '/waedit.php';
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/wasend.php';
require_once INCLUDE_PATH . '/notify.php';      // as admin/_guard.php: a send writes its message_logs row

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 400)) : '') . "\n"; }
}

/* ---- settings, in memory only: nothing global is written ---- */
function u8w4_set(array $ovr): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    foreach ($ovr as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => in_array($v, ['0', '1'], true) ? 'bool' : 'string', 'public' => false];
    }
    $rp->setValue(null, $c);
}
function u8w4_unset(string $k): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    unset($c[$k]);
    $rp->setValue(null, $c);
}

$tag   = (string) random_int(10000, 99999);
$CUST  = '9189' . $tag . '001';                 // international digits, never a real customer
$OTHER = '9189' . $tag . '002';
$ADMIN = '9189' . $tag . '009';                 // the office number, for this run only
$L     = static fn(string $d): string => substr($d, -10);
$bids  = [];
$pnrs  = [];
$freeCounted = 0;
$root  = dirname(__DIR__);
$src   = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

u8w4_set(['whatsapp_driver' => 'click_to_chat', 'wa_admin_number' => $ADMIN, 'wa_policy' => 'meta_first', 'wa_buttons_on' => '0']);

$cleanup = static function () use (&$bids, &$pnrs, $CUST, $OTHER, $ADMIN, $L, &$freeCounted): void {
    $q = static function (string $sql, array $p = []): void { try { Database::run($sql, $p); } catch (Throwable $e) {} };
    foreach ($bids as $bid) {
        $q('DELETE FROM ticket_edit_audit WHERE booking_id = ?', [$bid]);
        $q('DELETE FROM automation_log WHERE dedupe_key = ?', ['alert:wa_cancel:' . $bid]);
        $q("DELETE FROM rate_limits WHERE bucket = 'wa_ai_cancel_req' AND identifier = ?", [(string) $bid]);
        $q('DELETE FROM bookings WHERE id = ?', [$bid]);
    }
    foreach ($pnrs as $pnr) {
        $q("DELETE FROM notifications WHERE audience = 'admin' AND title = ?", ['Cancel request · ' . $pnr]);
        $q("DELETE FROM audit_logs WHERE action = 'booking.whatsapp_cancel_request' AND entity_id = ?", [$pnr]);
    }
    $all = [$CUST, $OTHER, $ADMIN, $L($CUST), $L($OTHER), $L($ADMIN)];
    $in  = implode(',', array_fill(0, count($all), '?'));
    $q("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey IN ($in)", $all);
    $q("DELETE FROM rate_limits WHERE bucket = 'wa_inbox_handoff' AND identifier IN ($in)", $all);
    $q("DELETE FROM audit_logs WHERE action IN ('wa.inbox_handoff', 'wa.bot_pause') AND entity_id IN ($in)", $all);
    $q("DELETE FROM message_logs WHERE to_number IN ($in)", $all);
    if ($freeCounted > 0) {   // the day's send counters our test line bumped (free + all)
        foreach (['free', 'all'] as $what) {
            $q("UPDATE kv_store SET kvalue = GREATEST(CAST(kvalue AS SIGNED) - ?, 0) WHERE kscope = 'wa_route' AND kkey = ?", [$freeCounted, date('Y-m-d') . ':' . $what]);
        }
        $freeCounted = 0;
    }
    WaAgentRoute::$transport = null;
    WaRoute::$transport = null;
    WaSend::$http = null;
    WaSend::$creds = null;
};
register_shutdown_function($cleanup);

$openWindow = static function (string $intl): void {
    Database::run(
        "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u8w4')
         ON DUPLICATE KEY UPDATE kvalue = :v2",
        ['k' => $intl, 'v' => (string) time(), 'v2' => (string) time()]
    );
};
$mkBooking = static function (string $status) use (&$bids, &$pnrs, $tag, $CUST, $L): array {
    $pnr = 'U8W4-' . $tag . '-' . count($bids);
    $id  = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $L($CUST), 'status' => $status]);
    $bids[] = $id;
    $pnrs[] = $pnr;
    return ['id' => $id, 'pnr' => $pnr];
};

echo "\n=== WhatsApp bot switches (W4) ===\n\n";
try {
    /* ---------------------------------------------------------------- P6-2d */
    echo "-- P6-2d the assistant's cancel is a REQUEST --\n";
    $office = [];
    WaAgentRoute::$transport = static function (string $to, string $text, ?string $image, ?array $ui) use (&$office): array {
        $office[] = ['to' => $to, 'text' => $text];
        return ['success' => true, 'message_id' => 'wamid.u8w4'];
    };
    $openWindow($ADMIN);
    $b = $mkBooking('confirmed');
    $r = WaEdit::requestCancel($b['pnr'], '+' . $CUST, 'IN', "Ghar ma kaam\n parne bhayo", 450.0);
    check('requestCancel() files it', $r['ok'] === true && $r['repeat'] === false, json_encode($r));
    $row = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request'", ['b' => $b['id']]);
    check('…a ticket_edit_audit row: requested, by this number, not code-verified',
        $row !== null && (string) $row['outcome'] === 'requested' && (int) $row['otp_verified'] === 0
        && (string) $row['changed_by'] === 'whatsapp:' . $CUST, json_encode($row));
    check('…its detail carries the reason (one line) and the quoted refund',
        $row !== null && str_contains((string) $row['detail'], 'Ghar ma kaam parne bhayo') && str_contains((string) $row['detail'], inr(450)), (string) ($row['detail'] ?? ''));
    $msg = $office[0] ?? null;
    check('…the office WhatsApp went to the office number (in its window)', $msg !== null && $msg['to'] === $ADMIN && count($office) === 1, json_encode($office));
    $t = (string) ($msg['text'] ?? '');
    check('…with the PNR, the passenger\'s number, the reason and the quoted refund',
        str_contains($t, $b['pnr']) && str_contains($t, '+' . $CUST) && str_contains($t, 'Reason: Ghar ma kaam parne bhayo')
        && str_contains($t, 'Refund by the slabs: ' . inr(450)), $t);
    check('…saying nothing was cancelled or refunded, and NOT "code verified"',
        str_contains($t, 'Nothing was cancelled or refunded') && !str_contains($t, 'code verified'), $t);
    $bell = Database::fetch("SELECT body, link FROM notifications WHERE audience = 'admin' AND title = :t ORDER BY id DESC LIMIT 1", ['t' => 'Cancel request · ' . $b['pnr']]);
    check('…the office bell, with the quoted refund and a panel link',
        $bell !== null && str_contains((string) $bell['body'], 'Quoted refund: ' . inr(450)) && str_contains((string) $bell['body'], 'via the assistant')
        && str_starts_with((string) $bell['link'], '/admin/booking-view.php'), json_encode($bell));
    check('…and the booking is untouched (still confirmed, no refund registered)',
        (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => $b['id']], '') === 'confirmed'
        && (string) Database::scalar("SELECT COALESCE(refund_status, 'none') FROM bookings WHERE id = :i", ['i' => $b['id']], '') !== 'pending');
    check('…audited (booking.whatsapp_cancel_request)', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'booking.whatsapp_cancel_request' AND entity_id = :p", ['p' => $b['pnr']]));

    $r2 = WaEdit::requestCancel($b['pnr'], '+' . $CUST, 'IN', 'again', 450.0);
    check('a second request within 6 h: ok, marked repeat, nothing re-sent',
        $r2['ok'] === true && $r2['repeat'] === true && count($office) === 1
        && (int) Database::scalar("SELECT COUNT(*) FROM ticket_edit_audit WHERE booking_id = :b", ['b' => $b['id']], 0) === 1, json_encode($r2));

    $c = $mkBooking('cancelled');
    $r3 = WaEdit::requestCancel($c['pnr'], '+' . $CUST, 'IN', '', 0.0);
    check('a cancelled booking cannot be requested again', $r3['ok'] === false && str_contains($r3['why'], 'CANCELLED') && count($office) === 1, json_encode($r3));
    check('an unknown PNR is refused', WaEdit::requestCancel('U8W4-NOPE-' . $tag, '+' . $CUST)['ok'] === false);

    // The 6-digit-code path keeps its old note word for word (reason / refund are only the assistant's).
    $tell = new ReflectionMethod('WaEdit', 'tellOffice');
    $cRow = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $c['id']]);
    $tell->invoke(null, $cRow, $CUST);
    $codeNote = (string) ($office[1]['text'] ?? '');
    check('the code path\'s office note is unchanged',
        $codeNote === "📝 रद्द अनुरोध · Cancel request\nPNR: " . $c['pnr'] . "\nयात्रु नम्बर: +" . $CUST . "\n(कोडले पुष्टि गरिएको · code verified)\nAdmin → Bookings मा हेर्नुहोस्।", $codeNote);

    // The members' desk context carries a bare 10-digit number: the booking's own country code is used.
    $np = $mkBooking('confirmed');
    Database::update('bookings', ['contact_country_code' => '977'], 'id = :i', ['i' => $np['id']]);
    $rNp = WaEdit::requestCancel($np['pnr'], $L($CUST), '', '', null);
    $npNote = (string) ($office[2]['text'] ?? '');
    check('a bare 10-digit sender on a Nepali booking is shown as +977, with no refund line when none was quoted',
        $rNp['ok'] === true && str_contains($npNote, '+977' . $L($CUST)) && !str_contains($npNote, 'Refund by the slabs') && !str_contains($npNote, 'Reason:'), $npNote);
    WaAgentRoute::$transport = null;

    /* ---------------------------------------------------------------- P6-3b */
    echo "-- P6-3b hand-off line --\n";
    $sent = [];
    WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$sent): array {
        $sent[] = ['to' => $to, 'text' => $text];
        return ['success' => true, 'message_id' => 'wamid.u8w4.h' . count($sent), 'error' => ''];
    };
    u8w4_unset('wa_handoff_msg_on');
    check('wa_handoff_msg_on defaults OFF', WaInbox::handoffOn() === false);
    u8w4_set(['wa_handoff_msg_on' => '0']);
    $openWindow($OTHER);
    $h0 = WaInbox::handoffNotice($OTHER, 1);
    check('switch OFF: nothing is sent', $h0['sent'] === false && $sent === [], json_encode($h0));
    u8w4_set(['wa_handoff_msg_on' => '1']);
    check('switch ON: handoffOn()', WaInbox::handoffOn() === true);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => $OTHER]);
    $h1 = WaInbox::handoffNotice($OTHER, 1);
    check('window closed: nothing is sent, and the office is told why', $h1['sent'] === false && str_contains($h1['why'], 'window') && $sent === [], json_encode($h1));
    $openWindow($OTHER);
    $h2 = WaInbox::handoffNotice($L($OTHER), 1);
    $freeCounted += $h2['sent'] ? 1 : 0;
    check('window open: ONE line is sent', $h2['sent'] === true && count($sent) === 1 && ($sent[0]['to'] ?? '') === $OTHER, json_encode([$h2, $sent]));
    check('…the fixed line, Nepali first, short English after',
        ($sent[0]['text'] ?? '') === WaInbox::HANDOFF_TEXT
        && str_starts_with(WaInbox::HANDOFF_TEXT, 'Namaste, S Hari office bata ek jana le aba tapai lai sahayog garnuhunchha.')
        && str_contains(WaInbox::HANDOFF_TEXT, "\nA person from our office will help you now."));
    $al = Database::fetch("SELECT new_value FROM audit_logs WHERE action = 'wa.inbox_handoff' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => $OTHER]);
    check('…audited like inbox_reply (wa.inbox_handoff, ok)', $al !== null && str_contains((string) $al['new_value'], '"ok":true'), json_encode($al));
    check('…logged as message purpose inbox_handoff',
        Database::exists("SELECT 1 FROM message_logs WHERE to_number IN (:a, :b) AND purpose = 'inbox_handoff'", ['a' => $OTHER, 'b' => $L($OTHER)]));
    $h3 = WaInbox::handoffNotice($OTHER, 1);
    check('a second tick within 10 minutes is not sent again', $h3['sent'] === false && count($sent) === 1, json_encode($h3));
    check('a non-number is refused', WaInbox::handoffNotice('abc', 1)['sent'] === false && count($sent) === 1);
    // Review fix: a refused send must not leave the 10-minute dedupe behind ("they were told" would be false).
    Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_inbox_handoff' AND identifier = ?", [$OTHER]);
    WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$sent): array {
        $sent[] = ['to' => $to, 'text' => $text, 'refused' => true];
        return ['success' => false, 'message_id' => '', 'error' => '(code 131000) u8w4 refusal'];
    };
    $hF = WaInbox::handoffNotice($OTHER, 1);
    check('a refused send is reported as not sent', $hF['sent'] === false && str_contains($hF['why'], 'did not accept') && count($sent) === 2, json_encode($hF));
    check('…and leaves no dedupe behind, so the next tick may try again',
        !Database::exists("SELECT 1 FROM rate_limits WHERE bucket = 'wa_inbox_handoff' AND identifier = ?", [$OTHER]));
    $hF2 = WaInbox::handoffNotice($OTHER, 1);
    check('…the retry is attempted (not "told a few minutes ago")', $hF2['sent'] === false && !str_contains($hF2['why'], 'few minutes') && count($sent) === 3, json_encode($hF2));
    u8w4_set(['wa_handoff_msg_on' => '0']);
    WaRoute::$transport = null;

    $page = $src('admin/wa-inbox.php');
    $pOn  = strpos($page, '<?php if (WaInbox::handoffOn()): ?>');
    $pBox = strpos($page, '<input type="checkbox" name="tell" value="1">');
    $pEnd = $pOn !== false ? strpos($page, '<?php endif; ?>', $pOn) : false;
    check('inbox page: the tick is drawn only behind the switch', $pOn !== false && $pBox !== false && $pEnd !== false && $pOn < $pBox && $pBox < $pEnd
        && substr_count($page, 'name="tell"') === 1);
    check('inbox page: the POST sends only on a pause with hours > 0, the tick and the switch',
        str_contains($page, "if (\$h > 0 && (string) (\$_POST['tell'] ?? '') === '1' && WaInbox::handoffOn())") && str_contains($page, 'WaInbox::handoffNotice($who, $adminId)'));

    /* ---------------------------------------------------------------- P7-8d */
    echo "-- P7-8d read + typing indicator --\n";
    $calls = [];
    WaSend::$creds = ['token' => 'tok-u8w4', 'phone_id' => '10101', 'base' => 'https://graph.example.test/v25.0'];
    WaSend::$http = static function (string $url, array $body) use (&$calls): array {
        $calls[] = ['url' => $url, 'body' => $body];
        return ['http' => 200, 'body' => '{"success":true}'];
    };
    $ok = WaSend::typing('wamid.HBgMOTE5ODc2NTQzMjEw');
    check('typing() posts once and reports Meta\'s yes', $ok === true && count($calls) === 1);
    check('…to {base}/{phone id}/messages', ($calls[0]['url'] ?? '') === 'https://graph.example.test/v25.0/10101/messages', (string) ($calls[0]['url'] ?? ''));
    check('…status read + typing_indicator on that wamid, no "to", no text',
        ($calls[0]['body'] ?? null) === ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => 'wamid.HBgMOTE5ODc2NTQzMjEw', 'typing_indicator' => ['type' => 'text']],
        json_encode($calls[0]['body'] ?? null));
    WaSend::$http = static function (string $url, array $body) use (&$calls): array { $calls[] = 1; return ['http' => 400, 'body' => '{"error":{"code":100}}']; };
    check('a refusal is false, never an exception', WaSend::typing('wamid.x') === false);
    WaSend::$http = static function (string $url, array $body): array { throw new RuntimeException('network down'); };
    $threw = false;
    try { $v = WaSend::typing('wamid.y'); } catch (Throwable $e) { $threw = true; $v = null; }
    check('a network failure is false, never an exception', $threw === false && $v === false);
    $n = count($calls);
    WaSend::$http = static function (string $url, array $body) use (&$calls): array { $calls[] = 1; return ['http' => 200, 'body' => '{}']; };
    check('no wamid: no call', WaSend::typing('  ') === false && count($calls) === $n);
    WaSend::$creds = ['token' => '', 'phone_id' => '10101'];
    check('no credentials: no call', WaSend::typing('wamid.z') === false && count($calls) === $n);
    WaSend::$http = null;
    WaSend::$creds = null;

    $ws = $src('includes/wasend.php');
    $fn = substr($ws, (int) strpos($ws, 'public static function typing('), 2200);
    check('typing() uses the stored credentials (creds()) and a hard 3 s cap',
        str_contains($fn, '$c = self::creds();') && str_contains($fn, 'CURLOPT_TIMEOUT_MS        => 3000') && str_contains($fn, 'CURLOPT_CONNECTTIMEOUT_MS => 2000'));
    $wh = $src('whatsapp/webhook.php');
    $pT = strpos($wh, 'WaSend::typing($wamid)');
    $pP = strpos($wh, 'WaInbox::botPaused($from)');
    $pR = strpos($wh, "WaBot::reply('+' . \$from");
    check('webhook: typing only while wa_typing_on (default OFF), inside try/catch',
        str_contains($wh, "if (Settings::getBool('wa_typing_on', false)) {") && (bool) preg_match('/try \{\s*if \(Settings::getBool\(\'wa_typing_on\', false\)\) \{.*?\} catch \(Throwable \$ignored\) \{/s', $wh));
    check('webhook: after the paused-bot check, before WaBot::reply', $pT !== false && $pP !== false && $pR !== false && $pP < $pT && $pT < $pR);

    /* ------------------------------------------------------------ migration */
    echo "-- migration --\n";
    $sql = $src('database/upgrade-2026-11-16b-W4-wa-bot.sql');
    check('the migration seeds wa_ai_cancel_mode = request', str_contains($sql, "('wa_ai_cancel_mode', 'request', 'string', 'whatsapp'"));
    check('…wa_handoff_msg_on = 0 and wa_typing_on = 0',
        str_contains($sql, "('wa_handoff_msg_on', '0', 'bool', 'whatsapp'") && str_contains($sql, "('wa_typing_on', '0', 'bool', 'whatsapp'"));
    check('…additive only (INSERT IGNORE, nothing dropped or updated)',
        str_contains($sql, 'INSERT IGNORE INTO settings') && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $sql));
    $have = Database::fetchAll("SELECT skey, svalue FROM settings WHERE skey IN ('wa_ai_cancel_mode', 'wa_handoff_msg_on', 'wa_typing_on')");
    echo '     (' . count($have) . " of 3 rows present in this database)\n";
    foreach ($have as $h) {
        check('  stored ' . $h['skey'] . ' is a known value', in_array((string) $h['svalue'], ['request', 'self', '0', '1'], true), (string) $h['svalue']);
    }
} catch (Throwable $e) {
    check('no exception: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
