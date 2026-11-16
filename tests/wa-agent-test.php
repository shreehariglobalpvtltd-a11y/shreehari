<?php
/**
 * =====================================================================
 *  wa-agent-test.php — the WhatsApp assistant's HANDS (20 Sep 2026).
 *
 *  includes/aiagent.php decides what to say; this suite does not test
 *  that (it would need the model, a network and a bill). It tests the
 *  only part that can hurt anyone: includes/aitools.php — the buttons
 *  the model is allowed to press, and the four gates in front of them.
 *
 *    • ROLE      a number is a customer, a counter agent or the office
 *                according to the admins table, never according to the
 *                message; the catalogue shrinks to match
 *    • SWITCH    no issue_ticket while wa_agent_sell is off, no
 *                office_confirm while wa_agent_admin_write is off, and a
 *                tool that is off cannot be reached by naming it anyway
 *    • STAGING   a fare quoted and a ticket sold in the SAME message is
 *                refused; quote in one message, "ho" in the next, sold —
 *                and the ticket is on the sender's own number
 *    • OWNERSHIP another number's PNR yields a status and nothing else;
 *                it cannot be renamed, resent or cancelled
 *    • REWRITE   a wrong name is corrected and the ticket re-minted,
 *                twice per booking and never after departure
 *    • CANCEL    refund_quote first; a WhatsApp passenger's yes only files
 *                a REQUEST to the office (P6-2d, wa_ai_cancel_mode 'request');
 *                in 'self' mode the cancel itself, seats released
 *    • AUDIT     every call — allowed or refused — is in ai_agent_calls
 *
 *  Creates real bookings on throwaway dates and cleans up after itself.
 *      php -c .claude/php-dev.ini tests/wa-agent-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/waagentroute.php';

const WA_CUST  = '9100006001';   // the passenger writing to us
const WA_OTHER = '9100006002';   // somebody else entirely
const WA_AGENT = '9100006003';   // our counter agent's own mobile
const WA_BOSS  = '9100006004';   // the office
const WA_LIKE  = '910000600';    // cleanup prefix

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== WhatsApp assistant — the tools, the gates, the audit ===\n\n";

/* ---- switches this suite drives; restored exactly as found ----------- */
$PINNED = ['wa_agent_on', 'wa_agent_sell', 'wa_agent_oneshot', 'wa_agent_rewrite', 'wa_agent_admin_write',
           'daily_service_on', 'allow_cod', 'quick_ticket_customer_on', 'quick_ticket_customer_boarding',
           'quick_ticket_max_open', 'whatsapp_driver', 'whatsapp_notify_customer', 'whatsapp_notify_admin',
           'wa_ai_cancel_mode'];
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

Settings::set('wa_agent_on', true, 'bool', 'ai', false);
Settings::set('wa_agent_sell', false, 'bool', 'ai', false);          // switched on further down, on purpose
Settings::set('wa_agent_oneshot', false, 'bool', 'ai', false);
Settings::set('wa_agent_rewrite', true, 'bool', 'ai', false);
Settings::set('wa_agent_admin_write', false, 'bool', 'ai', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_customer_boarding', '', 'string', 'booking', false);
Settings::set('quick_ticket_max_open', 6, 'int', 'booking', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);   // journal only, no network
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::flush();

/* ---- a route to sell on ---------------------------------------------- */
$route = null;
foreach (QuickTicket::routes() as $r) {
    if ($r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $route = $r; break; }
}
if ($route === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; $restoreSettings(); exit(0); }

$today  = todayISO();
$window = bookingWindow();
$D1 = addDaysISO($today, 26);
$D2 = addDaysISO($today, 27);
if ($D2 > (string) $window['to']) {
    echo "  SKIP  the public booking window is shorter than 27 days (" . $window['to'] . ")\n";
    $restoreSettings(); exit(0);
}
$dates = [$D1, $D2];

/* ---- staff fixtures: one counter agent, one office number ------------ */
$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', [
            'username' => $username, 'password_hash' => password_hash('Wa@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name], 'id = :i', ['i' => $id]);
    return $id;
};
$agentId = $mkStaff('wa-agent', 'WA Test Agent', 'agent', WA_AGENT);
$bossId  = $mkStaff('wa-boss',  'WA Test Boss',  'superadmin', WA_BOSS);

$cleanup = static function () use ($dates): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WA_LIKE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('ai_agent_calls', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        // P6-2d: the cancel REQUEST's own rows (audit row, office bell + its dedupe claim, audit log)
        try { Database::delete('ticket_edit_audit', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        try { Database::delete('automation_log', 'dedupe_key = :k', ['k' => 'alert:wa_cancel:' . $bid]); } catch (Throwable $e) {}
        try { Database::delete('notifications', "audience = 'admin' AND title = :t", ['t' => 'Cancel request · ' . $r['pnr']]); } catch (Throwable $e) {}
        try { Database::delete('audit_logs', "action = 'booking.whatsapp_cancel_request' AND entity_id = :p", ['p' => $r['pnr']]); } catch (Throwable $e) {}
        try { Database::delete('rate_limits', "bucket = 'wa_ai_cancel_req' AND identifier = :i", ['i' => (string) $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('ai_agent_calls', "phone LIKE '" . WA_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn') AND kkey LIKE '" . WA_LIKE . "%'", []); } catch (Throwable $e) {}
    foreach ($dates as $d) {
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $d]) as $s) {
            $sid = (int) $s['id'];
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => $sid]);
            try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
            try { Database::delete('booking_seats', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $d]);
        Database::delete('schedules', 'travel_date = :d', ['d' => $d]);
    }
    try { Database::pdo()->exec('DELETE FROM rate_limits'); } catch (Throwable $e) {}
};
$cleanup();

/** A context as the webhook would build it, at a given message number. */
$ctxFor = static function (string $phone, int $turn): array {
    $c = AiTools::whoIs($phone);
    $c['turn'] = $turn;
    $c['channel'] = 'whatsapp';
    return $c;
};
$names = static fn(array $tools): array => array_column($tools, 'name');

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

try {
    /* ================================================================
     *  1. Who is writing — decided by the number, never by the message
     * ================================================================ */
    echo "== who is writing ==\n";
    $cust  = $ctxFor(WA_CUST, 1);
    $agent = $ctxFor(WA_AGENT, 1);
    $boss  = $ctxFor(WA_BOSS, 1);

    check('an unknown number is a customer', $cust['role'] === 'customer' && $cust['adminId'] === 0, $cust['role']);
    check('a counter agent number is staff', $agent['role'] === 'staff' && $agent['adminId'] === $agentId, $agent['role']);
    check('  and is scoped to its own book', (int) $agent['scopeAdminId'] === $agentId, (string) $agent['scopeAdminId']);
    check('an office number is admin', $boss['role'] === 'admin' && $boss['adminId'] === $bossId, $boss['role']);
    check('  and is not scoped', $boss['scopeAdminId'] === null);
    check('a +91-prefixed form of the same number is the same person',
        AiTools::whoIs('+91' . WA_AGENT)['adminId'] === $agentId);

    /* ================================================================
     *  2. The catalogue each role is offered
     * ================================================================ */
    echo "\n== the catalogue ==\n";
    $cTools = $names(AiTools::catalogue($cust));
    $aTools = $names(AiTools::catalogue($agent));
    $bTools = $names(AiTools::catalogue($boss));

    check('a passenger gets the passenger tools', in_array('plan_ticket', $cTools, true) && in_array('find_ticket', $cTools, true));
    check('  and NOT the office tools', !in_array('office_day', $cTools, true) && !in_array('office_search', $cTools, true));
    check('  and NOT another seller\'s book', !in_array('agent_day', $cTools, true) && !in_array('staff_sell', $cTools, true));
    check('an agent gets their own day and passengers', in_array('agent_day', $aTools, true) && in_array('agent_passengers', $aTools, true));
    check('  but not the whole company', !in_array('office_day', $aTools, true));
    check('the office gets the company tools', in_array('office_day', $bTools, true) && in_array('office_alerts', $bTools, true));

    check('issue_ticket is absent while wa_agent_sell is OFF', !in_array('issue_ticket', $cTools, true));
    check('office_confirm is absent while wa_agent_admin_write is OFF', !in_array('office_confirm', $bTools, true));

    /* Naming a tool that is switched off must not reach it either. */
    $sneak = AiTools::run('issue_ticket', ['name' => 'Ram', 'confirm' => true], $cust);
    check('  naming a switched-off tool is refused', $sneak['ok'] === false, $sneak['say']);
    $sneak2 = AiTools::run('office_day', [], $cust);
    check('a passenger cannot reach an office tool by name', $sneak2['ok'] === false && $sneak2['data'] === [], $sneak2['say']);
    check('  and the refusal is in the audit trail',
        Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'office_day' AND ok = 0", ['p' => WA_CUST]));

    Settings::set('wa_agent_sell', true, 'bool', 'ai', false);
    Settings::flush();
    check('issue_ticket appears once selling is switched ON',
        in_array('issue_ticket', $names(AiTools::catalogue($cust)), true));
    check('  and staff_sell only for staff',
        in_array('staff_sell', $names(AiTools::catalogue($agent)), true)
        && !in_array('staff_sell', $names(AiTools::catalogue($cust)), true));

    /* ================================================================
     *  3. The two-message sale
     * ================================================================ */
    echo "\n== quote in one message, ticket in the next ==\n";

    $quote = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D1, 'direction' => 'toNepal'], $ctxFor(WA_CUST, 2));
    check('plan_ticket quotes a real bus', $quote['ok'] === true && (string) $quote['data']['date'] === $D1, (string) ($quote['data']['dateLabel'] ?? ''));
    check('  with a total the passenger can be read', (float) $quote['data']['total'] > 0, (string) $quote['data']['totalLabel']);
    check('  and nothing is booked yet',
        !Database::exists("SELECT 1 FROM bookings WHERE contact_phone = :p", ['p' => WA_CUST]));

    $sameTurn = AiTools::run('issue_ticket', ['name' => 'Sita Thapa', 'confirm' => true], $ctxFor(WA_CUST, 2));
    check('selling in the SAME message is refused (the fare was never read)', $sameTurn['ok'] === false, $sameTurn['say']);
    check('  and still nothing is booked',
        !Database::exists("SELECT 1 FROM bookings WHERE contact_phone = :p", ['p' => WA_CUST]));

    $noConfirm = AiTools::run('issue_ticket', ['name' => 'Sita Thapa'], $ctxFor(WA_CUST, 3));
    check('selling without the passenger\'s yes is refused', $noConfirm['ok'] === false, $noConfirm['say']);

    // The quote is still parked; the next message says "ho".
    $sold = AiTools::run('issue_ticket', ['name' => 'Sita Thapa', 'gender' => 'Female', 'confirm' => true], $ctxFor(WA_CUST, 3));
    check('"ho" in the next message issues the ticket', $sold['ok'] === true, (string) ($sold['data']['pnr'] ?? $sold['say']));

    $pnr = (string) ($sold['data']['pnr'] ?? '');
    $booking = $pnr !== '' ? BookingService::detail($pnr) : null;
    check('  a real confirmed booking exists', $booking !== null && (string) $booking['status'] === 'confirmed', (string) ($booking['status'] ?? '-'));
    check('  on the sender\'s OWN number', $booking !== null && normalisePhone((string) $booking['contact_phone']) === WA_CUST, (string) ($booking['contact_phone'] ?? ''));
    check('  on the quoted date', $booking !== null && (string) ($booking['legs'][0]['travel_date'] ?? '') === $D1);
    check('  for the quoted total', $booking !== null && abs((float) $booking['total_amount'] - (float) $quote['data']['total']) < 0.01,
        (string) ($booking['total_amount'] ?? ''));
    check('  the ticket picture rides back with the reply', is_string($sold['media']) && str_contains((string) $sold['media'], $pnr));
    check('  and the sale is in the audit trail with its booking',
        Database::exists("SELECT 1 FROM ai_agent_calls WHERE tool = 'issue_ticket' AND ok = 1 AND booking_id = :b", ['b' => (int) $booking['id']]));

    $reuse = AiTools::run('issue_ticket', ['name' => 'Sita Thapa', 'confirm' => true], $ctxFor(WA_CUST, 4));
    check('the same quote cannot be spent twice', $reuse['ok'] === false, $reuse['say']);
    check('  so the number still has exactly one booking',
        (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = :p", ['p' => WA_CUST], 0) === 1);

    /* ================================================================
     *  4. Whose booking is it
     * ================================================================ */
    echo "\n== ownership ==\n";
    $mine = AiTools::run('find_ticket', ['pnr' => $pnr], $ctxFor(WA_CUST, 5));
    check('the owner reads the whole ticket', $mine['ok'] === true && isset($mine['data']['passengers']), (string) ($mine['data']['seats'][0] ?? ''));

    $theirs = AiTools::run('find_ticket', ['pnr' => $pnr], $ctxFor(WA_OTHER, 1));
    check('another number gets the status only', $theirs['ok'] === true && !empty($theirs['data']['restricted']));
    check('  and no name, seat or amount leaks',
        !isset($theirs['data']['passengers']) && !isset($theirs['data']['seats']) && !isset($theirs['data']['total']));

    $list = AiTools::run('my_tickets', ['phone' => WA_CUST], $ctxFor(WA_OTHER, 2));
    check('a passenger cannot list somebody else\'s bookings by passing their number',
        $list['ok'] === true && ($list['data']['bookings'] ?? []) === []);

    $officeList = AiTools::run('my_tickets', ['phone' => WA_CUST], $ctxFor(WA_BOSS, 2));
    check('  the office may', $officeList['ok'] === true && count($officeList['data']['bookings'] ?? []) === 1);

    $steal = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => 'Hari Bahadur'], $ctxFor(WA_OTHER, 3));
    check('another number cannot rename the ticket', $steal['ok'] === false, $steal['say']);
    $stealCancel = AiTools::run('refund_quote', ['pnr' => $pnr], $ctxFor(WA_OTHER, 4));
    check('  nor get a refund quote for it', $stealCancel['ok'] === false, $stealCancel['say']);

    /* ================================================================
     *  5. Rewriting a ticket — the name, and only the name
     * ================================================================ */
    echo "\n== correcting the name ==\n";
    $bid    = (int) $booking['id'];
    $before = Database::fetch('SELECT id, full_name, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid]);
    $tktBefore = Database::fetch('SELECT reissue_count, qr_hash FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid]);
    $renameT0  = time();

    $bad = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => 'Sita 99'], $ctxFor(WA_CUST, 6));
    check('a name with digits in it is refused', $bad['ok'] === false, $bad['say']);

    $fix = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => 'Sita Kumari Thapa'], $ctxFor(WA_CUST, 7));
    check('the owner corrects the name', $fix['ok'] === true, (string) ($fix['data']['now'] ?? $fix['say']));
    $after = Database::fetch('SELECT full_name, seat_no FROM booking_passengers WHERE id = :i', ['i' => (int) $before['id']]);
    check('  the register holds the new name', (string) $after['full_name'] === 'Sita Kumari Thapa', (string) $after['full_name']);
    check('  the seat did not move', (string) $after['seat_no'] === (string) $before['seat_no'], (string) $after['seat_no']);
    $fresh = BookingService::detail($pnr);
    check('  the date and the fare did not move',
        (string) ($fresh['legs'][0]['travel_date'] ?? '') === $D1
        && abs((float) $fresh['total_amount'] - (float) $booking['total_amount']) < 0.01);
    /* 1 Oct 2026: the rename goes through TicketSync, which RE-ISSUES the ticket
       (REV n+1, old PNG/PDF deleted) and redraws the PNG on the spot - instead
       of only dropping png_path and leaving the redraw to the next open. The
       guard is the same: no picture with the old name is served after the
       correction. (The QR carries no name since 8 Oct, so its hash may stay.) */
    $tktAfter = Database::fetch('SELECT reissue_count, qr_hash, COALESCE(png_path, \'\') AS png FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid]);
    $pngFile  = TICKET_PATH . '/ticket_' . $pnr . '.png';
    check('  the ticket was re-issued (REV +1) and its picture redrawn after the rename, so no stale one is served',
        $tktBefore !== null && $tktAfter !== null
        && (int) $tktAfter['reissue_count'] === (int) $tktBefore['reissue_count'] + 1
        && ((string) $tktAfter['png'] === '' || (is_file($pngFile) && filemtime($pngFile) >= $renameT0 - 1)),
        $tktBefore === null ? 'no ticket row before the rename' : ('REV ' . (int) $tktBefore['reissue_count'] . ' -> ' . (int) ($tktAfter['reissue_count'] ?? -1)));
    check('  and the correction is in the audit log',
        Database::exists("SELECT 1 FROM audit_logs WHERE action = 'booking.rename_whatsapp' AND entity_id = :p", ['p' => $pnr]));

    $fix2 = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => 'Sita K Thapa'], $ctxFor(WA_CUST, 8));
    check('a second correction is allowed', $fix2['ok'] === true);
    $fix3 = AiTools::run('rename_passenger', ['pnr' => $pnr, 'new_name' => 'Gita Thapa'], $ctxFor(WA_CUST, 9));
    check('  a third is not — the ticket stops being transferable', $fix3['ok'] === false, $fix3['say']);

    Settings::set('wa_agent_rewrite', false, 'bool', 'ai', false);
    Settings::flush();
    check('with the rewrite switch OFF the tool is not even offered',
        !in_array('rename_passenger', $names(AiTools::catalogue($cust)), true));
    Settings::set('wa_agent_rewrite', true, 'bool', 'ai', false);
    Settings::flush();

    /* ================================================================
     *  6. Cancelling — the figure first; a passenger only REQUESTS
     *     (P6-2d, wa_ai_cancel_mode 'request' = the default), 'self'
     *     keeps the old self-cancel
     * ================================================================ */
    echo "\n== cancelling ==\n";
    $officeSent = [];
    WaAgentRoute::$transport = static function (string $to, string $text, ?string $image, ?array $ui) use (&$officeSent): array {
        $officeSent[] = $text;                                          // never the network
        return ['success' => true, 'message_id' => 'wamid.wa-agent-test'];
    };
    Settings::set('wa_ai_cancel_mode', 'request', 'string', 'whatsapp', false);
    Settings::flush();
    $descOf = static function (array $tools, string $name): string {
        foreach ($tools as $t) { if (($t['name'] ?? '') === $name) { return (string) ($t['description'] ?? ''); } }
        return '';
    };
    check('P6-2d: a passenger is offered cancel_ticket as a REQUEST to the office',
        str_contains($descOf(AiTools::catalogue($ctxFor(WA_CUST, 10)), 'cancel_ticket'), 'CANCEL REQUEST'));
    check('  an agent and the office keep the real cancel',
        str_starts_with($descOf(AiTools::catalogue($agent), 'cancel_ticket'), 'CANCEL a booking')
        && str_starts_with($descOf(AiTools::catalogue($boss), 'cancel_ticket'), 'CANCEL a booking'));
    $asReq = new ReflectionMethod('AiTools', 'cancelAsRequest');
    check('  request mode is for a WhatsApp passenger only (not the website, Messenger, staff or office)',
        $asReq->invoke(null, ['role' => 'customer', 'channel' => 'whatsapp']) === true
        && $asReq->invoke(null, ['role' => 'customer']) === true
        && $asReq->invoke(null, ['role' => 'customer', 'channel' => 'web', 'verified' => true]) === false
        && $asReq->invoke(null, ['role' => 'customer', 'channel' => 'web', 'via' => 'facebook']) === false
        && $asReq->invoke(null, ['role' => 'staff', 'channel' => 'whatsapp']) === false
        && $asReq->invoke(null, ['role' => 'admin', 'channel' => 'whatsapp']) === false
        && $asReq->invoke(null, ['role' => 'member', 'channel' => 'whatsapp']) === false);

    $blind = AiTools::run('cancel_ticket', ['pnr' => $pnr, 'confirm' => true], $ctxFor(WA_CUST, 10));
    check('cancelling before the refund was quoted is refused', $blind['ok'] === false && empty($blind['data']['requested']), $blind['say']);
    check('  the booking is still alive',
        (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bid], '') === 'confirmed');

    $rq = AiTools::run('refund_quote', ['pnr' => $pnr], $ctxFor(WA_CUST, 11));
    check('refund_quote states the figure', $rq['ok'] === true && isset($rq['data']['refund']), (string) ($rq['data']['refundLabel'] ?? ''));
    check('  and tells the model the yes only files a REQUEST', str_contains((string) $rq['say'], 'REQUEST'), (string) $rq['say']);

    $seatsBefore = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => $bid], 0);
    $req = AiTools::run('cancel_ticket', ['pnr' => $pnr, 'reason' => 'Plan badliyo', 'confirm' => true], $ctxFor(WA_CUST, 12));
    check('P6-2d: the yes files a cancel REQUEST', !empty($req['data']['requested']) && empty($req['data']['repeat']), (string) $req['say']);
    check('  ok is false, so no caller can print "cancelled"', $req['ok'] === false);
    check('  the passenger is told the office confirms and settles any refund by hand',
        str_contains((string) $req['say'], 'अनुरोध') && str_contains((string) $req['say'], 'by hand')
        && str_contains((string) $req['say'], (string) $rq['data']['refundLabel']), (string) $req['say']);
    check('  BookingService::cancel() was NOT called: still confirmed, refund untouched',
        (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bid], '') === 'confirmed'
        && (string) Database::scalar('SELECT refund_status FROM bookings WHERE id = :b', ['b' => $bid], '') !== 'pending');
    check('  every berth is still held',
        (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => $bid], 0) === $seatsBefore);
    $ted = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request' ORDER BY id DESC LIMIT 1", ['b' => $bid]);
    check('  a ticket_edit_audit row: requested, not code-verified, reason + quoted figure',
        $ted !== null && (string) $ted['outcome'] === 'requested' && (int) $ted['otp_verified'] === 0
        && str_contains((string) $ted['detail'], 'Plan badliyo') && str_contains((string) $ted['detail'], (string) $rq['data']['refundLabel']),
        (string) ($ted['detail'] ?? 'no row'));
    $bell = Database::fetch("SELECT body FROM notifications WHERE audience = 'admin' AND title = :t ORDER BY id DESC LIMIT 1", ['t' => 'Cancel request · ' . $pnr]);
    check('  the office bell carries the reason and the quoted refund',
        $bell !== null && str_contains((string) $bell['body'], 'Plan badliyo') && str_contains((string) $bell['body'], 'Quoted refund'), (string) ($bell['body'] ?? 'no bell'));
    check('  and the audit log has it', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'booking.whatsapp_cancel_request' AND entity_id = :p", ['p' => $pnr]));

    $again = AiTools::run('cancel_ticket', ['pnr' => $pnr, 'reason' => 'Plan badliyo', 'confirm' => true], $ctxFor(WA_CUST, 13));
    check('  the same quote cannot file twice', empty($again['data']['requested']), (string) $again['say']);
    AiTools::run('refund_quote', ['pnr' => $pnr], $ctxFor(WA_CUST, 13));
    $officeBefore = count($officeSent);
    $rep = AiTools::run('cancel_ticket', ['pnr' => $pnr, 'confirm' => true], $ctxFor(WA_CUST, 14));
    check('  a second request within 6 h is noted, not re-sent to the office',
        !empty($rep['data']['requested']) && !empty($rep['data']['repeat']) && count($officeSent) === $officeBefore
        && (int) Database::scalar("SELECT COUNT(*) FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request'", ['b' => $bid], 0) === 1);
    WaAgentRoute::$transport = null;

    echo "  -- wa_ai_cancel_mode = self (the old self-cancel) --\n";
    Settings::set('wa_ai_cancel_mode', 'self', 'string', 'whatsapp', false);
    Settings::flush();
    $rq = AiTools::run('refund_quote', ['pnr' => $pnr], $ctxFor(WA_CUST, 15));
    check('refund_quote states the figure', $rq['ok'] === true && isset($rq['data']['refund']), (string) ($rq['data']['refundLabel'] ?? ''));
    $cancel = AiTools::run('cancel_ticket', ['pnr' => $pnr, 'reason' => 'Plan badliyo', 'confirm' => true], $ctxFor(WA_CUST, 16));
    check('the next message cancels it', $cancel['ok'] === true, (string) ($cancel['data']['refundLabel'] ?? $cancel['say']));
    check('  the booking is cancelled',
        (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $bid], '') === 'cancelled');
    check('  and the berth went back to the bus',
        $seatsBefore > 0
        && (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => $bid], 0) === 0);

    /* ================================================================
     *  7. An agent's own book
     * ================================================================ */
    echo "\n== an agent sees their own book, and only their own ==\n";
    $q2 = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D2, 'direction' => 'toNepal'], $ctxFor(WA_AGENT, 1));
    check('an agent can quote', $q2['ok'] === true, (string) ($q2['data']['totalLabel'] ?? $q2['say']));

    $deskSale = AiTools::run('staff_sell', [
        'name' => 'Bipin Rai', 'phone' => WA_OTHER, 'gender' => 'Male', 'pay' => 'cash', 'confirm' => true,
    ], $ctxFor(WA_AGENT, 2));
    check('  and sell for a passenger on another number', $deskSale['ok'] === true, (string) ($deskSale['data']['pnr'] ?? $deskSale['say']));

    $soldPnr = (string) ($deskSale['data']['pnr'] ?? '');
    $soldRow = $soldPnr !== '' ? Database::fetch('SELECT contact_phone, sold_by_admin_id, source FROM bookings WHERE pnr = :p', ['p' => $soldPnr]) : null;
    check('  the ticket is on the PASSENGER\'s number', $soldRow !== null && normalisePhone((string) $soldRow['contact_phone']) === WA_OTHER);
    check('  the sale is credited to the agent who sold it', $soldRow !== null && (int) $soldRow['sold_by_admin_id'] === $agentId);
    check('  and is filed as an agent sale, not a counter sale', $soldRow !== null && (string) $soldRow['source'] === 'agent', (string) ($soldRow['source'] ?? ''));

    $day = AiTools::run('agent_day', [], $ctxFor(WA_AGENT, 3));
    check('agent_day counts the agent\'s own tickets', $day['ok'] === true && (int) $day['data']['tickets'] >= 1, (string) $day['data']['tickets']);
    check('  and keeps commission and cash apart', isset($day['data']['commissionDue'], $day['data']['cashDue']));

    $pax = AiTools::run('agent_passengers', ['date' => $D2], $ctxFor(WA_AGENT, 4));
    $flat = [];
    foreach (($pax['data']['byPickup'] ?? []) as $stop => $rows) { foreach ($rows as $row) { $flat[] = (string) $row['pnr']; } }
    check('agent_passengers lists the agent\'s own passenger', in_array($soldPnr, $flat, true), implode(',', $flat));

    $custPax = AiTools::run('agent_passengers', ['date' => $D1], $ctxFor(WA_AGENT, 5));
    $flat1 = [];
    foreach (($custPax['data']['byPickup'] ?? []) as $stop => $rows) { foreach ($rows as $row) { $flat1[] = (string) $row['pnr']; } }
    check('  and NOT the ticket the passenger bought for themselves', !in_array($pnr, $flat1, true), implode(',', $flat1));

    /* ================================================================
     *  7b. Named berths — "book A3 and A4" (P8-6b, 16 Nov 2026)
     * ================================================================ */
    echo "\n== named berths through plan_ticket ==\n";
    $l1 = Seats::displayLabel('L1', 'sleeper', 'sharing');
    [$okB, $badB] = AiTools::cleanBerths([strtolower($l1), 'l-4', 'zz9', ' ']);
    check('berths are cleaned: the printed "' . $l1 . '" and the stored "L4" both read; junk is reported, never guessed',
        $okB === ['L1', 'L4'] && $badB === ['ZZ9'], json_encode([$okB, $badB]));
    [$okS, $badS] = AiTools::cleanBerths('L1 and L4');
    check('  a berths STRING is split; a word with no number ("and") is no berth and is not reported',
        $okS === ['L1', 'L4'] && $badS === [], json_encode([$okS, $badS]));
    // review 2: one array item holding two berths is two berths; "A 3" stays one; junk words never eat the cap of 6
    [$okI, $badI] = AiTools::cleanBerths(['L1, L4', 'L 7']);
    check('  one item holding two berths ("L1, L4") is two berths, "L 7" is one — never the non-berth "L1L4"',
        $okI === ['L1', 'L4', 'L7'] && $badI === [], json_encode([$okI, $badI]));
    [$okC, $badC] = AiTools::cleanBerths('L1 and L2 and L3 and L4');
    check('  "and" does not count towards the cap: all four berths are read', $okC === ['L1', 'L2', 'L3', 'L4'] && $badC === [], json_encode([$okC, $badC]));
    check('  plan_ticket offers an optional berths list',
        str_contains($src('includes/aitools.php'), "'berths'    => ['array'"));

    $probe = QuickTicket::plan(['seats' => 1, 'date' => $D2, 'direction' => 'toNepal', 'gender' => 'Male']);
    $bCoach = (string) ($probe['coach'] ?? 'sleeper');
    $bAv    = Seats::availabilityForSchedule((int) $probe['scheduleId'], 'sharing');
    $bTaken = array_map('strval', array_merge($bAv['booked'] ?? [], $bAv['locked'] ?? []));
    $bFree  = array_values(array_diff(array_map('strval', $bAv['available'] ?? []), array_map('strval', $bAv['female'] ?? [])));
    $byUnitB = [];
    foreach ($bFree as $s) { $byUnitB[Seats::unitKey($s)][] = $s; }
    $pair = [];
    foreach (array_reverse($byUnitB, true) as $unit => $seats) {     // from the back: never what the engine picks by itself
        if (count($seats) >= 2 && array_intersect(Seats::unitSeats((string) $unit, $bCoach), $bTaken) === []) {
            $pair = array_slice($seats, 0, 2);
            break;
        }
    }
    if ($pair === []) {
        echo "  SKIP  no empty two-berth cabin left on $D2\n";
    } else {
        $lbls = array_map(static fn(string $s): string => Seats::displayLabel($s, $bCoach, 'sharing'), $pair);
        $qb = AiTools::run('plan_ticket', ['seats' => 2, 'date' => $D2, 'direction' => 'toNepal', 'gender' => 'Male',
            'berths' => [$lbls[0], $lbls[1], 'ZZ9']], $ctxFor(WA_AGENT, 6));
        check('plan_ticket honours the named berths', $qb['ok'] === true && ($qb['data']['berthsHonoured'] ?? []) === $lbls,
            json_encode([$lbls, $qb['data']['berthsHonoured'] ?? null, $qb['data']['seatNumbers'] ?? null]));
        check('  they are the berths quoted', count(array_intersect($lbls, (array) ($qb['data']['seatNumbers'] ?? []))) === 2);
        check('  the read-back names what was honoured', str_contains((string) $qb['say'], 'GOT: ' . implode(', ', $lbls)), (string) $qb['say']);
        check('  a label that is no berth is reported, not guessed', ($qb['data']['berthsUnknown'] ?? []) === ['ZZ9'] && str_contains((string) $qb['say'], 'not a berth'));
        $sb = AiTools::run('staff_sell', [
            'name' => 'Ramesh Rai', 'phone' => WA_OTHER, 'gender' => 'Male', 'pay' => 'cash', 'confirm' => true,
            'names' => ['Ramesh Rai', 'Suresh Rai'],
        ], $ctxFor(WA_AGENT, 7));
        $sbDetail = !empty($sb['data']['pnr']) ? BookingService::detail((string) $sb['data']['pnr']) : null;
        check('  the sale lands on exactly the berths read out', $sbDetail !== null
            && count(array_intersect($pair, array_map('strval', (array) $sbDetail['seats']))) === 2,
            json_encode([$pair, $sbDetail['seats'] ?? ($sb['say'] ?? '')]));
        $qTaken = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D2, 'direction' => 'toNepal', 'gender' => 'Male',
            'berths' => [$lbls[0]]], $ctxFor(WA_AGENT, 8));
        check('a berth already sold is NOT given, and the read-back says so', $qTaken['ok'] === true
            && ($qTaken['data']['berthsNotGiven'] ?? []) === [$lbls[0]] && str_contains((string) $qTaken['say'], 'NOT given'),
            (string) $qTaken['say']);
    }

    /* ================================================================
     *  8. The office
     * ================================================================ */
    echo "\n== the office ==\n";
    $oday = AiTools::run('office_day', ['date' => $today], $ctxFor(WA_BOSS, 3));
    check('office_day answers with live figures', $oday['ok'] === true && isset($oday['data']['ticketsSold'], $oday['data']['revenue']),
        (string) ($oday['data']['revenueLabel'] ?? ''));
    check('  and says how full each departure is', is_array($oday['data']['departures'] ?? null));

    $find = AiTools::run('office_search', ['q' => $soldPnr], $ctxFor(WA_BOSS, 4));
    check('office_search finds a booking by PNR', $find['ok'] === true && count($find['data']['results'] ?? []) >= 1);

    $alerts = AiTools::run('office_alerts', [], $ctxFor(WA_BOSS, 5));
    check('office_alerts answers without blowing up on a missing table', $alerts['ok'] === true && isset($alerts['data']['pendingPayments']));

    $confirmOff = AiTools::run('office_confirm', ['pnr' => $soldPnr, 'confirm' => true], $ctxFor(WA_BOSS, 6));
    check('office_confirm is refused while the write switch is OFF', $confirmOff['ok'] === false, $confirmOff['say']);

    /* ================================================================
     *  9. The wiring
     * ================================================================ */
    echo "\n== the wiring ==\n";
    $wabot = $src('includes/wabot.php');
    check('wabot.php hands a non-PNR message to the assistant', str_contains($wabot, 'AiAgent::handle'));
    check('  and a bare PNR still takes the instant path', str_contains($wabot, '$pnrOnly'));
    check('  a fresh PNR clears the assistant\'s thread', str_contains($wabot, 'AiAgent::forget'));

    /* 16 Nov 2026 (battery Y7): this code line is the recovered live source
       (checkpoint 739f95ec, 26 Oct 2026) and carries only the .sql
       migrations from 24 Oct on; upgrade-2026-09-wa-agent.sql was applied on
       the server long ago and is not in this checkout. When the file IS here
       it is read exactly as before. When it is not, the same two properties
       are checked where they live now: the switch ships OFF because every
       read of wa_agent_on defaults to OFF and no migration in the checkout
       seeds it ON (a missing row = OFF), and the audit table has every column
       the two writers (AiTools, WaFaq) insert. */
    $sql = $src('database/upgrade-2026-09-wa-agent.sql');
    if ($sql !== '') {
        check('the migration seeds the master switch OFF', str_contains($sql, "('wa_agent_on',         '0'"));
        check('  and creates the audit table', str_contains($sql, 'CREATE TABLE IF NOT EXISTS `ai_agent_calls`'));
    } else {
        $reads = []; $seedsOn = [];
        foreach (['includes', 'admin', 'admin/api', 'api', 'whatsapp', 'cron', 'database'] as $dir) {
            foreach (glob($root . '/' . $dir . '/*.{php,sql}', GLOB_BRACE) ?: [] as $f) {
                $code = (string) file_get_contents($f);
                if (!str_contains($code, 'wa_agent_on')) { continue; }
                if (preg_match_all("/Settings::get(?:Bool|Int|String)?\(\s*'wa_agent_on'\s*,\s*([^)]+)\)/", $code, $rm)) {
                    foreach ($rm[1] as $def) { $reads[] = [basename($f), strtolower(trim($def, " '\""))]; }
                }
                if ($dir === 'database' && preg_match("/\(\s*'wa_agent_on'\s*,\s*'(?!0')/", $code)) { $seedsOn[] = basename($f); }
            }
        }
        $onByDefault = array_values(array_filter($reads, static fn(array $r): bool => !in_array($r[1], ['false', '0'], true)));
        check('the master switch ships OFF (every read defaults OFF, no migration seeds it ON; the .sql predates this checkout)',
            $reads !== [] && $onByDefault === [] && $seedsOn === [],
            count($reads) . ' reads' . ($onByDefault !== [] ? '; ON by default in ' . implode(', ', array_map(static fn(array $r): string => $r[0], $onByDefault)) : '')
            . ($seedsOn !== [] ? '; seeded ON by ' . implode(', ', $seedsOn) : ''));
        $have = array_map('strval', pluck(Database::fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_agent_calls'"), 'COLUMN_NAME'));
        $gap  = array_values(array_diff(['created_at', 'phone', 'role', 'channel', 'tool', 'args', 'ok', 'detail', 'booking_id', 'ms'], $have));
        check('  and the audit table holds every column the assistant writes', $have !== [] && $gap === [],
            $have === [] ? 'no ai_agent_calls table' : ($gap === [] ? count($have) . ' columns' : 'missing: ' . implode(', ', $gap)));
    }

    $runAll = $src('tests/run-all.php');
    check('this suite is registered in the battery', str_contains($runAll, 'wa-agent-test.php'));

    $agentSrc = $src('includes/aiagent.php');
    check('the assistant can fall back to Gemini', str_contains($agentSrc, 'generativelanguage.googleapis.com'));
    check('  and never posts a key into a URL', !str_contains($agentSrc, 'key=' ));

} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    WaAgentRoute::$transport = null;
    $cleanup();
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "  \033[32m$PASS passed\033[0m, " . ($FAIL > 0 ? "\033[31m$FAIL failed\033[0m" : "0 failed") . "\n\n";
exit($FAIL > 0 ? 1 : 0);
