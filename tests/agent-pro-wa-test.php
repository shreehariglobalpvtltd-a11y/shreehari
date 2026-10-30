<?php
/**
 * =====================================================================
 *  agent-pro-wa-test.php — the WhatsApp desk and the AI on the VPS.
 *
 *  Talks to ApWa::reply() exactly as includes/wabot.php does, as a real
 *  agent, an office manager and a passenger, against the TEST database:
 *
 *    - passengers are left to the existing bot; "join" gets the form link;
 *      "JOIN 123456" verifies an applicant
 *    - LINK SHG-xxxx: OTP to the REGISTERED mobile, wrong code refused,
 *      right code links, the registered phone is told
 *    - balance · statement (+ PDF link) · withdraw (needs "ho") · pay-in
 *      with UTR · ID card / visiting card as signed images · my details ·
 *      contacts (agents see the office only) · office facts with the CIN
 *    - a ticket sale: quote first, "ho" sells on the agent's code, the
 *      commission lands on the agent's wallet
 *    - ticket edits from a message: name, pickup, seat, date; cancel
 *    - Nepali / Hindi / English replies, remembered per number
 *    - the AI (a mock Ollama started here): JSON-schema reads, think:false,
 *      vision reading of an ID photo, the benchmark, failure → a polite
 *      fallback, the Twilio-style queue + worker, OpenAI-compatible servers
 *
 *    php tests/agent-pro-wa-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-docs.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 160) : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 400) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
/** One WhatsApp message, as the webhook would pass it. */
function wa(string $from, string $text): ?array { return ApWa::reply('+' . $from, $text, 'text'); }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }

if (!AgentPro::available()) {
    check('ap_* tables exist', false);
    exit(1);
}

/* ---- settings for the run (restored at the end) ---- */
$keep = [];
foreach (['whatsapp_driver' => 'click_to_chat', 'ap_wa_on' => '1', 'ap_wa_sell' => '1', 'ap_ai_on' => '0', 'ap_ai_base' => 'http://127.0.0.1:11555',
          'ap_ai_model' => '', 'ap_ai_vision_model' => '', 'ap_ai_ack' => '0', 'ap_ai_customers' => '1', 'ap_ai_replace_cloud' => '1',
          'ap_wa_dir_agents' => '0', 'wa_agent_rewrite' => '1', 'agent_kyc_required' => '0', 'daily_service_on' => '1', 'ap_ai_timeout' => '30'] as $k => $v) {
    $keep[$k] = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k], null);
    Settings::set($k, $v, in_array($v, ['0', '1'], true) && $k !== 'ap_ai_timeout' ? 'bool' : 'string', 'agentpro', false);
}
Settings::flush();

$A_REG = '7000000301';   // the agent's registered mobile
$A_WA  = '7000000399';   // a second WhatsApp they link with LINK + OTP
$MGR   = '7000000302';   // an office manager
$CUST  = '7000000303';   // a passenger
$PAX   = '9812345671';   // the passenger an agent books for
$cleanup = static function () use ($A_REG, $A_WA, $MGR, $CUST, $PAX): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone IN ('$PAX', '9812345672') OR sold_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'apwa_%')") as $b) {
        try { Seats::releaseBooking((int) $b['id']); } catch (Throwable $e) {}
        foreach (['tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'agent_ledger'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => (int) $b['id']]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]); } catch (Throwable $e) {}
    }
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'apwa_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    foreach ([$A_REG, $A_WA, $MGR, $CUST] as $p) {
        Database::delete('ap_wa_state', 'phone = :p', ['p' => $p]);
        Database::delete('ap_wa_links', 'phone = :p', ['p' => $p]);
        Database::delete('kv_store', "kkey = :p AND kscope IN ('wa_stage','wa_ticket_fix','wa_agent','wa_booking')", ['p' => $p]);
    }
    Database::delete('ap_jobs', "phone IN ('$A_REG', '$A_WA', '$MGR', '$CUST')");
    Database::delete('ap_applications', "full_name LIKE 'APWA %'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap\\_%' OR bucket LIKE 'otp_%' OR bucket LIKE 'wa_%'");
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'aplink:%'");
};
$cleanup();

$mk = static function (string $user, string $name, string $phone, string $role) : int {
    $id = Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    AgentWallet::saveProfile($id, ['counter_name' => 'Mehsana', 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
$agentId = $mk('apwa_agent', 'APWA Ramesh Agent', $A_REG, 'agent');
AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('person'));
$code = AgentWallet::agentCodeLabel($agentId);
$mgrId = $mk('apwa_mgr', 'APWA Office Manager', $MGR, 'manager');
$otherAgent = $mk('apwa_agent2', 'APWA Other Agent', '7000000304', 'agent');
AgentWallet::setAgentCode($otherAgent, AgentWallet::nextFreeAgentCode('person'));

/* =================================================================== */
section('passengers, applicants');
check('a passenger\'s ordinary message is left to the existing bot', wa($CUST, 'kati baje bus cha?') === null);
check('"join" gets the join form link', str_contains(txt(wa($CUST, 'join')), '/join.php'));
$d = AgentPro::newDraft();
AgentPro::saveDraft($d['draft_key'], ['full_name' => 'APWA Applicant']);
$rev = AgentPro::reverseCode($d['draft_key']);
$r = wa('91' . $CUST, 'JOIN ' . $rev['code']);
check('"JOIN 123456" from WhatsApp verifies the applicant', str_contains(txt($r), $d['ref']) && AgentPro::draft($d['draft_key'])['wa_verified_via'] === 'reverse');
check('a made-up JOIN code is refused', str_contains(txt(wa($CUST, 'JOIN 000001')), '⚠️'));

/* =================================================================== */
section('LINK + OTP');
check('a stranger number is not an agent yet', ApWa::memberFor($A_WA) === null);
$r = wa($A_WA, 'LINK ' . $code);
check('LINK: the OTP goes to the REGISTERED mobile (tail shown), not to the sender', str_contains(txt($r), '…' . substr($A_REG, -2)), txt($r));
check('…and the registered mobile got the message', (bool) Database::fetch("SELECT id FROM message_logs WHERE to_number LIKE :p AND body LIKE '%link code%' ORDER BY id DESC LIMIT 1", ['p' => '%' . $A_REG . '%']));
Database::run("UPDATE otp_codes SET code_hash = :h WHERE identifier = :i AND is_used = 0", ['h' => password_hash('246810', PASSWORD_BCRYPT), 'i' => 'aplink:' . $agentId]);
check('a wrong code is refused', str_contains(txt(wa($A_WA, '111111')), '⚠️'));
$r = wa($A_WA, '246810');
check('the right code links this WhatsApp to the code', str_contains(txt($r), $code) && (ApWa::memberFor($A_WA)['id'] ?? 0) === $agentId, txt($r));
check('the registered phone is told about the new link', (bool) Database::fetch("SELECT id FROM message_logs WHERE to_number LIKE :p AND body LIKE :b ORDER BY id DESC LIMIT 1",
    ['p' => '%' . $A_REG . '%', 'b' => '%' . $A_WA . '%']));
check('LINK an unknown code → a clear refusal', str_contains(txt(wa($CUST, 'LINK SHG-0999')), 'LINK SHG-0027'));
check('the registered mobile works without LINK', (ApWa::memberFor($A_REG)['id'] ?? 0) === $agentId);

/* =================================================================== */
section('the desk: help, language, money, cards, people, company');
$r = wa($A_WA, 'help');
check('help (Nepali by default) lists the commands', str_contains(txt($r), 'balance') && str_contains(txt($r), 'id card') && str_contains(txt($r), $code));
check('"english" switches the language and it sticks', str_contains(txt(wa($A_WA, 'english')), 'English') && str_contains(txt(wa($A_WA, 'help')), 'Ticket'));
wa($A_WA, 'nepali');
AgentWallet::record($agentId, 'commission', 1200, ['note' => 'test']);
AgentWallet::record($agentId, 'cash_due', 2000, ['note' => 'test']);
$r = wa($A_WA, 'balance');
check('balance: commission due and cash held, apart', str_contains(txt($r), '1,200') && str_contains(txt($r), '2,000'), txt($r));
$r = wa($A_WA, 'बैलेंस बताइए, कितना है मेरा?');
check('a Hindi message gets a Hindi answer', str_contains(txt($r), 'कंपनी'), txt($r));
check('…and a bare "बैलेंस" next keeps Hindi (no evidence to switch)', str_contains(txt(wa($A_WA, 'बैलेंस')), 'कंपनी'));
wa($A_WA, 'nepali');
$r = wa($A_WA, 'statement');
check('statement lists the entries and a PDF link', str_contains(txt($r), 'कमिसन') && (str_contains(txt($r), 'download-statement.php') || str_contains(txt($r), '/me/')), mb_substr(txt($r), 0, 200));
$r = wa($A_WA, 'withdraw 500');
check('withdraw asks for "ho" first (nothing requested yet)', str_contains(txt($r), '*ho*') && AgentWallet::openPayoutRequests($agentId) === []);
check('"no" drops it', str_contains(txt(wa($A_WA, 'no')), '👍') && AgentWallet::openPayoutRequests($agentId) === []);
wa($A_WA, 'withdraw 500');
$r = wa($A_WA, 'ho');
check('"ho" files the request with the office', str_contains(txt($r), '✅') && count(AgentWallet::openPayoutRequests($agentId)) === 1);
check('more than due is refused', str_contains(txt(wa($A_WA, 'withdraw 99999')), '⚠️'));
$utr = (string) random_int(100000000000, 999999999999);
$r = wa($A_WA, 'jama 1500 UTR ' . $utr);
check('pay-in with a UTR is recorded for the office', str_contains(txt($r), $utr) && count(AgentPro::openDeposits($agentId)) === 1, txt($r));
check('the same UTR again is refused', str_contains(txt(wa($A_WA, 'jama 1500 UTR ' . $utr)), '⚠️'));
$r = wa($A_WA, 'id card');
check('id card → a signed PNG link', str_contains((string) ($r['media'] ?? ''), 'api/agentpro-card.php?m=' . $agentId . '&s=id-front'));
parse_str((string) parse_url((string) $r['media'], PHP_URL_QUERY), $q);
check('…whose signature checks out', ApCard_check((int) $q['m'], (string) $q['s'], (int) $q['e'], (string) $q['k']));
$r = wa($A_WA, 'visiting card');
check('visiting card → the card image + the share link', str_contains((string) ($r['media'] ?? ''), 's=vc-front') && str_contains(txt($r), 'c.php?u='));
check('"me" shows their own details', str_contains(txt(wa($A_WA, 'me')), $code));
$r = wa($A_WA, 'contacts');
check('an agent sees the office, not other agents\' numbers', str_contains(txt($r), 'APWA Office Manager') && !str_contains(txt($r), 'APWA Other Agent'), txt($r));
$r = wa($MGR, 'contacts');
check('the office sees everyone', str_contains(txt($r), 'APWA Other Agent') && str_contains(txt($r), 'APWA Ramesh Agent'));
check('"number <name>" searches', str_contains(txt(wa($MGR, 'number Other')), 'APWA Other Agent'));
check('office facts with the CIN', str_contains(txt(wa($A_WA, 'cin')), Settings::company()['cin']));
check('"bus" answers with the day\'s buses', str_contains(txt(wa($A_WA, 'bus')), '🚌'));
check('"aaja" — the agent\'s own day', str_contains(txt(wa($A_WA, 'aaja')), '📊'));
check('the office "today" is the whole company', str_contains(txt(wa($MGR, 'today')), 'कम्पनी') || str_contains(txt(wa($MGR, 'today')), 'Company'));

/* =================================================================== */
section('a ticket sale on the agent\'s code');
$date = date('Y-m-d', strtotime('+3 days'));
$r = wa($A_WA, "2 seat $date Surat, Ram Thapa $PAX");
check('the first message only QUOTES (nothing booked)', str_contains(txt($r), '₹') && str_contains(txt($r), '*ho*')
    && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAX]), txt($r));
$r = wa($A_WA, 'ho');
$bk = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PAX]);
check('"ho" sells it', $bk !== null && str_contains(txt($r), (string) ($bk['pnr'] ?? '----')), txt($r));
$pnr = (string) ($bk['pnr'] ?? '');
check('…on the agent\'s code (sold_by = the agent)', (int) ($bk['sold_by_admin_id'] ?? 0) === $agentId);
check('…with the commission on the agent\'s wallet', (bool) Database::fetch("SELECT id FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' AND agent_admin_id = :a",
    ['b' => (int) ($bk['id'] ?? 0), 'a' => $agentId]));
check('…and the ticket picture back to the agent', str_contains((string) ($r['media'] ?? ''), 'ticket') || str_contains((string) ($r['media'] ?? ''), $pnr));
$r = wa($A_WA, 'bholi 1 seat Surat');
check('a quote without the passenger asks for name + number', str_contains(txt($r), 'मोबाइल') || str_contains(txt($r), 'mobile'));
$r = wa($A_WA, 'Hari Thapa 9812345672');
check('…which the next message fills in (re-quoted)', str_contains(txt($r), 'Hari Thapa') && str_contains(txt($r), '*ho*'), txt($r));
wa($A_WA, 'no');

/* =================================================================== */
section('changing the ticket from a message');
if ($pnr !== '') {
    $r = wa($A_WA, "$pnr naam Sita Thapa");
    check('a PNR with two passengers asks which one', str_contains(txt($r), '👥'), txt($r));
    $first = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) $bk['id']]);
    $r = wa($A_WA, "$pnr $first ko naam Sita Thapa banau");
    check('the edit is a PREVIEW (old → new) first', str_contains(txt($r), '→') && str_contains(txt($r), 'Sita Thapa'), txt($r));
    $r = wa($A_WA, 'ho');
    check('"ho" applies it', str_contains(txt($r), '✅') && Database::exists('SELECT 1 FROM booking_passengers WHERE booking_id = :b AND full_name = :n', ['b' => (int) $bk['id'], 'n' => 'Sita Thapa']), txt($r));
    check('…and re-issues the ticket (REV n)', (int) Database::scalar('SELECT reissue_count FROM tickets WHERE booking_id = :b', ['b' => (int) $bk['id']], 0) >= 1);
    $legRoute = (int) Database::scalar('SELECT s.route_id FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id = :b LIMIT 1', ['b' => (int) $bk['id']], 0);
    $stops = Boarding::stopsFor($legRoute);
    $cur = (string) Database::scalar("SELECT boarding_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bk['id']], '');
    $other = '';
    foreach ($stops as $st) {
        if (Boarding::townKey($st['name']) !== Boarding::townKey($cur)) { $other = Boarding::stopDisplay($st['name'])['name']; break; }
    }
    if ($other !== '') {
        $r = wa($A_WA, "$pnr pickup $other");
        check('pickup change: preview', str_contains(txt($r), '→'), txt($r));
        wa($A_WA, 'ho');
        check('pickup change: applied to a stop of that route', Boarding::townKey((string) Database::scalar("SELECT boarding_stop FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bk['id']], '')) !== Boarding::townKey($cur));
    }
    check('a pickup that is not on the route is refused with the list', str_contains(txt(wa($A_WA, "$pnr pickup Kathmandu Airport")), '⚠️'));
    $schedule = (int) Database::scalar("SELECT schedule_id FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bk['id']], 0);
    $taken = Seats::bookedSeats($schedule);
    $free = '';
    foreach (Seats::seatIdsForSchedule($schedule) as $sid) {
        if (!in_array($sid, array_map('strval', $taken), true) && preg_match('/^L\d+$/', $sid)) { $free = $sid; break; }
    }
    if ($free !== '') {
        $seatNow = (string) Database::scalar('SELECT seat_no FROM booking_passengers WHERE booking_id = :b AND full_name = :n', ['b' => (int) $bk['id'], 'n' => 'Sita Thapa'], '');
        $r = wa($A_WA, "$pnr Sita ko seat $free");
        check('seat change: preview', str_contains(txt($r), $free), txt($r));
        $r = wa($A_WA, 'ho');
        check('seat change: moved (the seat map\'s own transfer)', Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b AND seat_no = :s', ['b' => (int) $bk['id'], 's' => $free]), txt($r));
    }
    $r = wa($A_WA, "$pnr phone 9812345679");
    wa($A_WA, 'ho');
    check('contact phone change', (string) Database::scalar('SELECT contact_phone FROM bookings WHERE id = :i', ['i' => (int) $bk['id']], '') === '9812345679', txt($r));
    Database::update('bookings', ['contact_phone' => $PAX], 'id = :i', ['i' => (int) $bk['id']]);
    $newDate = date('Y-m-d', strtotime('+4 days'));
    $r = wa($A_WA, "$pnr date $newDate");
    if (str_contains(txt($r), '→')) {
        $r2 = wa($A_WA, 'ho');
        check('date change: the desk\'s own rebook moved the trip', (string) Database::scalar("SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bk['id']], '') === $newDate, txt($r2));
    } else {
        check('date change: a clear answer when it cannot be done', str_contains(txt($r), '⚠️'), txt($r));
    }
    $other = wa('7000000304', "$pnr naam Hacker");
    check('another agent cannot touch this ticket', str_contains(txt($other), '⚠️'), txt($other));
    $r = wa($A_WA, "$pnr cancel");
    check('cancel: the refund figure first', str_contains(txt($r), '₹') && str_contains(txt($r), '*ho*'), txt($r));
    $r = wa($A_WA, 'ho');
    check('…then cancelled', (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $bk['id']], '') === 'cancelled', txt($r));
}

/* =================================================================== */
section('the AI on the VPS (mock Ollama)');
$mockLog = sys_get_temp_dir() . '/mock-ollama-requests.jsonl';
@unlink($mockLog);
$mock = null;
$up = static fn(): bool => @file_get_contents('http://127.0.0.1:11555/api/version') !== false;
// 30 Oct 2026 (af1): the PHP that runs this test may carry its extensions in a -c ini; children get the same one.
$phpArgs = static fn(): array => php_ini_loaded_file() ? [PHP_BINARY, '-c', (string) php_ini_loaded_file()] : [PHP_BINARY];
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
if (!$up()) {
    // af1: the AI switched ON with no model server = the desk's own menu, never "⏳" then "could not understand"
    Settings::set('ap_ai_on', '1', 'bool', 'agentpro', false);
    Settings::flush();
    ApAi::resetHealth();
    check('switch on but no model server: enabled() is false (cached health probe)', ApAi::configured() && !ApAi::enabled() && !ApAi::docsReady());
    $t0 = microtime(true);
    $r = wa($A_WA, 'malai aaunu parne paisa kati bhayo?');
    check('…so a member gets the menu at once — no "⏳", no "could not understand"', !str_contains(txt($r), '⏳') && str_contains(txt($r), 'balance')
        && microtime(true) - $t0 < 5, txt($r));
    check('…and the probe result is cached (no second network call)', ApAi::health()['ok'] === false && ApAi::health()['at'] >= time() - 5);
    // The repo keeps it in tools/; a website copy elsewhere names it with AP_MOCK (tools/test-local.sh does).
    $script = getenv('AP_MOCK') ?: (is_file(dirname(__DIR__) . '/tools/mock-ollama.php') ? dirname(__DIR__) . '/tools/mock-ollama.php' : dirname(__DIR__, 2) . '/tools/mock-ollama.php');
    if (is_file($script)) {
        $mock = proc_open(array_merge($phpArgs(), ['-S', '127.0.0.1:11555', $script]), [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $pp);
        for ($i = 0; $i < 30 && !$up(); $i++) { usleep(100000); }
    }
}
check('the mock model server answers', $up());
Settings::set('ap_ai_on', '1', 'bool', 'agentpro', false);
Settings::flush();
ApAi::resetHealth();
check('ApAi sees Ollama, its models and what they can do', ApAi::enabled() && ApAi::isOllama() && count(ApAi::models(true)) === 2 && ApAi::models()[0]['vision'] && ApAi::models()[0]['tools']);
check('health: up, the version, 2 models — cached for the next call', ApAi::health()['ok'] && ApAi::version() === '0.12.0-mock' && ApAi::health()['models'] === 2);
check('auto-pick: the preferred installed chat model', ApAi::pickModel('chat') === 'qwen3.5:4b');
check('documents: the same model when it can see (one model in RAM)', ApAi::pickModel('vision') === 'qwen3.5:4b');
$j = ApAi::json('You read messages.', 'bholi 2 seat Mehsana Ram Thapa 9812345678', ['type' => 'object', 'properties' => ['intent' => ['type' => 'string']]]);
check('structured JSON answers', ($j['intent'] ?? '') === 'book' && ($j['seats'] ?? 0) === 2);
$last = json_decode((string) (array_slice(file($mockLog) ?: [], -1)[0] ?? '{}'), true);
check('think:false is sent to a thinking model (no minute-long reasoning on CPU)', ($last['think'] ?? null) === false && ($last['format'] ?? false) === true);
check('every call is logged for the admin page', (int) Database::scalar("SELECT COUNT(*) FROM ap_ai_calls WHERE created_at > (NOW() - INTERVAL 1 MINUTE)", [], 0) >= 1);
$r = wa($A_WA, 'malai aaunu parne paisa kati bhayo?');
check('…(that sentence is not an instant command — it went to the model)', (json_decode((string) (array_slice(file($mockLog) ?: [], -1)[0] ?? '{}'), true)['path'] ?? '') === '/api/chat');
check('a sentence the desk does not know → the AI reads it → balance', str_contains(txt($r), '1,200') || str_contains(txt($r), '🟢'), txt($r));
$r = wa($A_WA, 'MOCKFAIL tapai kasto hunuhuncha');
check('model down → a polite fallback with the office number, not silence', str_contains(txt($r), Settings::officePhone()) || str_contains(txt($r), 'help'), txt($r));
$r = ApWa::customerTurn('+91' . $CUST, 'tapai ko office kaha cha?');
check('a passenger\'s question is answered by the VPS AI', str_contains(txt($r), Settings::company()['cin']) || str_contains(txt($r), 'MOCK'), txt($r));
$r = ApWa::customerTurn('+91' . $CUST, 'bus kahile aauncha bhanera bujhna man lagyo');
check('…in their language, from the facts only', str_contains(txt($r), 'MOCK'), txt($r));
check('the customer hook is on only with the AI on', ApWa::customerBrainOn());
$spec = sys_get_temp_dir() . '/apwa-id.jpg';
$im = imagecreatetruecolor(800, 500); imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240)); imagejpeg($im, $spec);
$v = ApDocRead::readWithAi($spec, 'aadhaar', 'front');
check('vision: the model reads an ID photo into fields', ($v['full_name'] ?? '') === 'Test Person Kumar' && ($v['dob'] ?? '') === '1994-03-12' && ($v['id_number'] ?? '') === '2345 6789 0124');
$last = json_decode((string) (array_slice(file($mockLog) ?: [], -1)[0] ?? '{}'), true);
check('…the photo was sent to the model (and nowhere else)', ($last['images'] ?? false) === true);
$full = ApDocRead::readAi($spec, 'aadhaar', 'front');
check('vision ladder: the VPS reader answers with a confidence per field', ($full['reader'] ?? '') === 'vps' && ($full['confidence']['full_name'] ?? '') === 'high'
    && ($full['confidence']['dob'] ?? '') === 'medium' && ($full['confidence']['gender'] ?? '') === 'medium', json_encode($full));
// the background read of an uploaded ID photo: the worker stores fields + confidence + reader on the draft
$dd = AgentPro::newDraft();
AgentPro::saveDraft($dd['draft_key'], ['full_name' => 'APWA Doc Reader']);
@mkdir(UPLOAD_PATH . '/agents-kyc', 0775, true);
$docRel = 'agents-kyc/apwa-test-' . bin2hex(random_bytes(4)) . '.jpg';
copy($spec, UPLOAD_PATH . '/' . $docRel);
$docJob = Database::insert('ap_jobs', ['phone' => 'form', 'kind' => 'doc', 'text' => json_encode(['key' => $dd['draft_key'], 'path' => $docRel, 'type' => 'aadhaar', 'side' => 'front'])]);
$out = (string) shell_exec(implode(' ', array_map('escapeshellarg', array_merge($phpArgs(), [dirname(__DIR__) . '/cron/agentpro-worker.php', '--once']))) . ' 2>&1');
$jr = Database::fetch('SELECT * FROM ap_jobs WHERE id = :i', ['i' => $docJob]);
$ro = jsonColumn(AgentPro::draft($dd['draft_key'])['readout'] ?? null);
check('worker: the ID photo job is read on the ladder and kept with confidence + reader', ($jr['status'] ?? '') === 'done'
    && ($ro['ai_front']['fields']['full_name'] ?? '') === 'Test Person Kumar' && ($ro['ai_front']['reader'] ?? '') === 'vps'
    && ($ro['ai_front']['confidence']['id_number'] ?? '') === 'high', $out . ' ' . json_encode($jr));
@unlink(UPLOAD_PATH . '/' . $docRel);
Database::delete('ap_applications', 'draft_key = :k', ['k' => $dd['draft_key']]);
// a document that cannot be read (file gone): failed at once, not retried for ever
$docJob2 = Database::insert('ap_jobs', ['phone' => 'form', 'kind' => 'doc', 'text' => json_encode(['key' => str_repeat('0', 32), 'path' => 'agents-kyc/none.jpg', 'type' => 'aadhaar', 'side' => 'front'])]);
shell_exec(implode(' ', array_map('escapeshellarg', array_merge($phpArgs(), [dirname(__DIR__) . '/cron/agentpro-worker.php', '--once']))) . ' 2>&1');
check('worker: a doc job with no file fails cleanly', (string) Database::scalar('SELECT status FROM ap_jobs WHERE id = :i', ['i' => $docJob2], '') === 'failed');
Database::run("DELETE FROM ap_jobs WHERE id IN ($docJob, $docJob2)");

// WhatsApp never waits more than ap_ai_wa_wait behind the model (another process holds it here)
Settings::set('ap_ai_wa_wait', '3', 'int', 'agentpro', false);
Settings::flush();
$holder = proc_open(array_merge($phpArgs(), ['-r', '$f = fopen(' . var_export(LOG_PATH . '/agentpro-ai.lock', true) . ', "c"); flock($f, LOCK_EX); sleep(7);']),
    [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $hp);
usleep(700000);
$t0 = microtime(true);
$busy = ApAi::chat([['role' => 'user', 'content' => 'balance']], ['purpose' => 'wa_member', 'format' => ['type' => 'object']]);
$took = microtime(true) - $t0;
check('a WhatsApp turn waits at most ap_ai_wa_wait (3 s here) for a busy model, then falls back', !$busy['ok'] && ApAi::lastBusy() && $took < 5.5, round($took, 1) . ' s');
check('…and while it waited it raised the "someone is waiting" flag a document read gives way to', ApAi::someoneWaiting());
proc_terminate($holder);
@unlink(LOG_PATH . '/agentpro-ai.want');   // the turn is over (the flag would otherwise stay fresh for 3 s)
Database::delete('settings', "skey = 'ap_ai_wa_wait'");
Settings::flush();

$b = ApAi::benchmark('qwen3.5:4b');
check('the benchmark runs the five Nepali / Hindi / English tasks', $b['of'] === 5 && $b['score'] === 5, json_encode($b['tasks']));
check('the recommendation follows RAM', ApAi::recommend(['ramGb' => 8, 'gpu' => false])['chat'] === 'qwen3.5:4b'
    && ApAi::recommend(['ramGb' => 16, 'gpu' => false])['chat'] === 'qwen3.5:9b' && ApAi::recommend(['ramGb' => 4, 'gpu' => false])['chat'] === 'qwen3.5:2b');
$r8 = ApAi::recommend(['ramGb' => 7.8, 'cpus' => 2, 'gpu' => false, 'othersGb' => 3.4, 'valhallaGb' => 1.9]);
check('…counting what MySQL, PHP and Valhalla hold: this 8 GB / 2 vCPU box still gets qwen3.5:4b, capped', $r8['chat'] === 'qwen3.5:4b'
    && $r8['measured'] && $r8['memoryMaxMb'] >= 3500 && $r8['memoryMaxMb'] <= 5200 && $r8['cpuQuota'] === 150, json_encode($r8));
$sh = ApAi::installScript($r8);
check('…with exact root commands: official install, a systemd drop-in (MemoryMax, CPUQuota, keep-alive, one model), the pull',
    str_contains($sh, 'curl -fsSL https://ollama.com/install.sh | sh') && str_contains($sh, 'MemoryMax=') && str_contains($sh, 'CPUQuota=150%')
    && str_contains($sh, 'OLLAMA_MAX_LOADED_MODELS=1') && str_contains($sh, 'OLLAMA_KEEP_ALIVE=') && str_contains($sh, 'ollama pull qwen3.5:4b'));
check('pull starts a download', ApAi::pull('qwen3.5:9b')['ok'] === true);
check('a nonsense model name is refused', ApAi::pull('rm -rf /')['ok'] === false);

// The Twilio webhook waits only 15 s: queue + "⏳" + the worker answers.
define('AP_WA_TRANSPORT', 'sync');
$r = wa($A_WA, 'malai aaunu parne paisa kati bhayo, bhannus na');
$job = Database::fetch("SELECT * FROM ap_jobs WHERE phone = :p ORDER BY id DESC LIMIT 1", ['p' => $A_WA]);
check('sync webhook: an instant "⏳" and a queued job', str_contains(txt($r), '⏳') && ($job['status'] ?? '') === 'queued');
// ApWa already kicked the worker in the background; run it here too (a no-op if it is busy) and wait.
$out = (string) shell_exec(implode(' ', array_map('escapeshellarg', array_merge($phpArgs(), [dirname(__DIR__) . '/cron/agentpro-worker.php', '--once']))) . ' 2>&1');
for ($i = 0; $i < 100; $i++) {
    $job = Database::fetch('SELECT * FROM ap_jobs WHERE id = :i', ['i' => (int) ($job['id'] ?? 0)]);
    if (($job['status'] ?? '') === 'done' || ($job['status'] ?? '') === 'failed') { break; }
    usleep(200000);
}
check('the worker answered it and sent the reply', ($job['status'] ?? '') === 'done' && str_contains((string) $job['reply'], '1,200'), $out . ' ' . json_encode($job));
check('…and records its heartbeat', (int) Database::scalar("SELECT svalue FROM settings WHERE skey = 'ap_worker_seen'", [], 0) > time() - 120);

Settings::set('ap_ai_base', 'http://127.0.0.1:11555/v1', 'string', 'agentpro', false);
Settings::flush();
check('OpenAI-compatible servers (llama.cpp, vLLM) work too', !ApAi::isOllama() && ApAi::models(true)[0]['name'] === 'mock-openai'
    && (ApAi::json('x', 'balance', ['type' => 'object'], [], ['model' => 'mock-openai'])['intent'] ?? '') === 'balance');
check('…and its health is probed on /v1/models', ApAi::health(true)['ok'] && ApAi::health()['kind'] === 'openai');

if ($mock !== null) {
    proc_terminate($mock);
    for ($i = 0; $i < 30 && $up(); $i++) { usleep(100000); }
    Settings::set('ap_ai_base', 'http://127.0.0.1:11555', 'string', 'agentpro', false);
    Settings::flush();
    check('the server goes away: a fresh probe says down and enabled() follows', !ApAi::available(true) && !ApAi::enabled());
}
ApAi::resetHealth();

/* ---- tidy up ---- */
$cleanup();
foreach ($keep as $k => $v) {
    if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); } else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
}
Settings::flush();

function ApCard_check(int $m, string $s, int $e, string $k): bool { require_once INCLUDE_PATH . '/agentpro-card.php'; return ApCard::checkSig($m, $s, $e, $k); }

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
