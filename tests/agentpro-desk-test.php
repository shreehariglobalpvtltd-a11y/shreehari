<?php
/**
 * =====================================================================
 *  agentpro-desk-test.php — the WhatsApp desk as the agents' remote
 *  control (30 Oct 2026, wa-desk).
 *
 *  Talks to ApWa::reply() as includes/wabot.php does, against the TEST
 *  database, as an agent, an office manager, a manager still on a
 *  temporary password and a passenger:
 *
 *    - the numbered menu ("3" = balance), "0"/admin for the office only
 *    - portal sign-in: the link + code, never an OTP in the reply
 *    - a ticket asked one question at a time (route, day, pickup, seats,
 *      passenger) → quote → CONFIRM sells ONCE: a second CONFIRM says
 *      "already done", two CONFIRMs at the same moment sell once
 *    - my tickets (all / one day)
 *    - an agent's cancel is a request in the Help Desk queue, nothing is
 *      cancelled or refunded
 *    - withdraw: confirm first, one open request, a repeat CONFIRM files
 *      nothing more
 *    - the office: pending, joins, withdrawals, approve (needs the bank
 *      ref, CONFIRM, pays once, audit) and reject (needs a reason); an
 *      agent or a temporary-password manager cannot
 *    - the AI (a mock Ollama started here): model down → the numbered
 *      menu; what it reads only fills questions; a model slower than the
 *      turn budget → the menu inside ~the budget; a passenger is left to
 *      the existing bot
 *
 *    php tests/agentpro-desk-test.php
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
require_once INCLUDE_PATH . '/agentpro-brain.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 140) : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 500) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function wa(string $from, string $text): ?array { return ApWa::reply('+' . $from, $text, 'text'); }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }
function pendingOf(string $phone): ?array {
    $p = json_decode((string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], ''), true);
    return is_array($p) ? $p : null;
}

if (!AgentPro::available()) {
    check('ap_* tables exist', false);
    exit(1);
}

/* ---- settings for the run (restored at the end) ---- */
$keep = [];
$set = static function (string $k, string $v, string $type = 'string') use (&$keep): void {
    if (!array_key_exists($k, $keep)) {
        $keep[$k] = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k], null);
    }
    Settings::set($k, $v, $type, 'agentpro', false);
};
foreach (['whatsapp_driver' => ['click_to_chat', 'string'], 'ap_wa_on' => ['1', 'bool'], 'ap_wa_sell' => ['1', 'bool'], 'ap_wa_admin_on' => ['1', 'bool'],
          'ap_ai_on' => ['0', 'bool'], 'ap_ai_base' => ['http://127.0.0.1:11557', 'string'], 'ap_ai_model' => ['', 'string'], 'ap_ai_ack' => ['1', 'bool'],
          'ap_wa_ai_answers' => ['0', 'bool'], 'agent_kyc_required' => ['0', 'bool'], 'daily_service_on' => ['1', 'bool'], 'ap_ai_timeout' => ['30', 'int'],
          'helpdesk_on' => ['1', 'bool'], 'helpdesk_ack' => ['0', 'bool'], 'helpdesk_office_alert' => ['0', 'bool']] as $k => [$v, $type]) {
    $set($k, $v, $type);
}
Settings::flush();

$A   = '7000000501';   // the agent (registered mobile)
$MGR = '7000000502';   // an office manager
$TMP = '7000000503';   // a manager still on a temporary password
$CUS = '7000000504';   // a passenger
$MG2 = '7000000505';   // a manager whose number only matches admins.phone (never linked with the OTP)
$PAX = '9812345681';   // a passenger the agent books for
$PAX2 = '9812345682';
$phones = [$A, $MGR, $TMP, $CUS, $MG2];
$cleanup = static function () use ($phones, $PAX, $PAX2): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone IN ('$PAX', '$PAX2') OR sold_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'apdk_%')") as $b) {
        $bid = (int) $b['id'];
        try { Seats::releaseBooking($bid); } catch (Throwable $e) {}
        try { Database::run('DELETE m FROM support_messages m JOIN support_tickets t ON t.id = m.ticket_id WHERE t.booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        foreach (['support_tickets', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'agent_ledger'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'apdk_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    foreach ($phones as $p) {
        Database::delete('ap_wa_state', 'phone = :p', ['p' => $p]);
        Database::delete('ap_wa_links', 'phone = :p', ['p' => $p]);
        Database::delete('kv_store', "kkey = :p AND kscope IN ('wa_stage','wa_ticket_fix','wa_agent','wa_booking')", ['p' => $p]);
    }
    Database::delete('ap_jobs', "phone IN ('" . implode("','", $phones) . "')");
    Database::delete('ap_applications', "full_name LIKE 'APDK %'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap\\_%' OR bucket LIKE 'wa_%'");
};
$cleanup();

$mk = static function (string $user, string $name, string $phone, string $role, int $mustChange = 0): int {
    $id = Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => $mustChange]);
    AgentWallet::saveProfile($id, ['counter_name' => 'Mehsana', 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
$agentId = $mk('apdk_agent', 'APDK Desk Agent', $A, 'agent');
AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('person'));
$code = AgentWallet::agentCodeLabel($agentId);
$mgrId = $mk('apdk_mgr', 'APDK Office Manager', $MGR, 'manager');
$tmpId = $mk('apdk_tmp', 'APDK Temp Manager', $TMP, 'manager', 1);
$mg2Id = $mk('apdk_mg2', 'APDK Unlinked Manager', $MG2, 'manager');
// the office number is linked with the OTP (what LINK + the code does): only such a number gets the office desk
Database::run("INSERT INTO ap_wa_links (phone, admin_id, via, verified_at, revoked_at) VALUES (:p, :a, 'otp', NOW(), NULL)
               ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), via = 'otp', revoked_at = NULL", ['p' => normalisePhone('+' . $MGR), 'a' => $mgrId]);

/* =================================================================== */
section('the numbered menu, the portal');
$r = wa($A, 'help');
check('help is a numbered menu with the words too', str_contains(txt($r), '1️⃣') && str_contains(txt($r), '6️⃣') && str_contains(txt($r), 'portal') && str_contains(txt($r), 'balance'), txt($r));
check('…an agent\'s menu has no office line', !str_contains(txt($r), 'admin'));
AgentWallet::record($agentId, 'commission', 900, ['note' => 'desk test']);
check('"3" alone = balance', str_contains(txt(wa($A, '3')), '₹900'));
check('"३" (Devanagari digit) works too', str_contains(txt(wa($A, '३')), '₹900'));
check('"0" for an agent is the menu, not the office desk', str_contains(txt(wa($A, '0')), '1️⃣'));
check('"admin" from an agent is refused', str_contains(txt(wa($A, 'admin')), '🔒'));
$r = wa($A, 'portal');
check('portal: the /me/ link, the agent code and a masked mobile', str_contains(txt($r), '/me/') && str_contains(txt($r), $code) && str_contains(txt($r), substr($A, -4)) && !str_contains(txt($r), $A), txt($r));
check('…and no code to type is in the reply (the OTP comes on the page\'s request)', preg_match('/\b\d{6}\b/', txt($r)) !== 1);
$r = wa($MGR, 'help');
check('a manager\'s menu has the office line', str_contains(txt($r), '0️⃣') && str_contains(txt($r), 'admin'));
check('"0" for a manager opens the office desk', str_contains(txt(wa($MGR, '0')), 'approve'));
check('a manager on a temporary password gets no office desk', str_contains(txt(wa($TMP, 'admin')), '🔒'));
check('a manager on a registered-only number (no OTP link): no office line in the menu', !str_contains(txt(wa($MG2, 'help')), '0️⃣'));
check('…and "admin" is refused', str_contains(txt(wa($MG2, 'admin')), '🔒'));
check('a passenger\'s message is left to the existing bot', wa($CUS, 'ticket chahiyo') === null);

/* =================================================================== */
section('a ticket, one question at a time');
$date = date('Y-m-d', strtotime('+3 days'));
$r = wa($A, 'ticket');
$asked = [];
$quote = '';
for ($i = 0; $i < 8; $i++) {
    $p = pendingOf($A);
    if (($p['kind'] ?? '') !== 'book') {
        $quote = txt($r);
        break;
    }
    $step = (string) $p['step'];
    $asked[] = $step;
    if ($step === 'who' && !isset($okTried)) {
        $okTried = true;
        $rOk = wa($A, 'ok');
        $pOk = pendingOf($A);
        check('an "ok" at the passenger question is no name: the question again', ($pOk['kind'] ?? '') === 'book' && ($pOk['step'] ?? '') === 'who'
            && (string) ($pOk['b']['name'] ?? '') === '' && str_contains(txt($rOk), '🤔'), txt($rOk));
        $rOk = wa($A, 'CONFIRM');
        check('…a CONFIRM there neither names nor sells', (pendingOf($A)['step'] ?? '') === 'who'
            && (string) (pendingOf($A)['b']['name'] ?? '') === '' && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAX]), txt($rOk));
    }
    $ans = match ($step) {
        'direction' => (string) (array_search('toNepal', (array) $p['opts'], true) + 1),
        'date'      => $date,
        'pickup'    => 'Surat',
        'seats'     => '2',
        'who'       => 'Ram Thapa ' . $PAX,
        default     => 'help',
    };
    $r = wa($A, $ans);
}
check('the desk asked day, pickup, seats and passenger in turn', in_array('date', $asked, true) && in_array('pickup', $asked, true)
    && in_array('seats', $asked, true) && in_array('who', $asked, true), implode(' → ', $asked));
check('…then QUOTED (nothing booked yet) and asks for CONFIRM', str_contains($quote, '₹') && str_contains($quote, 'CONFIRM')
    && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAX]), $quote);
check('…with what was answered', str_contains($quote, 'Ram Thapa') && str_contains($quote, 'Surat') && str_contains($quote, '2'), $quote);
$r = wa($A, 'CONFIRM');
$n = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $PAX], 0);
$bk = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PAX]);
$pnr = (string) ($bk['pnr'] ?? '');
check('CONFIRM sells it on the agent\'s code', $n === 1 && (int) ($bk['sold_by_admin_id'] ?? 0) === $agentId && str_contains(txt($r), $pnr), txt($r));
check('…the ticket picture and the PDF link come back', ($r['media'] ?? null) !== null && str_contains(txt($r), 'PDF'), (string) ($r['media'] ?? ''));
check('…and the sale is in the audit log', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_sale' AND entity_id = :p", ['p' => $pnr]));
$r = wa($A, 'CONFIRM');
check('a second CONFIRM says "already done" with the same PNR — no second sale', str_contains(txt($r), 'ℹ️') && str_contains(txt($r), $pnr)
    && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $PAX], 0) === 1, txt($r));
check('"no" after it is done undoes nothing', str_contains(txt(wa($A, 'no')), 'ℹ️') && (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $bk['id']], '') === 'confirmed');
check('a lone CONFIRM with nothing pending does nothing', str_contains(txt(wa($A, 'CONFIRM')), 'ℹ️'));
// the wizard can be left at any question
wa($A, 'ticket');
check('"no" in the middle of the questions drops the booking', str_contains(txt(wa($A, 'no')), '👍') && pendingOf($A) === null);

// two CONFIRMs at the same moment (a double tap, a webhook retry): one sale
$r = wa($A, "1 seat $date Surat, Hari Thapa $PAX2");
check('a one-line sale is quoted', str_contains(txt($r), 'CONFIRM') && (pendingOf($A)['kind'] ?? '') === 'sale', txt($r));
$phpArgs = php_ini_loaded_file() ? [PHP_BINARY, '-c', (string) php_ini_loaded_file()] : [PHP_BINARY];
$child = sys_get_temp_dir() . '/apdk-confirm-' . getmypid() . '.php';
file_put_contents($child, '<?php declare(strict_types=1); require ' . var_export(dirname(__DIR__) . '/includes/bootstrap.php', true) . ';'
    . ' require_once INCLUDE_PATH . "/agentpro-wa.php"; $r = ApWa::reply("+" . $argv[1], "CONFIRM", "text"); echo json_encode($r, JSON_UNESCAPED_UNICODE);');
$procs = [];
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
for ($i = 0; $i < 2; $i++) {
    $procs[] = proc_open(array_merge($phpArgs, [$child, $A]), [1 => ['pipe', 'w'], 2 => ['file', $devNull, 'w']], $pp[$i]);
}
$outs = [];
foreach ($procs as $i => $p) {
    $outs[] = (string) stream_get_contents($pp[$i][1]);
    proc_close($p);
}
@unlink($child);
$n2 = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $PAX2], 0);
check('two CONFIRMs at the same moment sell ONE ticket', $n2 === 1, $n2 . ' · ' . implode(' | ', array_map(static fn($o) => mb_substr($o, 0, 90), $outs)));

/* =================================================================== */
section('my tickets');
$r = wa($A, 'tickets');
check('"tickets" lists the agent\'s own sales', str_contains(txt($r), $pnr), txt($r));
check('"tickets <day>" lists the ones travelling that day', str_contains(txt(wa($A, 'tickets ' . $date)), $pnr));
check('"2" alone = my tickets', str_contains(txt(wa($A, '2')), $pnr));

/* =================================================================== */
section('a cancel from an agent is a request to the office');
$r = wa($A, "$pnr cancel");
check('the refund figure by the policy, and that the office does it', str_contains(txt($r), '₹') && str_contains(txt($r), 'CONFIRM'), txt($r));
$r = wa($A, 'CONFIRM');
check('CONFIRM opens a Help Desk ticket — nothing is cancelled or refunded', str_contains(txt($r), 'HD-')
    && (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $bk['id']], '') === 'confirmed'
    && (string) Database::scalar('SELECT refund_status FROM bookings WHERE id = :i', ['i' => (int) $bk['id']], 'none') !== 'pending'
    && Database::exists("SELECT 1 FROM support_tickets WHERE booking_id = :b AND category = 'refund'", ['b' => (int) $bk['id']]), txt($r));
check('…and the request is in the audit log', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_office_request' AND entity_id = :p", ['p' => $pnr]));
$hd = (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE booking_id = :b', ['b' => (int) $bk['id']], 0);
wa($A, 'CONFIRM');
check('a second CONFIRM opens no second ticket', (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE booking_id = :b', ['b' => (int) $bk['id']], 0) === $hd);

/* =================================================================== */
section('withdraw');
$due = AgentWallet::balances($agentId)['commission'];
$r = wa($A, 'withdraw 300');
check('withdraw asks first — nothing requested yet', str_contains(txt($r), '₹300') && AgentWallet::openPayoutRequests($agentId) === [], txt($r));
$r = wa($A, 'CONFIRM');
$open = AgentWallet::openPayoutRequests($agentId);
check('CONFIRM files ONE request with the office', count($open) === 1 && (float) $open[0]['amount'] === 300.0 && str_contains(txt($r), '✅'), txt($r));
check('…in the audit log', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_withdraw' AND entity_id = :i", ['i' => (string) $agentId]));
wa($A, 'CONFIRM');
check('a repeated CONFIRM files nothing more', count(AgentWallet::openPayoutRequests($agentId)) === 1);
check('a second withdraw while one is open is refused', str_contains(txt(wa($A, 'withdraw 100')), '⏳'));
$reqId = (int) $open[0]['id'];

/* =================================================================== */
section('the office on WhatsApp');
$d = AgentPro::newDraft();
AgentPro::saveDraft($d['draft_key'], ['full_name' => 'APDK Applicant Sita']);
Database::update('ap_applications', ['status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s')], 'draft_key = :k', ['k' => $d['draft_key']]);
$r = wa($MGR, 'pending');
check('"pending": applications, withdrawals, payments to check', str_contains(txt($r), '📝') && str_contains(txt($r), '💸'), txt($r));
$r = wa($MGR, 'joins');
check('"joins" lists the application waiting, with the approvals link', str_contains(txt($r), $d['ref']) && str_contains(txt($r), 'APDK Applicant Sita') && str_contains(txt($r), 'team.php'), txt($r));
$r = wa($MGR, 'withdrawals');
check('"withdrawals" lists the open request with the amount', str_contains(txt($r), '#' . $reqId) && str_contains(txt($r), '₹300'), txt($r));
check('the office "today" is the whole company', str_contains(txt(wa($MGR, 'today')), '📊'));
$r = wa($A, "approve $reqId UTR430987651234");
check('an agent cannot approve', str_contains(txt($r), '🔒') && count(AgentWallet::openPayoutRequests($agentId)) === 1);
check('a temporary-password manager cannot approve', str_contains(txt(wa($TMP, "approve $reqId UTR430987651234")), '🔒'));
check('a registered-only (not OTP-linked) manager cannot approve, nor list withdrawals or applicants', str_contains(txt(wa($MG2, "approve $reqId UTR430987651234")), '🔒')
    && str_contains(txt(wa($MG2, 'pending')), '🔒') && !str_contains(txt(wa($MG2, 'withdrawals')), '#' . $reqId)
    && !str_contains(txt(wa($MG2, 'joins')), $d['ref']) && count(AgentWallet::openPayoutRequests($agentId)) === 1);
$r = wa($MGR, "approve $reqId");
check('approve without the bank reference asks for it', str_contains(txt($r), 'UTR') && count(AgentWallet::openPayoutRequests($agentId)) === 1, txt($r));
$r = wa($MGR, "approve $reqId UTR430987651234");
check('approve with the ref: a preview, nothing paid yet', str_contains(txt($r), '₹300') && str_contains(txt($r), 'CONFIRM') && count(AgentWallet::openPayoutRequests($agentId)) === 1, txt($r));
$r = wa($MGR, 'CONFIRM');
$req = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i', ['i' => $reqId]);
$led = Database::fetch("SELECT * FROM agent_ledger WHERE id = :i AND entry_type = 'payout'", ['i' => (int) ($req['ledger_id'] ?? 0)]);
check('CONFIRM records the payout: request paid, ledger payout with the ref', ($req['status'] ?? '') === 'paid' && $led !== null
    && (string) $led['ref'] === 'UTR430987651234' && (int) $req['decided_by'] === $mgrId, txt($r) . ' · ref ' . (string) ($led['ref'] ?? ''));
check('…the agent\'s commission due went down by 300', abs(AgentWallet::balances($agentId)['commission'] - ($due - 300)) < 0.01);
check('…audited as a WhatsApp payout', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_payout_paid' AND entity_id = :i", ['i' => (string) $agentId]));
check('…and the agent was told', (bool) Database::fetch("SELECT id FROM message_logs WHERE to_number LIKE :p AND body LIKE '%paid to you%' ORDER BY id DESC LIMIT 1", ['p' => '%' . $A . '%']));
$r = wa($MGR, 'CONFIRM');
check('a second CONFIRM pays nothing more', str_contains(txt($r), 'ℹ️')
    && (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'payout'", ['a' => $agentId], 0) === 1, txt($r));
check('approving a paid request again is refused', str_contains(txt(wa($MGR, "approve $reqId UTR999999999")), '⚠️'));
// reject
wa($A, 'withdraw 200');
wa($A, 'CONFIRM');
$open2 = AgentWallet::openPayoutRequests($agentId);
$req2 = (int) ($open2[0]['id'] ?? 0);
check('a second request is open', $req2 > 0 && $req2 !== $reqId);
check('reject without a reason asks for it', str_contains(txt(wa($MGR, "reject $req2")), '✍️'));
$r = wa($MGR, "reject $req2 bank details do not match");
check('reject: a preview first', str_contains(txt($r), 'CONFIRM') && count(AgentWallet::openPayoutRequests($agentId)) === 1, txt($r));
$r = wa($MGR, 'CONFIRM');
check('CONFIRM declines it with the reason, audited', (string) Database::scalar('SELECT status FROM agent_payout_requests WHERE id = :i', ['i' => $req2], '') === 'declined'
    && Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_payout_declined' AND entity_id = :i", ['i' => (string) $req2]), txt($r));
// one bank transfer cannot pay a second request, however the reference is spelt
wa($A, 'withdraw 100');
wa($A, 'CONFIRM');
$req3 = (int) (AgentWallet::openPayoutRequests($agentId)[0]['id'] ?? 0);
foreach (['430987651234', 'utr 430987651234', 'UTR-4309-8765-1234'] as $spelt) {
    $r = wa($MGR, "approve $req3 $spelt");
    check('the same bank ref spelt "' . $spelt . '" cannot pay a second request', $req3 > 0 && str_contains(txt($r), '⚠️') && (pendingOf($MGR)['kind'] ?? '') !== 'wd_pay', txt($r));
}
$r = wa($MGR, "approve $req3 utr: 777012345678");
check('a spelled-out label ("utr: …") is dropped, the number kept', str_contains(txt($r), 'Ref: 777012345678') && (pendingOf($MGR)['kind'] ?? '') === 'wd_pay', txt($r));
wa($MGR, 'no');

/* =================================================================== */
section('payments members made: the office confirms or declines on WhatsApp');
AgentWallet::record($agentId, 'cash_due', 2000, ['note' => 'desk test cash']);
$held0 = AgentWallet::balances($agentId)['cash'];
$utrA = (string) random_int(400000000000, 899999999999);   // UTRs, never shaped like a +91 mobile
$utrB = (string) random_int(400000000000, 899999999999);
// 31 Oct 2026 round 2: a member's pay-in is a preview; their "ho" files it
wa($A, 'jama 500 UTR ' . $utrA);
wa($A, 'ho');
wa($A, 'jama 300 UTR ' . $utrB);
wa($A, 'ho');
$deps = AgentPro::openDeposits($agentId);
$depA = (int) ($deps[0]['id'] ?? 0);
$depB = (int) ($deps[1]['id'] ?? 0);
check('two payments wait for the office', count($deps) === 2 && $depA > 0 && $depB > 0, json_encode(array_column($deps, 'purpose')));
check('an agent cannot confirm a payment', str_contains(txt(wa($A, "jama ok $depA")), '🔒'));
check('a registered-only manager cannot confirm a payment', str_contains(txt(wa($MG2, "jama ok $depA")), '🔒') && count(AgentPro::openDeposits($agentId)) === 2);
$r = wa($MGR, 'deposits');
check('"deposits" lists both with the UTR and the amount', str_contains(txt($r), '#' . $depA) && str_contains(txt($r), $utrA) && str_contains(txt($r), '₹500'), txt($r));
check('the office menu shows the deposit commands', str_contains(txt(wa($MGR, 'admin')), 'jama ok'));
$r = wa($MGR, "jama ok $depA");
check('jama ok: a preview first, nothing written', str_contains(txt($r), 'CONFIRM') && str_contains(txt($r), '₹500') && count(AgentPro::openDeposits($agentId)) === 2, txt($r));
$r = wa($MGR, 'CONFIRM');
$rowA = Database::fetch('SELECT * FROM ap_deposit_requests WHERE id = :i', ['i' => $depA]);
check('CONFIRM: the payment is confirmed, cash handed over in the ledger, by the manager', ($rowA['status'] ?? '') === 'confirmed' && (int) $rowA['decided_by'] === $mgrId
    && (int) $rowA['ledger_id'] > 0 && Database::exists("SELECT 1 FROM agent_ledger WHERE id = :i AND entry_type = 'cash_handover'", ['i' => (int) $rowA['ledger_id']]), txt($r));
check('…the cash held went down by 500', abs(AgentWallet::balances($agentId)['cash'] - ($held0 - 500)) < 0.01);
check('…audited as a WhatsApp decision', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_deposit_confirmed' AND entity_id = :i", ['i' => (string) $agentId]));
$r = wa($MGR, 'CONFIRM');
check('a second CONFIRM writes nothing more', str_contains(txt($r), 'ℹ️')
    && (int) Database::scalar("SELECT COUNT(*) FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'cash_handover'", ['a' => $agentId], 0) === 1, txt($r));
check('confirming it again is refused', str_contains(txt(wa($MGR, "jama ok $depA")), '⚠️'));
check('jama no without a reason asks for it', str_contains(txt(wa($MGR, "jama no $depB")), '✍️'));
$r = wa($MGR, "jama no $depB not found in the bank");
check('jama no: a preview first', str_contains(txt($r), 'CONFIRM') && count(AgentPro::openDeposits($agentId)) === 1, txt($r));
$r = wa($MGR, 'CONFIRM');
check('CONFIRM declines it with the reason, no ledger entry, audited', (string) Database::scalar('SELECT status FROM ap_deposit_requests WHERE id = :i', ['i' => $depB], '') === 'declined'
    && (string) Database::scalar('SELECT decided_note FROM ap_deposit_requests WHERE id = :i', ['i' => $depB], '') === 'not found in the bank'
    && Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_deposit_declined' AND entity_id = :i", ['i' => (string) $agentId]), txt($r));

/* =================================================================== */
section('the AI on the VPS only reads intent (mock Ollama)');
$port = 11557;
$up = static fn(int $p): bool => @file_get_contents('http://127.0.0.1:' . $p . '/api/version') !== false;
$mockScript = getenv('AP_MOCK') ?: dirname(__DIR__) . '/tools/mock-ollama.php';
$mock = null;
if (!$up($port) && is_file($mockScript)) {
    $mock = proc_open(array_merge($phpArgs, ['-S', '127.0.0.1:' . $port, $mockScript]), [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $mp);
    for ($i = 0; $i < 40 && !$up($port); $i++) { usleep(100000); }
}
check('the mock model server answers', $up($port));
$set('ap_ai_on', '1', 'bool');
Settings::flush();
ApAi::resetHealth();
check('ApAi is enabled on the mock', ApAi::enabled());
// a Meta / Gupshup webhook (answer already flushed): "⏳" goes first, then the answer
define('AP_WA_TRANSPORT', 'flushed');
$before = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
$r = wa($A, 'MOCKFAIL tapai le ke ke garna sakchha');
check('model down → "could not understand" + the office number + the numbered menu', str_contains(txt($r), Settings::officePhone()) && str_contains(txt($r), '1️⃣'), txt($r));
check('…after a "⏳" ack on WhatsApp while the model was asked', Database::exists("SELECT 1 FROM message_logs WHERE id > :i AND to_number LIKE :p AND body LIKE '%⏳%'",
    ['i' => $before, 'p' => '%' . $A . '%']));
$r = wa($A, 'malai aaunu parne paisa kati bhayo?');
check('a sentence the desk does not know → the AI reads "balance" → the balance handler', str_contains(txt($r), '🟢'), txt($r));
$r = wa($A, 'kasari chalaune yo sabai, bujhina');
check('a model "question" answer does not reach a member (ap_wa_ai_answers off): the menu', !str_contains(txt($r), 'MOCK') && str_contains(txt($r), '1️⃣'), txt($r));
$ctx = ['role' => 'staff', 'admin' => ApWa::memberFor($A), 'adminId' => $agentId, 'scopeAdminId' => $agentId, 'name' => 'APDK Desk Agent',
        'phone' => $A, 'channel' => 'whatsapp', 'messageText' => 'x', 'raw_text' => 'x', 'turn' => 1, 't0' => microtime(true), 'office' => false];
$before = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId], 0);
$r = ApWa::act(['intent' => 'book', 'seats' => 2, 'date' => date('Y-m-d', strtotime('+2 days'))], ApWa::memberFor($A), $ctx, 'en');
check('what the model read as a booking only fills the questions — the next one is asked, nothing sold',
    (pendingOf($A)['kind'] ?? '') === 'book' && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId], 0) === $before
    && str_contains(txt($r), '(*no* to stop)') && str_contains(txt($r), '💺 2'), txt($r));
wa($A, 'no');
$r = ApWa::act(['intent' => 'pending'], ApWa::memberFor($A), $ctx, 'en');
check('an AI "pending" from an agent is the menu, not the office list', str_contains(txt($r), '1️⃣') && !str_contains(txt($r), 'Applications'));
check('the turn budget leaves the model at most budget − lock wait', ApBrain::timeoutFor(microtime(true), 15) <= 12 && ApBrain::timeoutFor(microtime(true) - 100, 60) === 8);
$fits = true;
foreach ([30, 45, 60, 120] as $bud) {
    $to = ApBrain::timeoutFor(microtime(true), $bud);
    $fits = $fits && $to + min($to, ApAi::waWait()) <= $bud;     // ApAi::chat waits min(timeout, waWait) for the lock
}
check('…the lock wait + the model timeout fit inside the budget (waWait ' . ApAi::waWait() . ' s)', $fits);
check('a passenger is still left to the existing bot', wa($CUS, 'kati baje bus cha?') === null);
if ($mock !== null) {
    proc_terminate($mock);
}

// a model slower than the turn: the reply comes inside ~the budget, with the menu
$slowPort = 11558;
$router = sys_get_temp_dir() . '/apdk-slow-' . getmypid() . '.php';
file_put_contents($router, '<?php if (str_ends_with((string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH), "/api/chat")) { sleep(40); } require '
    . var_export($mockScript, true) . ';');
$slow = proc_open(array_merge($phpArgs, ['-S', '127.0.0.1:' . $slowPort, $router]), [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $sp);
for ($i = 0; $i < 40 && !$up($slowPort); $i++) { usleep(100000); }
$set('ap_ai_base', 'http://127.0.0.1:' . $slowPort, 'string');
$set('ap_wa_turn_budget', '15', 'int');
$set('ap_ai_wa_wait', '3', 'int');
Settings::flush();
ApAi::resetHealth();
check('the slow mock is up and the AI is enabled on it', $up($slowPort) && ApAi::enabled());
$t0 = microtime(true);
$r = wa($A, 'yo kura ali lamo cha, bujhera bhannu hai');
$took = microtime(true) - $t0;
check('a model slower than the 15 s budget → the menu in about the budget, never a guess', $took < 20 && str_contains(txt($r), '1️⃣'), round($took, 1) . ' s · ' . mb_substr(txt($r), 0, 80));
proc_terminate($slow);
@unlink($router);
ApAi::resetHealth();

/* ---- tidy up ---- */
$cleanup();
Database::delete('ap_applications', 'draft_key = :k', ['k' => $d['draft_key']]);
foreach ($keep as $k => $v) {
    if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); } else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
}
Settings::flush();

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
