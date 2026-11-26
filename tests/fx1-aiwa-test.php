<?php
/**
 * =====================================================================
 *  fx1-aiwa-test.php — S Hari AI + WhatsApp, the fx1 gap pass (26 Nov 2026).
 *
 *   A cancel / change / refund words from a passenger never become a booking draft: a number with no ticket is told
 *     how (PNR from the booking's mobile, or the office) and that the office settles it by hand; a ticketed passenger
 *     still gets the 6-digit code and only a cancel REQUEST is filed (booking stays confirmed); a seat change points
 *     to the ticket page; a rule question still gets the refund slabs; switch wa_change_words_on = 0 = the old paths
 *   B "talk to a human" in Nepali / Roman Nepali / Hindi / English opens ONE call-back request (Help Desk, category
 *     callback) and gives the office number; a statement or a staff number never does; switch wa_human_ask_on
 *   C the owner's questions ("kun agent le kati kate?", "pending payment?", "bus 3 kaha cha?", "revenue",
 *     "sanjh ko report") are answered before the staff desk; an agent and a passenger never get company figures;
 *     a waiting sale quote keeps its "ho"; a sale line still reaches the desk; switch wa_office_ask_on;
 *     with wa_ops_stepup_on an unverified staff number gets the one-time link, never a figure (the desk's own gate)
 *   D the assistant never cancels for a passenger on any channel (website, Messenger): a REQUEST only; the office
 *     and agents keep the real tool; switch ai_cancel_request_all_on; wa_ai_cancel_mode is a super-admin's setting
 *   E confirming a payment from chat: super-admin only + a fresh step-up; switch wa_confirm_super_only_on
 *   F small ones: "hi" shows the menu with wa_faq_on off; date change as an office request (wa_date_change_mode);
 *     a pay-at-boarding booking never gets a pay QR; nothing about parcels anywhere in the bot
 *
 *    php -c .claude/php-dev.ini tests/fx1-aiwa-test.php
 *  Nothing leaves this machine (Meta constants empty, transports captured, AI keys blank, settings in memory only).
 *  Its own admins / bookings (usernames fx1aiwa_*, phones 70000955xx / 98123955xx, PNR SHG-2026-9955x) are removed
 *  at the end; no shared row is touched.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

foreach (['META_ACCESS_TOKEN' => '', 'META_PHONE_NUMBER_ID' => '', 'META_APP_SECRET' => '', 'META_WABA_ID' => '',
          'WHATSAPP_WEBHOOK_TOKEN' => '', 'META_API_BASE' => 'http://127.0.0.1:9', 'META_API_VERSION' => 'v0'] as $k => $v) {
    define($k, $v);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'booking', 'notify', 'agentpro', 'agentpro-wa', 'agentwallet', 'ticketbot', 'quickticket',
          'wabooking', 'wabot', 'waroute', 'waagentroute', 'aitools', 'aiagent', 'aiverify', 'aiops', 'wadirectory', 'walookup', 'waanswer',
          'waedit', 'walocal', 'waorder', 'waoffice', 'helpdesk', 'ticketrequests', 'ticketlink', 'officereport'] as $f) {
    require_once INCLUDE_PATH . '/' . $f . '.php';
}

set_exception_handler(static function (Throwable $e): void {
    echo "\n  UNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? ' — ' . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 600)) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }

$SENT = [];
WaRoute::$transport = static function (string $to, string $text, ?string $img = null) use (&$SENT): array {
    $SENT[] = [$to, $text];
    return ['success' => true, 'message_id' => 'wamid.FX1' . count($SENT), 'error' => ''];
};
WaAgentRoute::$transport = static function (string $to, string $text, ?string $img = null, ?array $ui = null) use (&$SENT): array {
    $SENT[] = [$to, $text];
    return ['success' => true, 'message_id' => 'wamid.FX1' . count($SENT), 'error' => ''];
};

/* ---- settings, in memory only (the shared test DB holds other sessions' values: never rely on a stored row) ---- */
$OVR = ['whatsapp_driver' => 'click_to_chat', 'wa_cloud_fallback_on' => '0', 'aisensy_api_key' => '', 'wa_meta_templates_on' => '0',
    'email_enabled' => '0', 'push_enabled' => '0', 'staffmsg_push_on' => '0', 'sms_enabled' => '0', 'sms_send_otp' => '0',
    'wa_agent_on' => '0', 'wa_ai_enabled' => '0', 'ap_ai_on' => '0', 'ai_manager_enabled' => '0', 'ai_manager_whatsapp' => '0',
    'gemini_api_key' => '', 'anthropic_api_key' => '', 'openai_api_key' => '', 'grok_api_key' => '', 'openrouter_api_key' => '', 'cerebras_api_key' => '',
    'ai_local_on' => '0', 'wa_faq_on' => '0', 'wa_local_first' => '1', 'wa_local_brain_on' => '1', 'ap_wa_on' => '1', 'ap_wa_sell' => '1',
    'ap_wa_admin_on' => '1', 'agent_kyc_required' => '0', 'helpdesk_on' => '1', 'helpdesk_ack' => '0', 'helpdesk_office_alert' => '0',
    'ap_learn_fast' => '0', 'wa_ops_stepup_on' => '0', 'wa_ops_handoff_on' => '0',
    'whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'wa_instant_sale' => 'staff', 'wa_short_booking_on' => '1',
    'wa_answer_first_on' => '1', 'wa_directory_on' => '1', 'wa_lookup_on' => '1', 'wa_pnr_last4_on' => '1', 'wa_auto_sender_guard_on' => '1',
    'wa_draft_only_bookings_on' => '1', 'ap_wa_answer_first_on' => '1', 'ap_wa_cloud_fallback_on' => '1', 'ap_wa_honest_fallback_on' => '1',
    'ap_wa_edit_yield_on' => '1', 'ai_kb_on' => '0', 'wa_login_on' => '0', 'wa_order_menu_on' => '1', 'wa_order_staff_on' => '1',
    'wa_office_words_on' => '1', 'wa_customer_words_on' => '1', 'wa_edit_otp_on' => '1', 'wa_ticket_buttons_on' => '0',
    'wa_ai_cancel_mode' => 'request', 'wa_agent_admin_write' => '0', 'wa_agent_rewrite' => '1', 'wa_report_chart_on' => '0',
    'wa_marketing_on' => '0', 'wa_bulk_on' => '0', 'wa_staff_lang' => 'ne', 'ticket_requests_on' => '1', 'parcel_book_on' => '0',
    'admin_whatsapp' => '', 'ceo_whatsapp' => '', 'office_report_numbers' => '',
    // the fx1 switches (their code defaults, stated here so a stored row can never change the test)
    'wa_change_words_on' => '1', 'wa_human_ask_on' => '1', 'wa_office_ask_on' => '1', 'ai_cancel_request_all_on' => '1',
    'wa_confirm_super_only_on' => '1', 'wa_date_change_mode' => 'self', 'wa_welcome_menu_on' => '1'];
function applyOverrides(): void {
    global $OVR;
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    foreach ($OVR as $k => $v) {
        $type = $c[$k]['type'] ?? (in_array($v, ['0', '1'], true) ? 'bool' : 'string');
        $c[$k] = ['value' => $v, 'type' => $type === 'json' ? 'string' : $type, 'public' => false];
    }
    $rp->setValue(null, $c);
}
function setOvr(string $k, string $v): void { global $OVR; $OVR[$k] = $v; applyOverrides(); }
applyOverrides();
/** One message through the whole bot, as the webhook sends it (the sender's 24 h window is open, as after any inbound). */
function wa(string $from, string $text, string $kind = 'text'): array {
    applyOverrides();
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'fx1aiwa') ON DUPLICATE KEY UPDATE kvalue = :v2",
        ['k' => '91' . $from, 'v' => (string) time(), 'v2' => (string) time()]);
    return WaBot::reply('+91' . $from, $text, $kind, []);
}

const OWN = '7000095501';     // superadmin (the owner's number, matched by number only — like live)
const MGR = '7000095502';     // manager
const AG1 = '7000095503';     // agent
const C1  = '9812395501';     // passenger with a confirmed ticket (B1, sold by AG1)
const C2  = '9812395502';     // passenger with a confirmed ticket (B2)
const C3  = '9812395503';     // passenger with a pay-at-boarding booking left pending (B3)
const C4  = '9812395504';     // passenger with a confirmed ticket (B4, the date-change request)
const STR = '9812395509';     // a stranger
$CUST = array_map(static fn(int $i): string => '98123955' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(10, 79));
$ALLP = array_merge([OWN, MGR, AG1, C1, C2, C3, C4, STR], $CUST);

$cleanup = static function () use ($ALLP): void {
    $cust = array_values(array_filter($ALLP, static fn(string $p): bool => str_starts_with($p, '98123955')));
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE pnr LIKE 'SHG-2026-9955%' OR contact_phone IN ('" . implode("','", $cust) . "')
                                  OR sold_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'fx1aiwa\\_%')") as $b) {
        $bid = (int) $b['id'];
        try { Seats::releaseBooking($bid); } catch (Throwable $e) {}
        foreach (Database::fetchAll("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'booking_id' AND TABLE_NAME <> 'bookings'") as $r) {
            try { Database::run('DELETE FROM `' . $r['t'] . '` WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM notifications WHERE audience = 'admin' AND title LIKE :t", ['t' => '%' . (string) $b['pnr'] . '%']); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id = :p", ['p' => (string) $b['pnr']]); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM automation_log WHERE event = 'admin_alert' AND dedupe_key = :k", ['k' => 'alert:wa_cancel:' . $bid]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM rate_limits WHERE bucket IN (:b1, :b2) AND identifier = :i', ['b1' => 'wa_ai_cancel_req', 'b2' => 'wa_tkbtn', 'i' => (string) $bid]); } catch (Throwable $e) {}
        try { Database::delete('bookings', 'id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    foreach ($ALLP as $p) {
        // every table the bot keeps per-number state in — this suite's own numbers only
        foreach (['brain_drafts', 'ai_agent_calls', 'contact_lang', 'wa_habits', 'mk_optout', 'wa_identity_links', 'wa_windows', 'wa_contacts', 'user_profiles'] as $t) {
            try { Database::run('DELETE FROM `' . $t . '` WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        }
        try {
            foreach (Database::fetchAll('SELECT id, ticket_ref FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . $p]) as $t) {
                try { Database::run('DELETE FROM support_messages WHERE ticket_id = :i', ['i' => (int) $t['id']]); } catch (Throwable $e) {}
                try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'support_ticket' AND entity_id = :r", ['r' => (string) $t['ticket_ref']]); } catch (Throwable $e) {}
                // the office bell of a call-back request carries the Help Desk number (the phone in it is masked)
                try { Database::run("DELETE FROM notifications WHERE audience = 'admin' AND title = :t", ['t' => 'Call back · WhatsApp ' . (string) $t['ticket_ref']]); } catch (Throwable $e) {}
                Database::run('DELETE FROM support_tickets WHERE id = :i', ['i' => (int) $t['id']]);
            }
        } catch (Throwable $e) {}
        try { Database::run("DELETE FROM notifications WHERE audience = 'admin' AND body LIKE :p", ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM otp_codes WHERE identifier LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM message_logs WHERE to_number LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM kv_store WHERE kkey LIKE :p', ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM ap_wa_state WHERE phone = :p', ['p' => $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM rate_limits WHERE identifier LIKE :p', ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM automation_log WHERE event = 'admin_alert' AND dedupe_key LIKE :k", ['k' => 'alert:wa\\_human:' . $p . ':%']); } catch (Throwable $e) {}
    }
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'fx1aiwa\\_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ai_tool_log' => 'admin_id',
                  'wa_identity_links' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :i", ['i' => (string) $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::run("DELETE FROM kv_store WHERE kscope = 'wa_pnr_fail' AND kkey LIKE 'p|SHG-2026-9955%'"); } catch (Throwable $e) {}
    // this suite's own log lines (a rate-limit note, a refused request) — matched on its own phone prefixes only
    try { Database::run("DELETE FROM app_logs WHERE context LIKE '%98123955__%' OR context LIKE '%70000955__%' OR context LIKE '%SHG-2026-9955%'"); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

echo "\n=== fx1-aiwa: S Hari AI + WhatsApp gap pass ===\n\n";

/* ---- fixtures ---- */
$mk = static function (string $user, string $name, string $phone, string $role, string $area = 'Surat'): int {
    $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    AgentWallet::saveProfile($id, ['counter_name' => $area, 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
$ownId = $mk('fx1aiwa_owner', 'FX1 Owner Bishwokarma', OWN, 'superadmin');
$mgrId = $mk('fx1aiwa_mgr', 'FX1 Manager Adhikari', MGR, 'manager');
$ag1   = $mk('fx1aiwa_ag1', 'FX1 Zorawar Agentone', AG1, 'agent');
AgentWallet::setAgentCode($ag1, AgentWallet::nextFreeAgentCode('person'));

$sched = Database::fetch("SELECT s.id, s.travel_date, r.from_city, r.to_city FROM schedules s JOIN routes r ON r.id = s.route_id
                           WHERE s.travel_date >= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND r.is_active = 1 AND s.is_blocked = 0
                           ORDER BY s.travel_date, r.sort_order LIMIT 1");
check('a future departure exists for the fixtures', $sched !== null);
$n = 0;
$book = static function (string $phone, int $seller, array $names, string $status = 'confirmed', int $cod = 0) use (&$n, $sched): array {
    $n++;
    $pnr = 'SHG-2026-9955' . $n;
    $id = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => '91', 'status' => $status,
        'total_amount' => 2200 * count($names), 'sold_by_admin_id' => $seller ?: null, 'booked_by_admin_id' => $seller ?: null, 'is_cod' => $cod,
        'confirmed_at' => $status === 'confirmed' ? date('Y-m-d H:i:s') : null, 'created_at' => date('Y-m-d H:i:s')]);
    $leg = (int) Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => (int) $sched['id'], 'leg_type' => 'outbound',
        'travel_date' => (string) $sched['travel_date'], 'boarding_stop' => 'Surat · Kamrej @ 13:00', 'boarding_time' => '13:00:00',
        'fare_per_seat' => 2200, 'seat_count' => count($names), 'leg_total' => 2200 * count($names)]);
    foreach ($names as $i => $nm) {
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => 'PAX-FX1-' . $n . $i, 'seat_no' => 'U' . (30 + $n * 2 + $i),
            'full_name' => $nm, 'gender' => 'Male', 'is_primary' => $i === 0 ? 1 : 0]);
    }
    return ['id' => $id, 'pnr' => $pnr];
};
$B1 = $book(C1, $ag1, ['Fxone Bahadur Thapa']);
$B2 = $book(C2, 0, ['Fxtwo Kumar Shah']);
$B3 = $book(C3, 0, ['Fxthree Cod Rana'], 'pending', 1);
$B4 = $book(C4, 0, ['Fxfour Date Gurung']);
$ci = 0;
$next = static function () use (&$ci, $CUST): string { return $CUST[$ci++]; };
$draftsOf = static fn(string $p): int => (int) Database::scalar('SELECT COUNT(*) FROM brain_drafts WHERE phone LIKE :p', ['p' => '%' . $p], 0);
$statusOf = static fn(array $b): string => (string) Database::scalar('SELECT status FROM bookings WHERE id = :b', ['b' => $b['id']], '');
$auditOf  = static fn(array $b, string $field): int => (int) Database::scalar('SELECT COUNT(*) FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = :f', ['b' => $b['id'], 'f' => $field], 0);
$office   = Settings::officePhone();
$PARCEL   = '/parcel|parsal|courier|पार्सल|कुरियर|कूरियर/iu';

/* ===================================================================== */
section('A cancel / change / refund words are answered, never parked as a new booking');
$helpOk = static fn(string $t) => str_contains($t, 'PNR') && str_contains($t, $office);
foreach ([['ticket cancel garnu cha', 'rn'], ['mero ticket cancel gardinus', 'rn'], ['seat badalnu cha', 'rn'], ['refund chahiyo', 'rn'], ['miti badalnu cha', 'rn'],
          ['मेरो टिकट रद्द गर्नु छ', 'ne'], ['mera ticket cancel kar do', 'hi'], ['I want to cancel my ticket', 'en'], ['date change garnu cha', 'rn']] as [$q, $lg]) {
    $p = $next();
    $r = wa($p, $q);
    $mirror = match ($lg) { 'ne' => 'भेटिएन', 'hi' => 'नहीं मिला', 'en' => 'no live ticket', default => 'bhetiena' };
    check('A no ticket on the number: "' . $q . '" -> how to ask + the office, in the writer\'s language; no draft, no code',
        WaBot::$lastRouter === 'waedit' && $helpOk(txt($r)) && str_contains(txt($r), $mirror) && $draftsOf($p) === 0
        && (int) Database::scalar('SELECT COUNT(*) FROM otp_codes WHERE identifier = :i', ['i' => 'wa:91' . $p], 0) === 0, WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa(STR, $B1['pnr'] . ' cancel gardinus');
check('A somebody else\'s PNR: the same words (the PNR is never confirmed), nothing started',
    WaBot::$lastRouter === 'waedit' && $helpOk(txt($r)) && !str_contains(txt($r), 'Fxone') && !str_contains(txt($r), C1) && $auditOf($B1, 'cancel_request') === 0, txt($r));
$r = wa(C1, 'seat badalnu cha');
check('A own ticket, "seat badalnu cha": the ticket page (request seat change) + the office — no self-service, no draft',
    WaBot::$lastRouter === 'waedit' && str_contains(txt($r), $B1['pnr']) && str_contains(txt($r), 'ticket/view.php?code=') && str_contains(txt($r), $office)
    && $draftsOf(C1) === 0 && (int) Database::scalar('SELECT COUNT(*) FROM ticket_requests WHERE booking_id = :b', ['b' => $B1['id']], 0) === 0, txt($r));
$r = wa(C1, 'seat badalna milcha?');
check('A …asked as a question: the same answer', WaBot::$lastRouter === 'waedit' && str_contains(txt($r), 'ticket/view.php?code='), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(C2, 'refund chahiyo');
check('A own ticket, "refund chahiyo": settled by the office by hand + how to send a cancel request; no code sent',
    WaBot::$lastRouter === 'waedit' && str_contains(txt($r), 'cancel ' . $B2['pnr']) && str_contains(txt($r), $office)
    && (int) Database::scalar('SELECT COUNT(*) FROM otp_codes WHERE identifier = :i', ['i' => 'wa:91' . C2], 0) === 0 && $statusOf($B2) === 'confirmed', txt($r));
$r = wa(C2, 'cancel garda kati firta?');
check('A the RULE question still gets the refund slabs (WaLocal), with their own ticket\'s figure', WaBot::$lastRouter === 'walocal' && str_contains(txt($r), '%') && str_contains(txt($r), $B2['pnr']), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(C2, 'cancel policy');
check('A "cancel policy" (the words the refund answer points to) is the rule too', WaBot::$lastRouter === 'walocal' && str_contains(txt($r), '%'), WaBot::$lastRouter . ' :: ' . txt($r));
$p = $next();
$r = wa($p, 'conductor le 200 rupaya badi liyo, paisa firta chahiyo');
check('A a COMPLAINT with "paisa firta" stays the Help Desk\'s (a complaint ticket, not the canned refund line)',
    WaBot::$lastRouter === 'walocal' && (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . $p], 0) === 1, WaBot::$lastRouter . ' :: ' . txt($r));
WaEdit::$codeGen = static fn(): string => '424242';
$r = wa(C2, 'ticket cancel garnu cha');
check('A own ticket, "ticket cancel garnu cha": the 6-digit code is asked (as before)', WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '🔐') && str_contains(txt($r), $B2['pnr']), txt($r));
$r = wa(C2, '424242');
check('A …the right code files a cancel REQUEST only: booking still confirmed, one ticket_edit_audit row',
    str_contains(txt($r), 'अनुरोध') && $statusOf($B2) === 'confirmed' && $auditOf($B2, 'cancel_request') === 1
    && (string) Database::scalar("SELECT outcome FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request'", ['b' => $B2['id']], '') === 'requested', txt($r));
$r = wa(C2, 'cancel ' . $B2['pnr']);
check('A "cancel <PNR>" (what the refund answer tells them to write) opens the same request', WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '🔐'), WaBot::$lastRouter . ' :: ' . txt($r));
wa(C2, 'stop');
$p = $next();
$r = wa($p, 'bholi 2 seat chahiyo Surat bata');
check('A a real order is still an order ("bholi 2 seat chahiyo Surat bata")', WaBot::$lastRouter === 'wabooking', WaBot::$lastRouter . ' :: ' . txt($r));
check('A reading the words: an order / a bare "cancel" / a name line are not change talk; cancel, refund, seat + date change are',
    !WaEdit::changeWords('2 seat chahiyo, plan change bhayo') && !WaEdit::changeWords('cancel') && !WaEdit::changeWords('mero naam Ram ho ticket milaunus')
    && !WaEdit::changeWords('2 din pachi ko ticket milau na') && WaEdit::changeWords('ticket cancel garnu cha') && WaEdit::changeWords('refund kaile aaucha')
    && WaEdit::changeWords('seat badalna milcha?') && WaEdit::changeWords('miti badalnu cha'));
check('A intents: seat / refund are read; "seat milau" (asking for a seat) and a counted order are not',
    WaEdit::intentOf('seat badalnu cha', 'seat badalnu cha') === 'seat' && WaEdit::intentOf('mero seat change gardinus', 'mero seat change gardinus') === 'seat'
    && WaEdit::intentOf('bholi 2 seat milaunu paryo', 'bholi 2 seat milaunu paryo') !== 'seat' && WaEdit::intentOf('seat milau na', 'seat milau na') !== 'seat'
    && WaEdit::intentOf('refund chahiyo', 'refund chahiyo') === 'refund' && WaEdit::intentOf('paise wapas chahiye', 'paise wapas chahiye') === 'refund'
    && WaEdit::intentOf('refund kati huncha', 'refund kati huncha?') === '' && WaEdit::intentOf('ticket cancel garnu cha', 'ticket cancel garnu cha') === 'cancel');
check('A "firta / wapas" alone is a RETURN trip, never a refund ("Nepal bata wapas aaune ticket chahiyo" stays an order)',
    WaEdit::intentOf('nepal bata wapas aaune ticket chahiyo', 'Nepal bata wapas aaune ticket chahiyo') === '' && !WaEdit::changeWords('Nepal bata wapas aaune ticket chahiyo')
    && WaEdit::intentOf('firta aaune ticket chahiyo', 'firta aaune ticket chahiyo') === '' && !WaEdit::changeWords('bus wapas kaile aaucha')
    && WaEdit::intentOf('mero paisa firta dinus', 'mero paisa firta dinus') === 'refund' && WaEdit::changeWords('mero paisa firta aayena'));
$p = $next();
wa($p, 'Nepal bata wapas aaune ticket chahiyo');
check('A …through the whole bot it is not answered as a ticket change', WaBot::$lastRouter !== 'waedit', WaBot::$lastRouter);
if (WaBooking::isOpen($p)) { wa($p, 'no'); }
foreach (['2 din pachi ko ticket milau na', 'mero naam Ram ho ticket milaunus'] as $q) {
    $p = $next();
    wa($p, $q);
    check('A "' . $q . '" (no ticket on the number) is NOT taken as a ticket change', WaBot::$lastRouter !== 'waedit', WaBot::$lastRouter);
    wa($p, 'no');
}
// a PNR sent while the date question waits is a lookup
$r = wa(C4, 'miti badalnu cha');
check('A own ticket, "miti badalnu cha": the new date is asked', WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '📅'), txt($r));
$r = wa(C4, $B4['pnr']);
check('A …a PNR sent meanwhile gets the ticket card (it was "date not understood")', WaBot::$lastRouter === 'lookup' && str_contains(txt($r), $B4['pnr']) && !str_contains(txt($r), 'मिति बुझिएन'), WaBot::$lastRouter . ' :: ' . txt($r));
wa(C4, 'stop');
// review fixes: a complaint, a worded order and a return-trip fare question are not ticket-change talk
$r = wa(STR, 'staff le gali garyo, ticket cancel gardinus');
check('A a COMPLAINT that also says "ticket cancel gardinus" (no ticket on the number) still opens the Help Desk complaint',
    WaBot::$lastRouter === 'walocal' && (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . STR], 0) === 1, WaBot::$lastRouter . ' :: ' . txt($r));
foreach (['plan badal gaya, ab 20 tarikh ko jana hai ek seat chahiye surat se', 'bholi ko ek seat chahiyo surat bata, window seat change garera dinu'] as $q) {
    $p = $next();
    $r = wa($p, $q);
    check('A an order with the seat count in WORDS beside a change word stays an order (desk draft): "' . mb_substr($q, 0, 40) . '…"',
        WaBot::$lastRouter !== 'waedit' && WaBot::$lastRouter !== 'unsure' && !WaEdit::changeWords($q) && $draftsOf($p) === 1 && !str_contains(txt($r), 'bhetiena') && !str_contains(txt($r), 'नहीं मिला'),
        WaBot::$lastRouter . ' :: ' . txt($r));
    if (WaBooking::isOpen($p)) { wa($p, 'no'); }
}
check('A …while a change with no order in it is still change talk ("ek" alone, "kar do", a counted cancel)',
    WaEdit::changeWords('mero ek seat badalnu cha') && WaEdit::changeWords('ticket ka date change kar do') && WaEdit::changeWords('seat change kar do ticket ka')
    && WaEdit::changeWords('ek ticket cancel gardinus'));
$p = $next();
foreach (['Nepal bata wapas aune ticket chahiyo paisa kati lagcha', 'wapas aune ticket chahiyo paisa kati', 'refund nachahiyo ticket nai chahiyo'] as $q) {
    $r = wa($p, $q);
    check('A "' . $q . '" is NOT a refund demand (a return ticket / a refund not wanted)',
        WaEdit::intentOf(mb_strtolower($q), $q) === '' && !WaEdit::changeWords($q) && WaBot::$lastRouter !== 'waedit' && !str_contains(txt($r), 'Paisa firta'), WaBot::$lastRouter . ' :: ' . txt($r));
    if (WaBooking::isOpen($p)) { wa($p, 'no'); }
}
check('A …a real refund demand still is one (money beside the back-word; a ticket not wanted + money back)',
    WaEdit::intentOf('ticket chahidaina paisa firta dinus', 'ticket chahidaina, paisa firta dinus') === 'refund' && WaEdit::intentOf('wapas paise chahiye', 'wapas paise chahiye') === 'refund'
    && WaEdit::intentOf('ticket ko paisa firta chahiyo', 'ticket ko paisa firta chahiyo') === 'refund' && WaEdit::changeWords('paisa firta garna kati din lagcha')
    && WaEdit::intentOf('paisa wapas nahi chahiye', 'paisa wapas nahi chahiye') === '' && WaEdit::changeWords('refund kaile aaucha'));
// the belt in WaBot: WaEdit not answering (its table missing) must still not park a draft
$p = $next();
setOvr('wa_change_words_on', '0');
$r = wa($p, 'ticket cancel garnu cha');
check('A switch wa_change_words_on = 0: the old path (a desk draft) — the switch really is the change', WaBot::$lastRouter === 'draft' && $draftsOf($p) === 1, WaBot::$lastRouter . ' :: ' . txt($r));
setOvr('wa_change_words_on', '1');

/* ===================================================================== */
section('B "talk to a human": one call-back request + the office number');
$cbRows = static fn(string $p): int => (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE phone LIKE :p AND category = 'callback'", ['p' => '%' . $p], 0);
$asks = [['manche sanga kura garnu cha', 'darta bhayo'], ['staff sanga kura garna paryo', 'darta bhayo'], ['malai phone garnus', 'darta bhayo'],
    ['मान्छेसँग कुरा गर्नु छ', 'दर्ता भयो'], ['kisi se baat karni hai', 'दर्ज'], ['मुझे कॉल कीजिए', 'दर्ज'],
    ['I want to talk to a person', 'recorded'], ['call me', 'recorded'], ['human', 'recorded']];
$firstHuman = '';
foreach ($asks as [$q, $mark]) {
    $p = $next();
    $firstHuman = $firstHuman !== '' ? $firstHuman : $p;
    $r = wa($p, $q);
    check('B "' . $q . '" -> a call-back request in the Help Desk + the office number, in the writer\'s language',
        WaBot::$lastRouter === 'human' && $cbRows($p) === 1 && str_contains(txt($r), $office) && str_contains(txt($r), $mark) && str_contains(txt($r), 'HD-') && $draftsOf($p) === 0,
        WaBot::$lastRouter . ' :: ' . txt($r));
}
$row = Database::fetch("SELECT * FROM support_tickets WHERE phone LIKE :p AND category = 'callback' ORDER BY id DESC LIMIT 1", ['p' => '%' . $firstHuman]);
check('B the Help Desk row: source chat, open, the passenger\'s own words', $row !== null && (string) $row['source'] === 'chat' && (string) $row['status'] === 'open' && str_contains((string) $row['message'], 'manche sanga kura'), json_encode($row));
$bell = Database::fetch("SELECT title, body FROM notifications WHERE audience = 'admin' AND title = :t ORDER BY id DESC LIMIT 1", ['t' => 'Call back · WhatsApp ' . (string) ($row['ticket_ref'] ?? '?')]);
check('B the office bell rings once, with the number masked', $bell !== null && !str_contains((string) $bell['body'], $firstHuman), json_encode($bell));
$r = wa($firstHuman, 'manche sanga kura garna paryo');
check('B asked again within the hour: no second row, "already with the office"', WaBot::$lastRouter === 'human' && $cbRows($firstHuman) === 1 && str_contains(txt($r), 'pugisakeko') && str_contains(txt($r), $office), txt($r));
foreach (['driver sanga kura bhayo', 'staff sanga kura bhayo', 'agent ko number', 'office ko number', 'call me, bholi 2 seat chahiyo Surat bata', 'bus kaha cha', 'maile office ma phone gareko thiye'] as $q) {
    $p = $next();
    wa($p, $q);
    check('B "' . $q . '" is NOT a request for a person (no Help Desk row)', WaBot::$lastRouter !== 'human' && $cbRows($p) === 0, WaBot::$lastRouter);
    if (WaBooking::isOpen($p)) { wa($p, 'no'); }
}
check('B reading the line: explicit asks only',
    WaAnswer::asksHuman('Manche sanga kura garnu cha.') && WaAnswer::asksHuman('please call me back') && WaAnswer::asksHuman('insaan se baat karwa do')
    && WaAnswer::asksHuman('talk to a real person') && WaAnswer::asksHuman('स्टाफसँग कुरा गर्नु पर्‍यो') && WaAnswer::asksHuman('phone garidinus na')
    && !WaAnswer::asksHuman('talk to agent') && !WaAnswer::asksHuman('customer care') && !WaAnswer::asksHuman('ticket') && !WaAnswer::asksHuman('hi')
    && !WaAnswer::asksHuman('driver lai phone gareko thiye') && !WaAnswer::asksHuman('kura garna 2 seat chahiyo') && !WaAnswer::asksHuman('ho')
    && !WaAnswer::asksHuman("don't call me") && !WaAnswer::asksHuman('malai phone nagarnus') && !WaAnswer::asksHuman('driver sanga kura garnu cha')
    && !WaAnswer::asksHuman('phone number dinus') && !WaAnswer::asksHuman('customer care number'));
check('B review fix: "no need to phone", a name ("they call me Ram") and a past call are not asks; the plain asks still are',
    !WaAnswer::asksHuman('malai phone garnu pardaina') && !WaAnswer::asksHuman('they call me Ram') && !WaAnswer::asksHuman('mujhe call karke bola tha ki bus 5 baje aayegi')
    && !WaAnswer::asksHuman('did you call me?') && !WaAnswer::asksHuman('malai phone garnu chahidaina') && !WaAnswer::asksHuman('mujhe phone karne ki zarurat nahi')
    && !WaAnswer::asksHuman('मलाई फोन गर्नु पर्दैन') && !WaAnswer::asksHuman('staff se baat karke ticket book hua kya')
    && WaAnswer::asksHuman('call me') && WaAnswer::asksHuman('ok please call me') && WaAnswer::asksHuman('can you call me back') && WaAnswer::asksHuman('I want someone to call me')
    && WaAnswer::asksHuman('bhai please call me') && WaAnswer::asksHuman('I need you to call me') && WaAnswer::asksHuman('mujhe kisi se baat karni thi')
    && WaAnswer::asksHuman('malai phone garnus') && WaAnswer::asksHuman('mujhe call karo'));
wa($p, 'malai phone garnu pardaina');     // the last number of the loop above: it has no call-back request
check('B …and through the whole bot "malai phone garnu pardaina" opens no call-back request', WaBot::$lastRouter !== 'human' && $cbRows($p) === 0, WaBot::$lastRouter);
if (WaBooking::isOpen($p)) { wa($p, 'no'); }
$r = wa(AG1, 'human');
check('B a staff number never opens a call-back request (the desk answers)', WaBot::$lastRouter === 'apwa' && $cbRows(AG1) === 0, WaBot::$lastRouter);
$p = $next();
wa($p, 'bholi 2 seat Surat bata');
$wasOpen = WaBooking::isOpen($p);
$r = wa($p, 'call me');
check('B in the middle of a booking: answered, the booking keeps waiting (said under the answer)',
    $wasOpen && WaBot::$lastRouter === 'human' && $cbRows($p) === 1 && WaBooking::isOpen($p) && str_contains(txt($r), trim(WaBooking::waitingNote($p))), WaBot::$lastRouter . ' :: ' . txt($r));
wa($p, 'no');
$p = $next();
setOvr('helpdesk_on', '0');
$r = wa($p, 'manche sanga kura garnu cha');
check('B Help Desk off: the office number only — never "recorded"', WaBot::$lastRouter === 'human' && $cbRows($p) === 0 && str_contains(txt($r), $office) && !str_contains(txt($r), 'darta') && !str_contains(txt($r), 'HD-'), txt($r));
setOvr('helpdesk_on', '1');
$p = $next();
setOvr('wa_human_ask_on', '0');
wa($p, 'manche sanga kura garnu cha');
check('B switch wa_human_ask_on = 0: the old path, no Help Desk row', WaBot::$lastRouter !== 'human' && $cbRows($p) === 0, WaBot::$lastRouter);
setOvr('wa_human_ask_on', '1');
$p = $next();
setOvr('wa_answer_first_on', '0');
wa($p, 'call me');
check('B it does not depend on wa_answer_first_on', WaBot::$lastRouter === 'human' && $cbRows($p) === 1, WaBot::$lastRouter);
setOvr('wa_answer_first_on', '1');

/* ===================================================================== */
section('C the owner\'s questions are answered before the staff desk');
// three more sales for the fixture agent today, so the board shows it whatever other suites sold
foreach ([70, 71, 72] as $i) { $book('98123955' . $i, $ag1, ['Fxboard Pax ' . $i]); }
foreach ([['kun agent le kati kate?', 'Top sellers'], ['kaun agent ne kitne ticket kaate', 'Top sellers'], ['which agent sold how many tickets', 'Top sellers'],
          ['agents', 'Top sellers'], ['top agents', 'Top sellers'], ['कुन एजेन्टले कति टिकट काटे', 'Top sellers']] as [$q, $mark]) {
    $r = wa(OWN, $q);
    check('C owner "' . $q . '" -> today\'s sellers, the fixture agent among them', WaBot::$lastRouter === 'office' && WaBot::$lastRole === 'admin'
        && str_contains(txt($r), $mark) && str_contains(txt($r), 'FX1 Zorawar Agentone'), WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa(OWN, 'agent report mahina');
check('C owner "agent report mahina" -> the month board from the books (was "contacts (0)")', WaBot::$lastRouter === 'office' && str_contains(txt($r), '👥') && str_contains(txt($r), date('M Y')) && !str_contains(txt($r), '📇'), txt($r));
foreach (['pending payment?', 'payment baki kati cha', 'verify baki payment', 'भुक्तानी बाँकी कति छ'] as $q) {
    $r = wa(OWN, $q);
    check('C owner "' . $q . '" -> payments waiting, with the pending fixture booking', WaBot::$lastRouter === 'office' && str_contains(txt($r), '⏳') && str_contains(txt($r), $B3['pnr']), WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa(MGR, 'pending payment?');
check('C a manager\'s number gets it too (the office role)', WaBot::$lastRouter === 'office' && str_contains(txt($r), $B3['pnr']), WaBot::$lastRouter . ' :: ' . txt($r));
foreach (['revenue', 'kamai', 'aaja ko kamai kati'] as $q) {
    $r = wa(OWN, $q);
    check('C owner "' . $q . '" -> today + this month', WaBot::$lastRouter === 'office' && str_contains(txt($r), '💰') && str_contains(txt($r), 'This month'), WaBot::$lastRouter . ' :: ' . txt($r));
}
foreach (['sanjh ko report', 'evening report', 'din ko report'] as $q) {
    $r = wa(OWN, $q);
    check('C owner "' . $q . '" -> the office report, now', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'अफिस रिपोर्ट'), WaBot::$lastRouter . ' :: ' . mb_substr(txt($r), 0, 200));
}
foreach (['bus kaha cha?', 'bus kaha pugyo', 'gadi kaha cha ahile'] as $q) {
    $r = wa(OWN, $q);
    check('C owner "' . $q . '" -> where the buses are (never the seats-sold list)', WaBot::$lastRouter === 'office' && str_contains(txt($r), '🚌') && str_contains(txt($r), 'admin/map.php') && !str_contains(txt($r), 'सिट बिक्री'), WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa(OWN, 'bus 99 kaha cha?');
check('C a bus number nobody knows: said so, then every bus on the road — never a guess', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'चिनिएन') && str_contains(txt($r), '🚌'), txt($r));
$anyBus = Database::fetch("SELECT id, bus_number, bus_name FROM buses WHERE is_active = 1 AND CHAR_LENGTH(bus_number) >= 6 ORDER BY id LIMIT 1");
if ($anyBus !== null) {
    $r = wa(OWN, 'bus ' . $anyBus['bus_number'] . ' kaha cha');
    check('C a bus named by its plate: only that bus (its trip, or "not on the road" + the running ones)',
        WaBot::$lastRouter === 'office' && str_contains(txt($r), (string) $anyBus['bus_number']) && !str_contains(txt($r), 'चिनिएन'), WaBot::$lastRouter . ' :: ' . txt($r));
    check('C AiOps::busFor reads the plate, and nothing from a line without a bus', (int) (AiOps::busFor('bus ' . $anyBus['bus_number'] . ' kaha cha')['id'] ?? 0) === (int) $anyBus['id'] && AiOps::busFor('bus kaha cha') === null);
}
$r = wa(OWN, 'seat badalnu cha');
check('C owner "seat badalnu cha" (no PNR): how the desk does it', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'seat A5'), txt($r));
// an agent / counter never gets company figures
foreach (['kun agent le kati kate?', 'revenue', 'pending payment?', 'agent report mahina', 'sabai agent le kati becheko'] as $q) {
    $direct = WaOffice::handle('+91' . AG1, AG1, $q, 'text');
    $r = wa(AG1, $q);
    check('C an AGENT asking "' . $q . '": nothing from WaOffice, no company figures in the reply',
        $direct === null && WaBot::$lastRouter !== 'office' && !str_contains(txt($r), 'Top sellers') && !str_contains(txt($r), 'This month') && !str_contains(txt($r), $B3['pnr'])
        && !str_contains(txt($r), 'FX1 Owner'), WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa(AG1, 'seat badalnu cha');
check('C an agent\'s "seat badalnu cha" -> the hint (SHG-… seat A5, then ho)', WaBot::$lastRouter === 'office' && WaBot::$lastRole === 'staff' && str_contains(txt($r), 'seat A5'), txt($r));
$r = wa(AG1, 'day report');
check('C an agent\'s "day report" -> the hint (report / hisab), not the company report', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'hisab') && !str_contains(txt($r), 'अफिस रिपोर्ट'), txt($r));
// review fix: the lines the desk already answered with the agent's own day keep going to the desk
foreach (['aaja din ko report', 'report daily', 'aaja ko daily report deu', 'today day report', 'aaja ko din bhar ko report'] as $q) {
    $direct = WaOffice::handle('+91' . AG1, AG1, $q, 'text');
    $r = wa(AG1, $q);
    check('C an agent\'s "' . $q . '" is left to the desk (the agent\'s own day report, not the hint)',
        $direct === null && WaBot::$lastRouter === 'apwa' && !str_contains(txt($r), 'write *report* or *hisab*') && !str_contains(txt($r), 'अफिस रिपोर्ट'), WaBot::$lastRouter . ' :: ' . mb_substr(txt($r), 0, 200));
}
$r = wa(AG1, 'din ko report');
check('C …and "din ko report" (the desk does not read it) still gets the hint', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'hisab'), WaBot::$lastRouter . ' :: ' . txt($r));
// a passenger never gets any of it
foreach (['kun agent le kati kate?', 'revenue', 'pending payment?', 'agent report mahina', 'sanjh ko report', 'agents', 'kamai'] as $q) {
    $p = $next();
    $direct = WaOffice::handle('+91' . $p, $p, $q, 'text');
    $r = wa($p, $q);
    check('C a PASSENGER number asking "' . $q . '": no office answer (no sellers, no totals, no pending list)',
        $direct === null && WaBot::$lastRouter !== 'office' && !str_contains(txt($r), 'FX1 Zorawar') && !str_contains(txt($r), '₹') && !str_contains(txt($r), $B3['pnr'])
        && !str_contains(txt($r), 'Top sellers') && !str_contains(txt($r), 'अफिस रिपोर्ट'), WaBot::$lastRouter . ' :: ' . txt($r));
    if (WaBooking::isOpen($p)) { wa($p, 'no'); }
}
// the desk keeps everything that is its own
$buyer = $next();
$r = wa(OWN, '1 seat bholi Surat Fxsale Thapa ' . $buyer);
$quoted = WaBot::$lastRouter === 'apwa' && str_contains(txt($r), '₹');
check('C owner\'s sale line still reaches the desk (a quote, nothing sold)', $quoted && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $buyer], 0) === 0, WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(OWN, 'agents');
check('C while that quote waits for its "ho", "agents" is NOT taken by WaOffice (the desk keeps the line)', WaBot::$lastRouter === 'apwa' && WaOffice::handle('+91' . OWN, OWN, 'revenue', 'text') === null, WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(OWN, 'ho');
check('C …and the "ho" still sells that one ticket', WaBot::$lastRouter === 'apwa' && (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = :p AND status IN ('confirmed','pending')", ['p' => $buyer], 0) === 1, WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(OWN, 'agents');
check('C after the sale is done "agents" is answered again', WaBot::$lastRouter === 'office' && str_contains(txt($r), 'Top sellers'), WaBot::$lastRouter . ' :: ' . txt($r));
foreach (['agents abhi nahi', $B1['pnr'], $B1['pnr'] . ' seat A5', 'SHG-0001 agent report', 'Surat agent ko number', 'agent kati jana chan', 'aaja', 'report', 'tickets', 'balance', 'ho', 'no', 'menu',
          'bus ko ticket kaha painchha', 'khali seat bholi', '2 seat bholi Surat Ram 9812395579'] as $q) {
    check('C WaOffice leaves "' . $q . '" to the desk', WaOffice::handle('+91' . OWN, OWN, $q, 'text') === null);
}
check('C reading the line: strict intents',
    WaOffice::intentOf('kun agent le kati kate') === 'board_today' && WaOffice::intentOf('yo mahina kun agent le kati kate') === 'board_month'
    && WaOffice::intentOf('pending payment') === 'pending' && WaOffice::intentOf('bus 3 kaha cha') === 'bus' && WaOffice::intentOf('kamai') === 'revenue'
    && WaOffice::intentOf('sanjh ko report') === 'day_report' && WaOffice::intentOf('agent ko paisa baki kati cha') !== 'pending'
    && WaOffice::intentOf('office kaha cha') === '' && WaOffice::intentOf('bus ko driver kaha cha') === '' && WaOffice::intentOf('pending') === '' && WaOffice::intentOf('report') === '');
// a number on the office-report list that is not a staff record: today's figures and the bus, never the name lists
$listed = $next();
setOvr('office_report_numbers', '+91' . $listed);
$r = wa($listed, 'aaja ko kamai kati');
check('C an office-report number (no staff record): revenue is answered', WaBot::$lastRouter === 'office' && str_contains(txt($r), '💰'), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa($listed, 'pending payment?');
check('C …but not the pending list with passengers\' names', WaBot::$lastRouter !== 'office' && !str_contains(txt($r), $B3['pnr']), WaBot::$lastRouter . ' :: ' . txt($r));
// review fix: the step-up gate. With wa_ops_stepup_on the desk's company report waits for a fresh panel
// verification — the same figures asked in other words wait for it too (never a figure, the one-time link instead)
setOvr('wa_ops_stepup_on', '1');
Database::run('DELETE FROM wa_identity_links WHERE phone = :p', ['p' => MGR]);
$leak = static fn(string $t): bool => str_contains($t, 'Top sellers') || str_contains($t, '💰') || str_contains($t, 'अफिस रिपोर्ट') || str_contains($t, 'This month')
    || str_contains($t, $B3['pnr']) || str_contains($t, 'FX1 Zorawar') || str_contains($t, '👥') || str_contains($t, '₹');
$links = 0;
foreach (['revenue', 'sanjh ko report', 'kun agent le kati kate?', 'pending payment?', 'agent report mahina'] as $i => $q) {
    $r = wa(MGR, $q);
    $links += str_contains(txt($r), 'admin/wa-verify.php?t=') ? 1 : 0;
    check('C step-up ON, an UNVERIFIED manager number asking "' . $q . '": the verification step, no figure',
        WaBot::$lastRouter === 'office' && str_contains(txt($r), '🔐') && !$leak(txt($r)) && ($i > 0 || str_contains(txt($r), 'admin/wa-verify.php?t=')), WaBot::$lastRouter . ' :: ' . txt($r));
}
check('C …one link only for the five questions (the later ones point at the link already sent)', $links === 1, (string) $links);
$r = wa(MGR, 'report');
check('C …the desk\'s own "report" is refused the same way (the gate both paths share)', WaBot::$lastRouter === 'apwa' && !$leak(txt($r)), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(MGR, 'bus kaha cha?');
check('C …where the bus is carries no money and is still answered', WaBot::$lastRouter === 'office' && str_contains(txt($r), '🚌') && !str_contains(txt($r), '🔐'), WaBot::$lastRouter . ' :: ' . txt($r));
Database::run('INSERT INTO wa_identity_links (phone, admin_id, verified_at, verified_via) VALUES (:p, :a, NOW(), :v)
               ON DUPLICATE KEY UPDATE admin_id = :a2, verified_at = NOW(), verified_via = :v2', ['p' => MGR, 'a' => $mgrId, 'v' => 'fx1aiwa', 'a2' => $mgrId, 'v2' => 'fx1aiwa']);
$r = wa(MGR, 'revenue');
check('C …once the number is verified in the panel the figures are answered', WaBot::$lastRouter === 'office' && str_contains(txt($r), '💰') && !str_contains(txt($r), '🔐'), WaBot::$lastRouter . ' :: ' . txt($r));
Database::run('DELETE FROM wa_identity_links WHERE phone = :p', ['p' => MGR]);
setOvr('office_report_numbers', '+91' . $listed);
$r = wa($listed, 'revenue');
check('C …a number that is only on the office-report list has no account to verify: unchanged', WaBot::$lastRouter === 'office' && str_contains(txt($r), '💰'), WaBot::$lastRouter . ' :: ' . txt($r));
setOvr('office_report_numbers', '');
setOvr('wa_ops_stepup_on', '0');
setOvr('wa_office_ask_on', '0');
$r = wa(OWN, 'kun agent le kati kate?');
check('C switch wa_office_ask_on = 0: the desk answers as before', WaBot::$lastRouter === 'apwa' && !str_contains(txt($r), 'Top sellers'), WaBot::$lastRouter . ' :: ' . txt($r));
setOvr('wa_office_ask_on', '1');

/* ===================================================================== */
section('D the assistant never cancels for a passenger, on any channel');
$asReq = new ReflectionMethod('AiTools', 'cancelAsRequest');
$asReq->setAccessible(true);
$cx = static fn(array $c): bool => (bool) $asReq->invoke(null, $c);
applyOverrides();
check('D request-only for a passenger on WhatsApp, the website and Messenger; staff and the office keep the real tool',
    $cx(['role' => 'customer', 'channel' => 'whatsapp']) && $cx(['role' => 'customer']) && $cx(['role' => 'customer', 'channel' => 'web', 'verified' => true])
    && $cx(['role' => 'customer', 'channel' => 'web', 'via' => 'facebook']) && !$cx(['role' => 'staff', 'channel' => 'whatsapp']) && !$cx(['role' => 'admin', 'channel' => 'web']));
$descOf = static function (array $tools, string $name): string {
    foreach ($tools as $t) { if (($t['name'] ?? '') === $name) { return (string) ($t['description'] ?? ''); } }
    return '';
};
$ctxOf = static function (string $phone, int $turn, array $extra = []): array {
    applyOverrides();
    $c = AiTools::whoIs('+91' . $phone);
    $c['turn'] = $turn;
    $c['channel'] = 'whatsapp';
    $c['messageText'] = 'ho';
    return $extra + $c;
};
$B5 = $book('9812395573', 0, ['Fxweb Cancel Karki']);
$B6 = $book('9812395574', 0, ['Fxfb Cancel Magar']);
foreach ([['the website (verified)', '9812395573', $B5, ['channel' => 'web', 'verified' => true], '[Website]'],
          ['Messenger', '9812395574', $B6, ['channel' => 'web', 'via' => 'facebook', 'verified' => true], '[Messenger]']] as [$label, $ph, $bk, $extra, $tag]) {
    check('D ' . $label . ': cancel_ticket is offered as a REQUEST to the office', str_starts_with($descOf(AiTools::catalogue($ctxOf($ph, 1, $extra)), 'cancel_ticket'), 'Send a CANCEL REQUEST'));
    $rq = AiTools::run('refund_quote', ['pnr' => $bk['pnr']], $ctxOf($ph, 1, $extra));
    $res = AiTools::run('cancel_ticket', ['pnr' => $bk['pnr'], 'reason' => 'Plan badliyo', 'confirm' => true], $ctxOf($ph, 2, $extra));
    $ted = Database::fetch("SELECT * FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = 'cancel_request' ORDER BY id DESC LIMIT 1", ['b' => $bk['id']]);
    check('D ' . $label . ': the yes files a request — ok false, booking still confirmed, audit row names the channel',
        !empty($rq['ok']) && str_contains((string) $rq['say'], 'REQUEST') && $res['ok'] === false && !empty($res['data']['requested']) && $statusOf($bk) === 'confirmed'
        && $ted !== null && (string) $ted['outcome'] === 'requested' && str_contains((string) $ted['detail'], $tag), (string) $res['say'] . ' / ' . (string) ($ted['detail'] ?? 'no row'));
}
check('D an agent and the office are still offered the real cancel',
    str_starts_with($descOf(AiTools::catalogue($ctxOf(AG1, 1)), 'cancel_ticket'), 'CANCEL a booking') && str_starts_with($descOf(AiTools::catalogue($ctxOf(OWN, 1)), 'cancel_ticket'), 'CANCEL a booking'));
setOvr('ai_cancel_request_all_on', '0');
check('D switch ai_cancel_request_all_on = 0: the 16 Nov rule (WhatsApp passengers only)',
    $cx(['role' => 'customer', 'channel' => 'whatsapp']) && !$cx(['role' => 'customer', 'channel' => 'web', 'verified' => true]) && !$cx(['role' => 'customer', 'channel' => 'web', 'via' => 'facebook']));
setOvr('ai_cancel_request_all_on', '1');
setOvr('wa_ai_cancel_mode', 'self');
check('D the owner\'s own override wa_ai_cancel_mode = self still means self-cancel (nobody is request-only)',
    !$cx(['role' => 'customer', 'channel' => 'whatsapp']) && !$cx(['role' => 'customer', 'channel' => 'web', 'verified' => true]));
setOvr('wa_ai_cancel_mode', 'request');
check('D …and that override, like the new safety switches, is a super-admin\'s setting (the mp1 list itself is unchanged)',
    AiOps::superOnlySetting('wa_ai_cancel_mode') && AiOps::superOnlySetting('ai_cancel_request_all_on') && AiOps::superOnlySetting('wa_confirm_super_only_on')
    && !AiOps::superOnlySetting('wa_human_ask_on') && !AiOps::superOnlySetting('wa_office_ask_on') && !AiOps::superOnlySetting('wa_agent_on') && count(AiOps::SUPER_ONLY_SETTINGS) === 4);

/* ===================================================================== */
section('E confirming a payment from chat: the super-admin, behind a fresh step-up');
setOvr('wa_agent_admin_write', '1');
check('E office_confirm stays on the office list (refused at run time, never hidden)', in_array('office_confirm', array_column(AiTools::catalogue($ctxOf(MGR, 1)), 'name'), true));
$res = AiTools::run('office_confirm', ['pnr' => $B3['pnr'], 'confirm' => true], $ctxOf(MGR, 2));
check('E a MANAGER\'s number: refused, the booking stays pending', $res['ok'] === false && str_contains((string) $res['say'], 'super-admin') && $statusOf($B3) === 'pending', (string) $res['say']);
$res = AiTools::run('office_confirm', ['pnr' => $B3['pnr'], 'confirm' => true], $ctxOf(OWN, 2));
check('E the super-admin WITHOUT a fresh verification (global step-up off): refused with the one-time link, still pending',
    $res['ok'] === false && !empty($res['data']['needs_verification']) && str_contains((string) $res['say'], 'VERIFICATION NEEDED') && $statusOf($B3) === 'pending', (string) $res['say']);
Database::run('INSERT INTO wa_identity_links (phone, admin_id, verified_at, verified_via) VALUES (:p, :a, NOW(), :v)
               ON DUPLICATE KEY UPDATE admin_id = :a2, verified_at = NOW(), verified_via = :v2', ['p' => OWN, 'a' => $ownId, 'v' => 'fx1aiwa', 'a2' => $ownId, 'v2' => 'fx1aiwa']);
$res = AiTools::run('office_confirm', ['pnr' => $B1['pnr'], 'confirm' => true], $ctxOf(OWN, 3));
check('E the super-admin WITH a fresh verification passes the gate (a non-pending PNR: "nothing to confirm")', $res['ok'] === false && str_contains((string) $res['say'], 'nothing to confirm'), (string) $res['say']);
Database::run('INSERT INTO wa_identity_links (phone, admin_id, verified_at, verified_via) VALUES (:p, :a, NOW(), :v)
               ON DUPLICATE KEY UPDATE admin_id = :a2, verified_at = NOW(), verified_via = :v2', ['p' => MGR, 'a' => $mgrId, 'v' => 'fx1aiwa', 'a2' => $mgrId, 'v2' => 'fx1aiwa']);
$res = AiTools::run('office_confirm', ['pnr' => $B3['pnr'], 'confirm' => true], $ctxOf(MGR, 3));
check('E a manager is refused even when verified', $res['ok'] === false && str_contains((string) $res['say'], 'super-admin') && $statusOf($B3) === 'pending', (string) $res['say']);
setOvr('wa_confirm_super_only_on', '0');
$res = AiTools::run('office_confirm', ['pnr' => $B1['pnr'], 'confirm' => true], $ctxOf(MGR, 4));
check('E switch wa_confirm_super_only_on = 0: the old rule (any office number reaches the booking check)', str_contains((string) $res['say'], 'nothing to confirm'), (string) $res['say']);
setOvr('wa_confirm_super_only_on', '1');
setOvr('wa_agent_admin_write', '0');
$res = AiTools::run('office_confirm', ['pnr' => $B3['pnr'], 'confirm' => true], $ctxOf(OWN, 5));
check('E with wa_agent_admin_write off (the default) nobody confirms from chat', $res['ok'] === false && $statusOf($B3) === 'pending', (string) $res['say']);
$res = AiTools::run('office_confirm', ['pnr' => $B3['pnr'], 'confirm' => true], $ctxOf(C3, 1));
check('E a passenger number calling office_confirm: not permitted', $res['ok'] === false && $statusOf($B3) === 'pending', (string) $res['say']);

/* ===================================================================== */
section('F small ones: hello menu, date change as a request, no pay QR on pay-at-boarding, nothing about parcels');
foreach (['hi', 'hello', 'namaste', 'नमस्ते'] as $q) {
    $p = $next();
    $r = wa($p, $q);
    check('F "' . $q . '" with wa_faq_on off: the welcome + the menu (3 buttons, and in words)',
        WaBot::$lastRouter === 'welcome' && is_array($r['ui'] ?? null) && count((array) ($r['ui']['buttons'] ?? [])) === 3 && str_contains(txt($r), 'mero ticket')
        && str_contains(txt($r), 'office') && str_contains((string) ($r['ui']['body'] ?? ''), 'PNR') && mb_strlen((string) ($r['ui']['body'] ?? '')) <= 1024,
        WaBot::$lastRouter . ' :: ' . txt($r));
}
$p = $next();
$r = wa($p, 'asdf qwer zxcv');
check('F a line that is not a hello keeps the plain welcome (no menu forced on it)', WaBot::$lastRouter !== 'welcome' || !is_array($r['ui'] ?? null), WaBot::$lastRouter);
$p = $next();
setOvr('wa_welcome_menu_on', '0');
$r = wa($p, 'hi');
check('F switch wa_welcome_menu_on = 0: the welcome alone, as before', WaBot::$lastRouter === 'welcome' && !is_array($r['ui'] ?? null) && !str_contains(txt($r), 'mero ticket'), txt($r));
setOvr('wa_welcome_menu_on', '1');

// date change as an office request
setOvr('wa_date_change_mode', 'request');
WaEdit::$codeGen = static fn(): string => '515151';
$oldDate = (string) Database::scalar("SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => $B4['id']], '');
$newDate = addDaysISO($oldDate, 2);
// review fix: a line that names the PNR AND the new date gives the date at once (the 31 Oct rule), in the opening
// words and as the answer to the date question; only a PNR with no date beside it is a lookup
$dWords = date('j M', (int) strtotime($newDate));
$r = wa(C4, $B4['pnr'] . ' ko miti badalnu cha, ' . $dWords . ' ma jane');
check('F "<PNR> ko miti badalnu cha, ' . $dWords . ' ma jane": the date in the line is taken at once (no second ask)',
    WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '🔐') && str_contains(txt($r), 'request') && WaEdit::parseDate($dWords) === $newDate, WaBot::$lastRouter . ' :: ' . txt($r));
wa(C4, 'stop');
wa(C4, 'miti badalnu cha');
$r = wa(C4, $B4['pnr'] . ' ' . $dWords);
check('F "<PNR> ' . $dWords . '" as the answer to the date question is the new date, not a ticket lookup',
    WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '🔐') && str_contains(txt($r), 'request'), WaBot::$lastRouter . ' :: ' . txt($r));
wa(C4, 'stop');
wa(C4, 'miti badalnu cha');
$r = wa(C4, $newDate);
check('F wa_date_change_mode = request: the new date is read back as a REQUEST and the code is asked', WaBot::$lastRouter === 'waedit' && str_contains(txt($r), '🔐') && str_contains(txt($r), 'request'), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(C4, '515151');
$tr = Database::fetch("SELECT * FROM ticket_requests WHERE booking_id = :b ORDER BY id DESC LIMIT 1", ['b' => $B4['id']]);
check('F …the code files a row in the office queue (change_date, open) and the ticket is NOT changed',
    $tr !== null && (string) $tr['kind'] === 'change_date' && (string) $tr['wanted_date'] === $newDate && (string) $tr['status'] === 'open'
    && (string) Database::scalar("SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => $B4['id']], '') === $oldDate
    && str_contains(txt($r), 'NOT changed') && $auditOf($B4, 'travel_date') === 1, txt($r) . ' / ' . json_encode($tr));
setOvr('wa_date_change_mode', 'self');
check('F the default stays self-service (wa_date_change_mode = self): the switch is opt-in', Settings::getString('wa_date_change_mode', 'self') === 'self');

// pay-at-boarding never shows a pay QR
setOvr('upi_id', 'fx1test@upi');
$B7 = $book('9812395575', 0, ['Fxpay Pending Oli'], 'pending', 0);
$r = wa(C3, 'kasari tirne?');
check('F "kasari tirne?" on a pay-at-boarding booking: no QR picture', WaBot::$lastRouter === 'walocal' && str_contains(txt($r), $B3['pnr']) && ($r['media'] ?? null) === null, txt($r));
$r = wa('9812395575', 'kasari tirne?');
check('F …an ordinary pending booking still gets its QR', WaBot::$lastRouter === 'walocal' && str_contains((string) ($r['media'] ?? ''), 'pay-image.php'), WaBot::$lastRouter . ' :: ' . txt($r));
$pi = AiTools::run('payment_info', ['pnr' => $B3['pnr']], $ctxOf(C3, 1));
$pj = AiTools::run('payment_info', ['pnr' => $B7['pnr']], $ctxOf('9812395575', 1));
check('F the assistant\'s payment_info: no QR for pay-at-boarding, the QR for an ordinary pending booking', !empty($pi['ok']) && $pi['media'] === null && str_contains((string) ($pj['media'] ?? ''), 'pay-image.php'));
setOvr('wa_answer_first_on', '0');
setOvr('wa_lookup_on', '0');
$r = wa(C3, $B3['pnr']);
$rj = wa('9812395575', $B7['pnr']);
check('F the old bare-PNR card (answer-first off): no QR for pay-at-boarding, the QR for the other', WaBot::$lastRouter === 'pnr' && ($r['media'] ?? null) === null && !str_contains(txt($r), 'QR')
    && str_contains((string) ($rj['media'] ?? ''), 'pay-image.php'), txt($r));
setOvr('wa_answer_first_on', '1');
setOvr('wa_lookup_on', '1');
setOvr('upi_id', '');

// parcels are leaving the product: nothing in the bot offers them
$said = [];
foreach (['parcel', 'parcel pathauna milcha?', 'saman pathaunu cha Nepal', 'menu', 'help', 'courier service cha?'] as $q) {
    $p = $next();
    $said[] = txt(wa($p, $q));
    if (WaBooking::isOpen($p)) { wa($p, 'no'); }
}
foreach ([AG1, OWN] as $s) { $said[] = txt(wa($s, 'menu')); $said[] = txt(wa($s, 'help')); }
foreach (['customer', 'staff', 'admin'] as $role) { foreach (['ne', 'hi', 'en'] as $lg) { $said[] = WaOrder::menuText($role, $lg) . json_encode(WaOrder::menuUi($role, $lg, ''), JSON_UNESCAPED_UNICODE); } }
check('F no menu, help or answer mentions a parcel / courier service', preg_match($PARCEL, implode("\n", $said)) !== 1);
$src = '';
foreach (array_merge(glob(INCLUDE_PATH . '/wa*.php') ?: [], glob(INCLUDE_PATH . '/ai*.php') ?: [], glob(dirname(__DIR__) . '/whatsapp/*.php') ?: []) as $f) {
    $src .= preg_match('/parcel|पार्सल/iu', (string) file_get_contents($f)) === 1 ? basename($f) . ' ' : '';
}
check('F no bot / AI source file carries a parcel offer', $src === '', $src);

/* ===================================================================== */
section('G the templates paper');
$doc = (string) @file_get_contents(dirname(__DIR__) . '/docs/fx1-whatsapp-templates.md');
require_once INCLUDE_PATH . '/watplmeta.php';
require_once ROOT_PATH . '/whatsapp/aisensy.php';
$missing = [];
foreach (array_merge(array_keys(WaTplMeta::KNOWN), array_keys(WaTplMeta::DEFS), ['shg_login_code', 'shg_help_ticket', 'shg_offer', 'shg_offer_image',
    AiSensyAPI::TPL_TICKET, AiSensyAPI::TPL_REMINDER, AiSensyAPI::TPL_CHALAN, AiSensyAPI::TPL_OFFICE, AiSensyAPI::TPL_MESSAGE, AiSensyAPI::TPL_TRIP]) as $name) {
    if (!str_contains($doc, '`' . $name . '`')) { $missing[] = $name; }
}
check('G docs/fx1-whatsapp-templates.md lists every template the registry and the senders name', $doc !== '' && $missing === [], implode(', ', $missing));
check('G …and records the 132001 / old AiSensy app issue for the owner', str_contains($doc, '132001') && stripos($doc, 'AiSensy') !== false);
check('(harness) the pool of fresh passenger numbers was not exhausted', $ci <= 60, (string) $ci);

echo "\n" . ($FAIL === 0 ? "ALL PASSED: $PASS" : "FAILED: $FAIL (passed $PASS)") . "\n";
exit($FAIL === 0 ? 0 : 1);
