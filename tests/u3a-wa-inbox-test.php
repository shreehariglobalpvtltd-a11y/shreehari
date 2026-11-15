<?php
/**
 * =====================================================================
 *  u3a-wa-inbox-test.php — the WhatsApp inbox as the office's hub
 *  (16 Nov 2026, SHG Network Master Upgrade v2.0, unit U3a).
 *
 *  Pins, against throwaway rows only:
 *    P6-1g  unread PER conversation: opening one chat clears only its badge
 *    P6-1b  a name for each number (customer master > account > saved
 *           WhatsApp contact > lead passenger of the latest booking)
 *    P6-1e  search by digits or name, widening to 30 days
 *    P6-1d  state: Agent needed (open help-desk ticket) / Agent handling
 *           (bot paused) / AI; "Resolved" resumes the bot + resolves the
 *           number's open tickets, audited
 *    P6-9c  Escalate opens a help-desk ticket WITHOUT messaging the customer
 *    P6-10a responseStats(): inbound -> first bot_reply / inbox_reply
 *    P6-9a  channelTabs() + the Sent list (one-time codes masked)
 *    P6-9b  WaFaq::suggest() is side-effect free and works with wa_faq_on off
 *    P6-2a  the timing answer carries ONE booking-link line
 *    P7-9b  👍 / 👎 on a bot reply -> ai_feedback_log (hashed number, redacted)
 *
 *  Nothing leaves this machine: help-desk acknowledgements and office alerts
 *  are switched off in memory; no reply() is called. Every row it writes
 *  carries a u3a marker and is deleted at the end.
 *
 *      php -c .claude/php-dev.ini tests/u3a-wa-inbox-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false && !getenv('SHG_ALLOW_TEST_ON_DB')) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/wainbox.php';
require_once INCLUDE_PATH . '/wafaq.php';
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/aiknowledge.php';
require_once INCLUDE_PATH . '/ticketbot.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 300)) : '') . "\n"; }
}

/* ---- settings, in memory only: nothing is sent, nothing is written ---- */
$OVR = ['helpdesk_ack' => '0', 'helpdesk_office_alert' => '0', 'helpdesk_autoassign' => '0', 'wa_faq_on' => '0',
        'ai_kb_on' => '0', 'whatsapp_driver' => 'click_to_chat', 'wa_report_chart_on' => '0', 'wa_seat_photo_on' => '0'];
function u3a_apply(array $ovr): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    foreach ($ovr as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => in_array($v, ['0', '1'], true) ? 'bool' : 'string', 'public' => false];
    }
    $rp->setValue(null, $c);
}
u3a_apply($OVR);

$tag  = (string) random_int(10000, 99999);
$P    = [];                                     // international digits, 12 long, never a real customer
for ($i = 1; $i <= 7; $i++) { $P[$i] = '9190' . $tag . str_pad((string) $i, 3, '0', STR_PAD_LEFT); }
$L    = array_map(static fn(string $d): string => substr($d, -10), $P);
$FAKE = 990000 + random_int(1, 9000);           // a kv-only admin id for the seen bookkeeping
$REAL = (int) Database::scalar('SELECT id FROM admins ORDER BY id LIMIT 1', [], 0);
$refs = [];
$bookingId = 0;

$inbound = static function (string $from, string $text, ?string $at = null) use ($tag): int {
    return (int) Database::insert('wa_inbound', [
        'wamid' => 'wamid.u3a.' . $tag . '.' . bin2hex(random_bytes(5)), 'from_digits' => $from, 'msg_type' => 'text',
        'payload' => json_encode(['text' => ['body' => $text]], JSON_UNESCAPED_UNICODE), 'status' => 'done',
    ] + ($at !== null ? ['created_at' => $at, 'updated_at' => $at] : []));
};
$outbound = static function (string $to, string $body, string $purpose, string $status = 'sent', ?string $at = null): int {
    return (int) Database::insert('message_logs', [
        'channel' => 'whatsapp', 'purpose' => $purpose, 'provider' => 'u3a-test', 'to_number' => $to,
        'body' => $body, 'status' => $status,
    ] + ($at !== null ? ['created_at' => $at] : []));
};
$rowOf = static function (array $threads, string $d): ?array {
    foreach ($threads as $t) { if ($t['digits'] === $d) { return $t; } }
    return null;
};

$cleanup = static function () use (&$P, &$L, $FAKE, &$refs, &$bookingId, $tag): void {
    $in = static fn(array $v): string => implode(',', array_fill(0, count($v), '?'));
    $all = array_merge(array_values($P), array_values($L));
    $q = static function (string $sql, array $p = []): void { try { Database::run($sql, $p); } catch (Throwable $e) {} };
    $q('DELETE FROM wa_inbound WHERE wamid LIKE ?', ['wamid.u3a.' . $tag . '.%']);
    $q("DELETE FROM audit_logs WHERE action = 'ai.feedback' AND entity_id IN (SELECT id FROM message_logs WHERE provider = 'u3a-test')");
    $q("DELETE FROM message_logs WHERE to_number IN ({$in($all)})", $all);
    $ids = [];
    try { $ids = array_map(static fn(array $r): int => (int) $r['id'], Database::fetchAll("SELECT id, ticket_ref FROM support_tickets WHERE RIGHT(phone, 10) IN ({$in(array_values($L))})", array_values($L))); } catch (Throwable $e) {}
    if ($ids !== []) {
        $q('DELETE FROM support_messages WHERE ticket_id IN (' . implode(',', $ids) . ')');
        $q('DELETE FROM support_tickets WHERE id IN (' . implode(',', $ids) . ')');
    }
    if ($refs !== []) { $q("DELETE FROM audit_logs WHERE entity_id IN ({$in($refs)})", $refs); }
    $q("DELETE FROM audit_logs WHERE entity_id IN ({$in($all)})", $all);
    $q("DELETE FROM kv_store WHERE kscope = 'wa_inbox_seen_t' AND kkey LIKE ?", [$FAKE . ':%']);
    $q("DELETE FROM kv_store WHERE kscope = 'wa_inbox_seen' AND kkey = ?", [(string) $FAKE]);
    $q("DELETE FROM kv_store WHERE kscope IN ('wa_bot_pause', 'wa_greet', 'wa_agent', 'wa_inbox_seen_t') AND (kkey IN ({$in($all)}) OR kkey LIKE ?)", array_merge($all, ['%:9190' . $tag . '%']));
    $q("DELETE FROM wa_contacts WHERE phone IN ({$in($all)})", $all);
    $q("DELETE FROM user_profiles WHERE phone IN ({$in(array_values($L))})", array_values($L));
    $q("DELETE FROM ai_agent_calls WHERE phone IN ({$in($all)})", $all);
    $hashes = array_map(static fn(string $d): string => hash('sha256', $d), array_values($P));
    $q("DELETE FROM ai_feedback_log WHERE owner_key IN ({$in($hashes)})", $hashes);
    if ($bookingId > 0) { $q('DELETE FROM bookings WHERE id = ?', [$bookingId]); }
};
register_shutdown_function($cleanup);

echo "\n=== WhatsApp inbox hub (U3a) ===\n\n";
try {
    /* ---------------------------------------------------------------- P6-1g */
    echo "-- P6-1g unread per conversation --\n";
    $inbound($P[1], 'namaste');
    $inbound($P[1], 'bholi Surat bata bus kati baje chhutcha?');
    $inbound($P[2], 'hello');
    $inbound($P[2], 'seat cha?');
    $inbound($P[2], 'kati ho?');
    $th = WaInbox::threads($FAKE);
    check('a new admin sees 2 unread on chat 1', ($rowOf($th, $P[1])['unread'] ?? -1) === 2);
    check('…and 3 unread on chat 2', ($rowOf($th, $P[2])['unread'] ?? -1) === 3);
    WaInbox::markThreadSeen($FAKE, $P[1]);
    $th = WaInbox::threads($FAKE);
    check('opening chat 1 clears ONLY chat 1', ($rowOf($th, $P[1])['unread'] ?? -1) === 0 && ($rowOf($th, $P[2])['unread'] ?? -1) === 3);
    $inbound($P[1], 'hello?');
    $th = WaInbox::threads($FAKE);
    check('a later message in chat 1 counts again (1)', ($rowOf($th, $P[1])['unread'] ?? -1) === 1);
    check('another admin is untouched', ($rowOf(WaInbox::threads($FAKE + 1), $P[1])['unread'] ?? -1) === 3);
    WaInbox::markSeen($FAKE);
    $th = WaInbox::threads($FAKE);
    check('"Mark all read" (the per-admin floor) still clears every chat', ($rowOf($th, $P[1])['unread'] ?? -1) === 0 && ($rowOf($th, $P[2])['unread'] ?? -1) === 0);
    check('unread() is an int', is_int(WaInbox::unread($FAKE)));

    /* ---------------------------------------------------------------- P6-1b */
    echo "-- P6-1b names --\n";
    Database::run('INSERT INTO wa_contacts (name, phone, note, created_by, created_at, updated_at) VALUES (?, ?, ?, NULL, NOW(), NOW())', ['U3a Contact Ramesh', $P[1], 'u3a']);
    Database::run('INSERT INTO wa_contacts (name, phone, note, created_by, created_at, updated_at) VALUES (?, ?, ?, NULL, NOW(), NOW())', ['U3a Contact Other', $P[2], 'u3a']);
    Database::run('INSERT INTO user_profiles (phone, customer_no, full_name) VALUES (?, ?, ?)', [$L[2], 8800000 + (int) $tag, 'U3a Profile Sita']);
    $inbound($P[3], 'ticket chahiyo');
    $bookingId = (int) Database::insert('bookings', ['pnr' => 'U3A-' . $tag, 'contact_phone' => $L[3]]);
    Database::insert('booking_passengers', ['booking_id' => $bookingId, 'passenger_ref' => 'U3A' . $tag . 'A', 'seat_no' => 'A1', 'full_name' => 'U3a Lead Hari', 'is_primary' => 1]);
    $names = WaInbox::names([$P[1], $P[2], $P[3], $P[4]]);
    check('a saved WhatsApp contact names the number', ($names[$L[1]]['name'] ?? '') === 'U3a Contact Ramesh' && ($names[$L[1]]['source'] ?? '') === 'contact');
    check('the customer master wins over a saved contact', ($names[$L[2]]['name'] ?? '') === 'U3a Profile Sita' && ($names[$L[2]]['source'] ?? '') === 'customer');
    check('else the lead passenger of their latest booking', ($names[$L[3]]['name'] ?? '') === 'U3a Lead Hari' && ($names[$L[3]]['source'] ?? '') === 'booking');
    check('an unknown number has no name (never a guess)', !isset($names[$L[4]]));
    $th = WaInbox::threads($FAKE);
    check('the conversation list carries the name', ($rowOf($th, $P[2])['name'] ?? '') === 'U3a Profile Sita');
    $con = array_values(array_filter(WaInbox::contacts(), static fn(array $c): bool => $c['digits'] === $P[3]));
    check('Contacts tab: a passenger has a name now', ($con[0]['name'] ?? '') === 'U3a Lead Hari');

    /* ---------------------------------------------------------------- P6-1e */
    echo "-- P6-1e search --\n";
    $inbound($P[4], 'purano sandesh', date('Y-m-d H:i:s', strtotime('-20 days')));
    $hit = WaInbox::threads($FAKE, 7, 80, 'contact ramesh');
    check('search by name finds the chat', $rowOf($hit, $P[1]) !== null && $rowOf($hit, $P[2]) === null);
    $hit = WaInbox::threads($FAKE, 7, 80, substr($P[2], -6));
    check('search by digits finds the chat', $rowOf($hit, $P[2]) !== null && $rowOf($hit, $P[1]) === null);
    check('without a search an older chat (20 days) is not listed', $rowOf(WaInbox::threads($FAKE), $P[4]) === null);
    check('a search looks back 30 days', $rowOf(WaInbox::threads($FAKE, 7, 80, substr($P[4], -7)), $P[4]) !== null);
    check('a search that matches nobody: empty', WaInbox::threads($FAKE, 7, 80, 'zzq nobody here') === []);

    /* ---------------------------------------------------------------- P6-1d */
    echo "-- P6-1d state + Resolved --\n";
    check('no ticket, bot on: AI', WaInbox::statusFor($P[1]) === 'ai' && ($rowOf(WaInbox::threads($FAKE), $P[1])['status'] ?? '') === 'ai');
    $tid = (int) Database::insert('support_tickets', ['ticket_ref' => 'HD-U3A' . $tag, 'name' => 'U3a', 'phone' => $P[2], 'subject' => 'u3a',
        'message' => 'u3a test', 'category' => 'general', 'status' => 'open', 'source' => 'whatsapp_ai']);
    $refs[] = 'HD-U3A' . $tag;
    check('an open help-desk ticket for the number: Agent needed', WaInbox::statusFor($P[2]) === 'needed'
        && ($rowOf(WaInbox::threads($FAKE), $P[2])['status'] ?? '') === 'needed' && ($rowOf(WaInbox::threads($FAKE), $P[2])['tickets'] ?? 0) === 1);
    WaInbox::pause($P[1], 2, $REAL);
    check('bot paused: Agent handling', WaInbox::statusFor($P[1]) === 'agent');
    check('statusOf(): a pause outranks a ticket', WaInbox::statusOf(true, 2) === 'agent' && WaInbox::statusOf(false, 1) === 'needed' && WaInbox::statusOf(false, 0) === 'ai');
    $r = WaInbox::resolve($P[2], $REAL);
    $t = Database::fetch('SELECT status, resolved_at FROM support_tickets WHERE id = :i', ['i' => $tid]);
    check('Resolved closes the number\'s open ticket', ($t['status'] ?? '') === 'resolved' && !empty($t['resolved_at']) && $r['tickets'] === ['HD-U3A' . $tag]);
    check('…the state is AI again', WaInbox::statusFor($P[2]) === 'ai');
    check('…audited (support.status old→new)', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'support.status' AND entity_id = :e", ['e' => 'HD-U3A' . $tag]));
    $r = WaInbox::resolve($P[1], $REAL);
    check('Resolved on a paused chat resumes the bot', $r['resumed'] === true && !WaInbox::botPaused($P[1]) && WaInbox::statusFor($P[1]) === 'ai');
    check('…audited as wa.inbox_resolved', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.inbox_resolved' AND entity_id = :e", ['e' => $P[1]]));

    /* ---------------------------------------------------------------- P6-9c */
    echo "-- P6-9c Escalate --\n";
    $before = (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE to_number IN (?, ?)', [$P[1], $L[1]], 0);
    $esc = WaInbox::escalate($P[1], $REAL, 'U3a Contact Ramesh');
    if (($esc['ref'] ?? '') !== '') { $refs[] = $esc['ref']; }
    $tk = Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => $esc['ref']]);
    check('Escalate opens a help-desk ticket', $esc['ok'] === true && $tk !== null && (string) $tk['status'] === 'open');
    check('…typed by the office, with their last message', (string) ($tk['source'] ?? '') === 'desk' && str_contains((string) ($tk['message'] ?? ''), 'hello?'));
    check('…and the customer is NOT messaged', empty($tk['ack_sent_at'])
        && (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE to_number IN (?, ?)', [$P[1], $L[1]], 0) === $before);
    check('…the chat now reads Agent needed', WaInbox::statusFor($P[1]) === 'needed');
    check('…audited as wa.inbox_escalate', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.inbox_escalate' AND entity_id = :e", ['e' => $P[1]]));
    WaInbox::resolve($P[1], $REAL);
    check('…and Resolved closes it again', WaInbox::statusFor($P[1]) === 'ai');
    check('lastInbound() is their newest words', WaInbox::lastInbound($P[1]) === 'hello?');

    /* --------------------------------------------------------------- P6-10a */
    echo "-- P6-10a response times --\n";
    $day = '2024-02-29';
    $inbound($P[5], 'q1', "$day 10:00:00");  $outbound($P[5], 'a1', 'bot_reply', 'sent', "$day 10:00:12");
    $inbound($P[5], 'q2', "$day 11:00:00");  $outbound($L[5], 'a2', 'inbox_reply', 'sent', "$day 11:04:00");
    $inbound($P[6], 'q3', "$day 12:00:00");  $outbound($P[6], 'a3', 'bot_reply', 'sent', "$day 12:01:00");
    $inbound($P[6], 'q4', "$day 13:00:00");                                             // never answered
    $inbound($P[7], 'q5', "$day 14:00:00");  $outbound($P[7], 'a5', 'bot_reply', 'failed', "$day 14:00:03");   // failed = not answered
    $rs = WaInbox::responseStats($day);
    foreach (['ai_n', 'agent_n', 'ai_avg_s', 'agent_avg_s', 'ai_within_30s_pct', 'agent_within_5m_pct'] as $k) {
        check("responseStats() has the contract key $k", array_key_exists($k, $rs));
    }
    check('AI: 2 replies, average 36 s, 50% within 30 s', $rs['ai_n'] === 2 && $rs['ai_avg_s'] === 36.0 && $rs['ai_within_30s_pct'] === 50.0, json_encode($rs));
    check('agent: 1 reply (10-digit to_number), 4 min, 100% within 5 min', $rs['agent_n'] === 1 && $rs['agent_avg_s'] === 240.0 && $rs['agent_within_5m_pct'] === 100.0, json_encode($rs));
    check('unanswered and failed are counted apart, never as 0 s', $rs['unanswered_n'] === 2 && $rs['inbound_n'] === 5, json_encode($rs));
    $none = WaInbox::responseStats('2024-02-28');
    check('a quiet day: zeros and nulls', $none['ai_n'] === 0 && $none['ai_avg_s'] === null && $none['agent_within_5m_pct'] === null);
    check('a bad date is refused', WaInbox::responseStats('29-02-2024')['ai_n'] === 0);

    /* ---------------------------------------------------------------- P6-9a */
    echo "-- P6-9a channel tabs + Sent --\n";
    $tabs = WaInbox::channelTabs('whatsapp');
    check('channelTabs(): WhatsApp tab, active', str_contains($tabs, 'href="wa-inbox.php"') && str_contains($tabs, 'class="on"') && str_contains($tabs, 'WhatsApp'));
    check('channelTabs(): Messenger only when its page exists', str_contains($tabs, 'messenger-inbox.php') === is_file(ROOT_PATH . '/admin/messenger-inbox.php'));
    check('channelTabs(): Sent needs dashboard.view (none in CLI)', !str_contains($tabs, 'tab=sent'));
    check('channelTabs(): its small style is printed once', substr_count($tabs . WaInbox::channelTabs('messenger'), '<style>') === 1);
    $outbound($P[1], 'Your login code is 482913', 'login_code');
    $outbound($P[1], 'Bus leaves 13:00', 'bot_reply');
    $sent = WaInbox::sent(7, substr($P[1], -8));
    $codes = array_values(array_filter($sent, static fn(array $m): bool => $m['purpose'] === 'login_code'));
    check('Sent lists what went to the number', count($sent) >= 2);
    check('…with one-time codes masked', $codes !== [] && !str_contains($codes[0]['body'], '482913') && str_contains($codes[0]['body'], '••••'));

    /* ---------------------------------------------------------- P6-9b + 2a */
    echo "-- P6-9b suggest (side-effect free) + P6-2a booking link --\n";
    $who = normalisePhone('+' . $P[3]);
    $calls = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM ai_agent_calls WHERE phone IN (?, ?)', [$who, $P[3]], 0);
    $mem   = static fn(): int => (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope IN ('wa_agent', 'wa_greet') AND kkey IN (?, ?)", [$who, $P[3]], 0);
    $c0 = $calls(); $m0 = $mem();
    check('wa_faq_on is off here: the real answer() stays silent', WaFaq::answer('+' . $P[3], 'Surat bata bus kati baje chhutcha?') === null);
    $sug = WaFaq::suggest('+' . $P[3], 'Surat bata bus kati baje chhutcha?');
    check('suggest() drafts the timetable answer anyway', $sug !== null && preg_match('/\d:\d\d (AM|PM)/', $sug) === 1, (string) $sug);
    check('…with ONE booking-link line (P6-2a)', $sug !== null && substr_count($sug, appUrl('')) === 1 && str_contains($sug, '🎫'), (string) $sug);
    $hello = WaFaq::suggest('+' . $P[3], 'namaste');
    check('suggest() answers a hello too', $hello !== null && str_contains($hello, 'नमस्ते'), (string) $hello);
    check('suggest() writes NO ai_agent_calls row', $calls() === $c0);
    check('suggest() writes NO memory / greeting stamp', $mem() === $m0);
    check('a personal question gets no draft (the office reads their booking)', WaFaq::suggest('+' . $P[3], 'mero ticket aayena') === null);
    $wholeTable = WaFaq::factAnswer('+' . $P[3], 'bus ko timing k ho?');
    check('the full-timetable answer has the link once too', $wholeTable !== null && substr_count((string) $wholeTable['text'], appUrl('')) === 1, (string) ($wholeTable['text'] ?? ''));
    Database::run("DELETE FROM ai_agent_calls WHERE phone IN (?, ?)", [$who, $P[3]]);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_agent' AND kkey IN (?, ?)", [$who, $P[3]]);

    /* ---------------------------------------------------------------- P7-9b */
    echo "-- P7-9b 👍 / 👎 --\n";
    $inbound($P[1], 'luggage kati kg?');
    $botId = $outbound($P[1], 'Each passenger may carry 20 kg.', 'bot_reply');
    $deskId = $outbound($P[1], 'नमस्ते', 'inbox_reply');
    $lvl = WaInbox::feedbackReady();
    echo "     (ai_feedback_log level $lvl)\n";
    $th = WaInbox::thread($P[1]);
    $ids = array_column($th, 'id');
    check('thread() items carry their row id', in_array($botId, $ids, true) && in_array($deskId, $ids, true));
    if ($lvl === 0) {
        check('no table: verdict() refuses politely', WaInbox::verdict($P[1], $botId, 'down', '', $REAL)['ok'] === false);
    } else {
        $r = WaInbox::verdict($P[1], $botId, 'down', 'wrong — call 9876543210', $REAL);
        check('👎 on a bot reply is stored', $r['ok'] === true, $r['why']);
        $fb = Database::fetch('SELECT * FROM ai_feedback_log WHERE owner_key = :k ORDER BY id DESC LIMIT 1', ['k' => hash('sha256', $P[1])]);
        check('…the number only as a sha256 hash', $fb !== null && !str_contains(json_encode($fb), $L[1]));
        check('…with the question it answered and the reply', ($fb['user_text'] ?? '') === 'luggage kati kg?' && ($fb['reply_text'] ?? '') === 'Each passenger may carry 20 kg.' && ($fb['verdict'] ?? '') === 'down');
        check('…the office note redacted', str_contains((string) ($fb['note'] ?? ''), '[number]') && !str_contains((string) ($fb['note'] ?? ''), '9876543210'));
        check('…audited (ai.feedback)', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'ai.feedback' AND entity_id = :e", ['e' => (string) $botId]));
        check('a desk reply cannot be judged as a bot reply', WaInbox::verdict($P[1], $deskId, 'up', '', $REAL)['ok'] === false);
        check('another number\'s reply cannot be judged from this chat', WaInbox::verdict($P[2], $botId, 'up', '', $REAL)['ok'] === false);
        check('only up / down', WaInbox::verdict($P[1], $botId, 'meh', '', $REAL)['ok'] === false);
        if ($lvl === 2) {
            WaInbox::verdict($P[1], $botId, 'up', '', $REAL);
            check('a second vote by the same person replaces the first', (int) Database::scalar('SELECT COUNT(*) FROM ai_feedback_log WHERE msg_id = :m', ['m' => $botId], 0) === 1
                && WaInbox::verdicts([$botId, $deskId], $REAL) === [$botId => 'up']);
        } else {
            check('live-shaped table (no msg_id): verdicts() is empty, never an error', WaInbox::verdicts([$botId], $REAL) === []);
        }
    }

    /* ---------------------------------------------------------------- order */
    $same = date('Y-m-d H:i:s');
    $outbound($P[6], 'answer', 'bot_reply', 'sent', $same);
    $inbound($P[6], 'question', $same);
    $t6 = array_values(array_filter(WaInbox::thread($P[6]), static fn(array $m): bool => $m['at'] === $same));
    check('same second: the question shows before the answer', count($t6) === 2 && $t6[0]['dir'] === 'in' && $t6[1]['dir'] === 'out');
} catch (Throwable $e) {
    check('no exception: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    $cleanup();
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
