<?php
/**
 * =====================================================================
 *  wa-desk-sim-test.php — the 1:1 WhatsApp desk, as four persona
 *  simulators found it on 30-31 Oct 2026 (owner: "jhe bhane ni tehi
 *  anusar reply; galat cha bhane galat bhanne; staff, customer, agent,
 *  admin — sabai lai ramro sita manage").
 *
 *  Every check is one of the failures those simulators reported, fixed:
 *    passenger (WaBot::reply)  fare / time questions answered (never a desk
 *        draft), a wrong fare / route corrected, "ticket pheri pathaidinu",
 *        complaints to the Help Desk, "dhanyabad", the Nepal office, personal
 *        numbers, "Jodhpur…" / "register…" not taken for LINK, "Tciket" never
 *        a name, "UPI bata tirchu" = UPI, a typed UTR to the verify queue, the
 *        berth label the same on the summary and the confirmation
 *    desk (ApWa::reply)  "nahi" / "pardaina" / "mat karo" cancel a quote, a
 *        day word re-plans it, a sentence never becomes the passenger, a quote
 *        is dropped when another command runs, sentences read as commands
 *        (balance, tickets, withdraw 300, pay-in, cards, portal, numbers,
 *        pending, report), bank / ID numbers refused, a counter's own sales,
 *        "SHG-0042 ko lagi" credited to that agent, "company ko …" is a sale,
 *        masked bank accounts, one-token payout references, PNR questions
 *        answered, printed seat labels, a lone "ho" says nothing is waiting
 *
 *  Safety rules checked on the way: money / ticket actions still wait for a
 *  later "ho"; a passenger never sees someone else's booking.
 *
 *  Round 3 — the owner's rules, each with many phrasings (Nepali, Hindi,
 *  English, romanised, Devanagari, typos): R1 a "no" / "not now" drops what
 *  waits for "ho" (TicketBot::holdOrNo); R2 a payment line never reaches a
 *  booking or a sale (WaPayProof::payTalk / answerPayTalk); R3 an office
 *  sentence naming a member goes to that member (ApWa::moneyRoute); R4 a
 *  complaint ticket needs a meant complaint (WaLocal::complaintKind with
 *  praise / question / negation); R5 no other booking's PNR (QuickTicket).
 *
 *  Nothing leaves this machine: the Meta constants are empty, the Meta
 *  transport is captured, the WhatsApp driver is click-to-chat, the AI is off
 *  — all settings in memory only (never written). Throwaway members and
 *  bookings are deleted at the end.
 *
 *    php tests/wa-desk-sim-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

foreach (['META_ACCESS_TOKEN' => '', 'META_PHONE_NUMBER_ID' => '', 'META_APP_SECRET' => '', 'META_WABA_ID' => '',
          'WHATSAPP_WEBHOOK_TOKEN' => '', 'META_API_BASE' => 'http://127.0.0.1:9', 'META_API_VERSION' => 'v0'] as $k => $v) {
    define($k, $v);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false && !getenv('SHG_ALLOW_TEST_ON_DB')) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
foreach (['fare', 'seats', 'qr', 'pdf', 'ticket', 'booking', 'notify', 'agentpro', 'agentpro-wa', 'agentwallet', 'ticketbot', 'quickticket',
          'wabooking', 'wabot', 'waroute', 'waagentroute'] as $f) {
    require_once INCLUDE_PATH . '/' . $f . '.php';
}

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 400)) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }

/* ---- nothing leaves: the Meta transport is captured ---- */
$SENT = [];
WaRoute::$transport = static function (string $to, string $text, ?string $img = null) use (&$SENT): array {
    $SENT[] = $to;
    return ['success' => true, 'message_id' => 'wamid.TEST' . count($SENT), 'error' => ''];
};
WaAgentRoute::$transport = static function (string $to, string $text, ?string $img = null, ?array $ui = null) use (&$SENT): array {
    $SENT[] = $to;
    return ['success' => true, 'message_id' => 'wamid.TEST' . count($SENT), 'error' => ''];
};

/* ---- settings, in memory only (re-applied before every message) ---- */
$OVR = ['whatsapp_driver' => 'click_to_chat', 'wa_cloud_fallback_on' => '0', 'aisensy_api_key' => '', 'wa_meta_templates_on' => '0',
    'email_enabled' => '0', 'push_enabled' => '0', 'staffmsg_push_on' => '0', 'sms_enabled' => '0', 'sms_send_otp' => '0',
    'wa_agent_on' => '0', 'wa_ai_enabled' => '0', 'ap_ai_on' => '0', 'ai_manager_enabled' => '0', 'ai_manager_whatsapp' => '0',
    'gemini_api_key' => '', 'anthropic_api_key' => '', 'openai_api_key' => '', 'grok_api_key' => '', 'openrouter_api_key' => '', 'cerebras_api_key' => '',
    'wa_faq_on' => '0', 'ap_wa_on' => '1', 'ap_wa_sell' => '1', 'ap_wa_admin_on' => '1', 'ap_wa_dir_agents' => '0', 'agent_kyc_required' => '0',
    'helpdesk_on' => '1', 'helpdesk_ack' => '0', 'helpdesk_office_alert' => '0', 'helpdesk_autoassign' => '0', 'ap_learn_fast' => '0'];
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
applyOverrides();
/** A desk member's message (ApWa first, as WaBot does). */
function desk(string $from, string $text): ?array { applyOverrides(); return ApWa::reply('+91' . $from, $text, 'text'); }
/** A passenger's message through the whole bot. */
function pax(string $from, string $text): array { applyOverrides(); return WaBot::reply('+91' . $from, $text, 'text', []); }
function pendingOf(string $phone): ?array {
    $p = json_decode((string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], ''), true);
    return is_array($p) ? $p : null;
}

if (!AgentPro::available()) {
    check('ap_* tables exist', false);
    exit(1);
}

$AG  = '7000000711';   // an agent
$CT  = '7000000712';   // a counter
$MGR = '7000000713';   // an office manager (OTP-linked)
$CRD = '7000000714';   // an agent a counter sells FOR
$ST  = '7000000715';   // another staff member
$P = array_map(static fn(int $i): string => '93274000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 95));   // round 4: 41-95 for its checks
$PAXN = ['9812349991', '9812349992', '9812349993', '9812349994', '9812349995', '9812349996', '9812349997',
         // round 3: fresh passenger numbers for the R1–R5 checks
         '9812349981', '9812349982', '9812349983', '9812349984', '9812349985', '9812349986', '9812349987', '9812349988',
         // round 4: the passengers the desk sells to in its checks (indexes 15-29)
         '9812349961', '9812349962', '9812349963', '9812349964', '9812349965', '9812349966', '9812349967', '9812349968', '9812349969',
         '9812349970', '9812349971', '9812349972', '9812349973', '9812349974', '9812349975'];
// post-deploy probes A + B (31 Oct 2026): fresh passenger chats and desk-sale passenger numbers for their checks
$Q  = array_map(static fn(int $i): string => '93274100' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 99));
$QS = array_map(static fn(int $i): string => '98123498' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 60));
// usability leftovers a-g (31 Oct 2026): fresh passenger chats and desk-sale passenger numbers for their checks
$L  = array_map(static fn(int $i): string => '93274200' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 90));
$LS = array_map(static fn(int $i): string => '98123497' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 60));
$members = [$AG, $CT, $MGR, $CRD, $ST];
$cleanup = static function () use ($members, $P, $PAXN, $Q, $QS, $L, $LS): void {
    $phones = array_merge($P, $PAXN, $Q, $QS, $L, $LS);
    $in = "'" . implode("','", $phones) . "'";
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone IN ($in) OR sold_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'apws_%')
                                  OR booked_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'apws_%')") as $b) {
        $bid = (int) $b['id'];
        try { Seats::releaseBooking($bid); } catch (Throwable $e) {}
        try { Database::run('DELETE m FROM support_messages m JOIN support_tickets t ON t.id = m.ticket_id WHERE t.booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        foreach (Database::fetchAll("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'booking_id' AND TABLE_NAME <> 'bookings'") as $r) {
            try { Database::run('DELETE FROM `' . $r['t'] . '` WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    foreach ($phones as $p) {
        try { Database::run('DELETE m FROM support_messages m JOIN support_tickets t ON t.id = m.ticket_id WHERE t.phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM brain_drafts WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        Database::run('DELETE FROM kv_store WHERE kkey LIKE :p', ['p' => '%' . $p . '%']);
    }
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'apws_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    foreach ($members as $p) {
        Database::delete('ap_wa_state', 'phone = :p', ['p' => $p]);
        Database::delete('ap_wa_links', 'phone = :p', ['p' => $p]);
        Database::run('DELETE FROM kv_store WHERE kkey LIKE :p', ['p' => '%' . $p . '%']);
    }
    Database::run("DELETE FROM rate_limits WHERE identifier IN ($in) OR identifier IN ('" . implode("','", $members) . "')");
};
$cleanup();

$mk = static function (string $user, string $name, string $phone, string $role): int {
    $id = Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    AgentWallet::saveProfile($id, ['counter_name' => 'Mehsana', 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
try {
    $agId = $mk('apws_agent', 'APWS Bikash Agent', $AG, 'agent');
    AgentWallet::setAgentCode($agId, AgentWallet::nextFreeAgentCode('person'));
    $crdId = $mk('apws_credit', 'APWS Kamal Creditagent', $CRD, 'agent');
    AgentWallet::setAgentCode($crdId, AgentWallet::nextFreeAgentCode('person'));
    $crdCode = AgentWallet::agentCodeLabel($crdId);
    $ctId = $mk('apws_counter', 'APWS Sunita Counter', $CT, 'counter');
    $mgrId = $mk('apws_mgr', 'APWS Office Manager', $MGR, 'manager');
    $stId = $mk('apws_staff', 'APWS Sunilkumar Yadav', $ST, 'counter');
    Database::run("INSERT INTO ap_wa_links (phone, admin_id, via, verified_at, revoked_at) VALUES (:p, :a, 'otp', NOW(), NULL)
                   ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), via = 'otp', revoked_at = NULL", ['p' => $MGR, 'a' => $mgrId]);
    $office = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $mgrId]);
    $TOM = date('Y-m-d', strtotime('+1 day'));
    $PARSI = date('Y-m-d', strtotime('+2 day'));

    /* ================================================================= */
    section('passenger: questions are answered, never parked as a booking draft');
    $drafts = static fn(string $p): int => (int) Database::scalar('SELECT COUNT(*) FROM brain_drafts WHERE phone LIKE :p', ['p' => '%' . $p], 0);
    $r = pax($P[0], 'Surat to Rupaidiha bhada kati ho?');
    check('"bhada kati ho?" gets the fare (₹), not "the desk will confirm"', str_contains(txt($r), '₹') && !str_contains(txt($r), 'अनुरोध प्राप्त भयो') && $drafts($P[0]) === 0, txt($r));
    $r = pax($P[1], 'kal ki bus kitne baje hai Surat se');
    check('"kitne baje" gets the time, in Hindi', preg_match('/\d:\d\d (AM|PM)/', txt($r)) === 1 && str_contains(txt($r), 'बस') && $drafts($P[1]) === 0, txt($r));
    $r = pax($P[2], 'Mehsana dekhi Rupaidiha samma 2 jana ko kati lagcha?');
    check('a fare for 2 people is the total for 2', str_contains(txt($r), '2 जनाको') && $drafts($P[2]) === 0, txt($r));
    $r = pax($P[3], 'bhada ta 1500 matra ho ni Surat bata?');
    check('a wrong fare is corrected ("₹1,500 होइन")', str_contains(txt($r), '1,500') && str_contains(txt($r), 'होइन'), txt($r));
    $r = pax($P[4], 'tapai ko bus ta Kathmandu samma janchha hoina?');
    check('"Kathmandu samma janchha?" — no, our route is Gujarat ↔ Rupaidiha', str_contains(txt($r), 'Kathmandu') && str_contains(txt($r), 'होइन') && $drafts($P[4]) === 0, txt($r));
    $r = pax($P[5], 'Jodhpur bata bus cha?');
    check('"Jodhpur bata…" is not a LINK command', !str_contains(txt($r), 'LINK') && str_contains(txt($r), 'Jodhpur'), txt($r));
    $r = pax($P[6], 'register garnu paryo ticket ko lagi');
    check('"register garnu paryo" is not a LINK command, and never a passenger "Register"', !str_contains(txt($r), 'LINK') && !str_contains(txt($r), 'Register'), txt($r));
    $r = pax($P[7], 'Nepalgunj ma office cha?');
    check('"Nepalgunj ma office cha?" gets the Nepal-side office', str_contains(txt($r), '🇳🇵'), txt($r));
    $r = pax($P[7], 'Hari sir ko personal number dinus na');
    check('a personal number is politely refused, with the office line', str_contains(txt($r), Settings::officePhone()) && str_contains(txt($r), 'निजी'), txt($r));
    $r = pax($P[8], 'Tciket book');
    check('"Tciket book" asks the details — never a passenger called "Tciket"', !str_contains(txt($r), 'Tciket'), txt($r));

    /* ================================================================= */
    section('passenger with a ticket: resend, complaints, thanks');
    $seed = QuickTicket::sell(['name' => 'Ram Thapa', 'phone' => $P[9], 'date' => $TOM, 'direction' => 'toNepal', 'boarding' => 'Surat', 'pay' => 'cash'], $office);
    $pnrA = (string) $seed['pnr'];
    $r = pax($P[9], 'mero ticket pheri pathaidinu na');
    check('"ticket pheri pathaidinu" sends the ticket picture again', !empty($r['media']) && str_contains(txt($r), $pnrA) && $drafts($P[9]) === 0, txt($r));
    $r = pax($P[9], 'bus ko driver le dherai rude bolyo, complain garnu cha');
    check('a rude-driver complaint: sorry + a Help Desk ticket (staff), not the bus card', str_contains(txt($r), 'HD-')
        && Database::exists("SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = 'staff'", ['p' => '%' . $P[9]]), txt($r));
    $r = pax($P[9], 'staff le 500 rupaya badi liyo, ticket ma 2200 lekheko cha');
    check('an overcharge is a payment complaint for the office, not the "this booking / another" chooser', str_contains(txt($r), 'HD-') && !str_contains(txt($r), 'अर्को बुक')
        && Database::exists("SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = 'payment'", ['p' => '%' . $P[9]]), txt($r));
    $r = pax($P[10], 'AC chalena bus ma, paisa firta chahiyo');
    check('"AC chalena, paisa firta chahiyo" is a complaint, not the cancellation slabs', str_contains(txt($r), 'HD-') && !str_contains(txt($r), '48+'), txt($r));
    $r = pax($P[9], 'dhanyabad');
    check('"dhanyabad" gets a short thank-you', str_contains(txt($r), 'धन्यवाद') && mb_strlen(txt($r)) < 200, txt($r));
    $r = pax($P[11], $pnrA);
    check('a stranger with that PNR still sees only the status', !str_contains(txt($r), 'Ram Thapa'), txt($r));

    /* ================================================================= */
    section('passenger: a booking — the typed number, "UPI bata tirchu", the typed UTR, the berth label');
    pax($P[12], 'Tciket book garnu cha bholi 2 jana Surat bata');
    $sum = txt(pax($P[12], 'Ram Thapa ra Sita Thapa, phone ' . $PAXN[4]));
    check('the number typed beside the names is shown as where the ticket goes', str_contains($sum, $PAXN[4]), $sum);
    $r = pax($P[12], 'UPI bata tirchu');
    $bk = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PAXN[4]]);
    check('"UPI bata tirchu" is the UPI choice: a PENDING booking with the pay link (never sold as cash)', $bk !== null && (string) $bk['status'] === 'pending'
        && str_contains(txt($r), 'pay.php'), txt($r));
    preg_match('/(?:सिट|Seat|सीट):\s*([^\n]+)/u', $sum, $s1);
    preg_match('/(?:सिट|Seat|सीट):\s*([^\n]+)/u', txt($r), $s2);
    $set = static fn(string $s): array => (static function (array $a): array { sort($a); return $a; })(array_map('trim', explode(',', $s)));
    check('the confirmation shows the same berths as the summary (as the ticket prints them)', isset($s1[1], $s2[1]) && $set($s1[1]) === $set($s2[1]),
        ($s1[1] ?? '?') . ' vs ' . ($s2[1] ?? '?'));
    /* round 2 (safety review #2): a typed UTR is a PREVIEW for "ho"; on "ho" it goes to the office (Help Desk) and the
       booking's status, expiry and payment row stay exactly as they were. */
    $exp0 = (string) Database::scalar('SELECT COALESCE(expires_at, \'\') FROM bookings WHERE id = :i', ['i' => (int) ($bk['id'] ?? 0)], '');
    $utrOf = static fn(int $bid): string => (string) Database::scalar('SELECT COALESCE(utr_number, \'\') FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid], '');
    $r = pax($P[12], 'paisa pathaye UPI bata, UTR 412345678901');
    check('a typed UTR is only a preview for that booking ("ho" asked) — nothing stored yet', str_contains(txt($r), (string) ($bk['pnr'] ?? '-')) && str_contains(txt($r), '*ho*')
        && $utrOf((int) ($bk['id'] ?? 0)) === '', txt($r));
    $r = pax($P[12], 'ho');
    check('…"ho" sends it to the office (Help Desk payment ticket) — the booking stays pending, same expiry, no UTR on the payment',
        str_contains(txt($r), '✅') && Database::exists("SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = 'payment' AND message LIKE '%412345678901%'", ['p' => '%' . $P[12]])
        && (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) ($bk['id'] ?? 0)], '') === 'pending'
        && (string) Database::scalar('SELECT COALESCE(expires_at, \'\') FROM bookings WHERE id = :i', ['i' => (int) ($bk['id'] ?? 0)], '') === $exp0
        && $utrOf((int) ($bk['id'] ?? 0)) === '', txt($r));
    foreach (['paisa pathaye, UTR number 412345678902', 'reference no. 412345678903 paisa pathaye'] as $line) {
        $r = pax($P[12], $line);
        preg_match('/UTR …(\w{4})/u', txt($r), $tl);
        check('"' . $line . '" reads the digits after "number / no." (never NUMBER / ERENCE)', ($tl[1] ?? '') === substr((string) preg_replace('/\D/', '', $line), -4), txt($r));
        pax($P[12], 'no');
    }
    foreach (['payment kasari garne? mero number 919876543210', 'upi payment gardai chu, utr pathaunchu', 'UTR 9812345671 paisa pathaye', 'paisa pathaye utr +919876543210'] as $line) {
        $r = pax($P[12], $line);
        check('"' . $line . '" is no payment reference (question / future / a mobile) — no preview', !str_contains(txt($r), 'UTR …') && $utrOf((int) ($bk['id'] ?? 0)) === '', txt($r));
    }
    $r = pax($P[13], 'mujhe kal 2 ticket chahiye Surat se Nepal');
    check('Hindi asks for "all 2" names (never "all four")', !str_contains(txt($r), 'चारों') && str_contains(txt($r), '2'), txt($r));

    /* ================================================================= */
    section('desk: refusals and day words on a waiting quote');
    $q = txt(desk($AG, '1 seat bholi Surat Gopal Rana ' . $PAXN[0]));
    check('a one-line sale is a quote first', str_contains($q, '*ho*') && (pendingOf($AG)['kind'] ?? '') === 'sale', $q);
    $r = desk($AG, 'parsi');
    check('"parsi" alone re-plans the quote for the day after tomorrow', (pendingOf($AG)['sale']['plan']['date'] ?? '') === $PARSI && str_contains(txt($r), '*ho*'), txt($r));
    foreach (['nahi', 'pardaina', 'mat karo', 'nahi chahiye', 'chahidaina'] as $no) {
        desk($AG, '1 seat bholi Surat Gopal Rana ' . $PAXN[0]);
        $r = desk($AG, $no);
        check('"' . $no . '" drops the quote (never a passenger "' . ucwords($no) . '")', pendingOf($AG) === null && !str_contains(txt($r), ucwords($no)), txt($r));
    }
    check('nothing was sold by any of that', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAXN[0]]));
    $r = desk($AG, 'Dileep, 2 ticket ok tciket');
    check('no day and no pickup: the questions, not a quote "ok" could sell', (pendingOf($AG)['kind'] ?? '') === 'book', txt($r));
    desk($AG, 'no');
    $r = desk($CT, 'Bhai kal ka 1 ticket kaat do Surat se, naam Mohan Lal ' . $PAXN[1]);
    check('after "naam" only the name is the passenger ("Mohan Lal", not "Kaat Do Mohan Lal")', str_contains(txt($r), 'Mohan Lal') && !str_contains(txt($r), 'Kaat'), txt($r));
    $r = desk($CT, 'aajako hisab dekhau');
    check('a command while a quote waits runs the command, never renames the passenger', !str_contains(txt($r), 'Aajako') && str_contains(txt($r), '📊'), txt($r));
    check('…and the quote is dropped, so a later "ok" sells nothing', pendingOf($CT) === null && str_contains(txt(desk($CT, 'ok')), 'ℹ️')
        && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAXN[1]]));
    $r = desk($CT, 'bholi 3 jana ko tikat chaiyo surat bata');
    check('"tikat" / "chaiyo" are never a name: the quote asks for the passenger', !str_contains(txt($r), 'Tikat'), txt($r));
    desk($CT, 'no');

    /* ================================================================= */
    section('desk: sentences are commands');
    AgentWallet::record($agId, 'commission', 900, ['note' => 'wa-desk-sim test']);
    check('"mero commission kati bhayo?" = balance', str_contains(txt(desk($AG, 'mero commission kati bhayo?')), '₹900'));
    check('"balanse kati cha" (typo) = balance', str_contains(txt(desk($AG, 'balanse kati cha')), '₹900'));
    $r = desk($AG, 'paisa nikalnu cha 300');
    check('"paisa nikalnu cha 300" asks to withdraw ₹300 (not the whole due) — and waits for "ho"', str_contains(txt($r), '₹300') && (pendingOf($AG)['kind'] ?? '') === 'withdraw'
        && AgentWallet::openPayoutRequests($agId) === [], txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'maile 2000 tireko chu, UTR 512345678421');
    check('"maile 2000 tireko chu, UTR …" is a PREVIEW (₹2,000, that UTR) — nothing filed before "ho"', str_contains(txt($r), '512345678421') && str_contains(txt($r), '2,000')
        && (pendingOf($AG)['kind'] ?? '') === 'deposit' && !Database::exists("SELECT 1 FROM ap_deposit_requests WHERE admin_id = :a", ['a' => $agId]), txt($r));
    $r = desk($AG, 'ho');
    check('…"ho" records the payment for the office', str_contains(txt($r), '512345678421')
        && Database::exists("SELECT 1 FROM ap_deposit_requests WHERE admin_id = :a AND utr = '512345678421' AND amount = 2000", ['a' => $agId]), txt($r));
    $deps = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a', ['a' => $agId], 0);
    $d0 = $deps();
    foreach (['Ram Thapa 9812345671, 1 seat bholi Surat, 1500 cash paid', 'customer 9812345672 lai ticket pathaye, 2400 liye', '2 seat bholi Surat Ram Thapa 9812345662, paisa 4400 jama garchu'] as $line) {
        $r = desk($AG, $line);
        check('"' . $line . '" files no deposit and keeps no phone as a UTR', $deps() === $d0 && (pendingOf($AG)['kind'] ?? '') !== 'deposit', txt($r));
        desk($AG, 'no');
    }
    $r = desk($AG, 'maile 26 Oct ma 5000 pathaye UTR 512345678999');
    check('"maile 26 Oct ma 5000 pathaye UTR …" previews ₹5,000 (never ₹26) and waits', str_contains(txt($r), '5,000') && !str_contains(txt($r), '₹26')
        && (pendingOf($AG)['kind'] ?? '') === 'deposit' && $deps() === $d0, txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'maile 2000 jama gareko chu');
    check('"maile 2000 jama gareko chu" (no UTR) asks how / for the reference — nothing filed', str_contains(txt($r), 'UTR') && $deps() === $d0 && !str_contains(txt($r), '1️⃣'), txt($r));
    $r = desk($AG, 'office ma 5000 cash bujhaye');
    check('"office ma 5000 cash bujhaye": the office confirms the cash — nothing filed, not the company card', str_contains(txt($r), '5,000') && !str_contains(txt($r), 'CIN') && $deps() === $d0, txt($r));
    check('"mero visiting card pathaidinu" sends the card', !empty(desk($AG, 'mero visiting card pathaidinu')['media']));
    check('"portal ma login garna milena" gets the sign-in help', str_contains(txt(desk($AG, 'portal ma login garna milena')), 'login'));
    check('"bholi ko mero ticket haru dekhau na" lists tomorrow\'s tickets', str_contains(txt(desk($AG, 'bholi ko mero ticket haru dekhau na')), '📋'));
    check('"dai aaja bus kati baje chhutcha?" gives today\'s buses', str_contains(txt(desk($AG, 'dai aaja bus kati baje chhutcha?')), '🚌'));
    check('"Kamal ko bank account number deu" is refused', str_contains(txt(desk($CT, 'Kamal ko bank account number deu')), '🔒'));
    check('another member\'s balance is refused for a counter', str_contains(txt(desk($CT, $crdCode . ' ko balance kati cha')), '🔒'));
    check('"Sunilkumar ko number deu" gives the number', str_contains(txt(desk($CT, 'Sunilkumar ko number deu')), substr($ST, -5)));
    // round 2 (safety review #5): a counter known only by its typed number never gets an agent's personal mobile
    $r = desk($CT, 'number Kamal');
    check('a counter that is not OTP-linked does not get an agent\'s personal mobile ("number Kamal") — and is told why', !str_contains(txt($r), substr($CRD, -5))
        && str_contains(txt($r), '🔒'), txt($r));
    $r = desk($MGR, 'number Kamal');
    check('…the OTP-linked office still does', str_contains(txt($r), substr($CRD, -5)), txt($r));
    check('…nor the whole agent list with mobiles ("number agent")', !str_contains(txt(desk($CT, 'number agent')), substr($CRD, -5))
        && !str_contains(txt(desk($CT, 'number agent')), substr($AG, -5)));
    desk($AG, 'nepali');
    $r = desk($AG, 'thank you dai');
    check('after "nepali", "thank you dai" is answered in Nepali (the choice sticks)', str_contains(txt($r), 'धन्यवाद'), txt($r));
    check('a lone "ho" with nothing waiting says so (not the menu)', str_contains(txt(desk($AG, 'ho')), 'ℹ️'));

    /* ================================================================= */
    section('desk: a counter sells for an agent and for the company, and sees its own tickets');
    $r = desk($CT, $crdCode . ' ko lagi 1 ticket bholi Surat, Ram Thapa ' . $PAXN[2]);
    check('"SHG-xxxx ko lagi …" quotes for that agent', str_contains(txt($r), $crdCode) && (pendingOf($CT)['kind'] ?? '') === 'sale', txt($r));
    $r = desk($CT, 'ho');
    $bk2 = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PAXN[2]]);
    check('…"ho" sells it credited to that agent (sold_by), cut by the counter (booked_by)', $bk2 !== null && (int) $bk2['sold_by_admin_id'] === $crdId
        && (int) $bk2['booked_by_admin_id'] === $ctId, txt($r));
    check('…and the counter is not told it earned a commission', !str_contains(txt($r), 'कमिसन तपाईंको'), txt($r));
    $pnr2 = (string) ($bk2['pnr'] ?? '');
    check('the counter sees that ticket in "tickets"', $pnr2 !== '' && str_contains(txt(desk($CT, 'tickets')), $pnr2));
    check('…its PNR card', str_contains(txt(desk($CT, $pnr2)), '🎫'));
    $r = desk($CT, $pnr2 . ' resend');
    check('…can resend it — and, as WhatsApp did not take it on this test number, says so (never "✅ sent")', str_contains(txt($r), $pnr2)
        && !str_contains(txt($r), '⚠️') && !str_contains(txt($r), '✅') && preg_match('/फर्वार्ड|फॉरवर्ड|forward/u', txt($r)) === 1, txt($r));
    check('another agent cannot', str_contains(txt(desk($AG, $pnr2 . ' resend')), '⚠️'));
    $r = desk($CT, 'company ko 1 seat bholi Surat, Hari Prasad ' . $PAXN[3]);
    check('"company ko 1 seat …" is a sale quote, not the company card', str_contains(txt($r), '*ho*') && !str_contains(txt($r), 'CIN'), txt($r));
    desk($CT, 'no');

    /* ================================================================= */
    section('office desk');
    check('"baki kam k k cha?" is the pending list (not the owner\'s own wallet)', str_contains(txt(desk($MGR, 'baki kam k k cha?')), '⏳'));
    $r = desk($MGR, 'balance ' . $crdCode);
    check('"balance SHG-xxxx" is that agent\'s wallet', str_contains(txt($r), 'APWS Kamal Creditagent'), txt($r));
    AgentWallet::saveProfile($crdId, ['payout_method' => 'bank', 'payout_account' => 'SBI A/C 30123456789 IFSC SBIN0001234']);
    AgentWallet::record($crdId, 'commission', 400, ['note' => 'wa-desk-sim test']);
    $wid = AgentWallet::requestPayout($crdId, 400, 'test');
    $r = desk($MGR, 'withdrawals');
    check('withdrawals: the bank account is masked', str_contains(txt($r), '#' . $wid) && !str_contains(txt($r), '30123456789') && !str_contains(txt($r), 'SBIN0001234') && str_contains(txt($r), '6789'), txt($r));
    $r = desk($MGR, "approve $wid UTR998877665544 confirm");
    check('"approve … UTR… confirm" previews with the reference alone, and waits', str_contains(txt($r), 'Ref: UTR998877665544') && !str_contains(txt($r), 'UTR998877665544confirm')
        && (pendingOf($MGR)['kind'] ?? '') === 'wd_pay' && count(AgentWallet::openPayoutRequests($crdId)) === 1, txt($r));
    desk($MGR, 'no');
    $r = desk($MGR, $pnr2 . ' ko phone number k ho?');
    check('"<PNR> ko phone number k ho?" answers with the card (a question is never an edit)', str_contains(txt($r), '🎫') && pendingOf($MGR) === null, txt($r));
    $r = desk($MGR, $pnr2 . ' Ram ko seat Z9');
    check('a seat this bus does not have is refused at the preview, nothing to "ho"', str_contains(txt($r), '⚠️') && pendingOf($MGR) === null, txt($r));
    $sched = (int) Database::scalar("SELECT schedule_id FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bk2['id']], 0);
    $taken = array_map('strval', Seats::bookedSeats($sched));
    $free = '';
    foreach (Seats::seatIdsForSchedule($sched) as $sid) {
        if (!in_array((string) $sid, $taken, true) && preg_match('/^L\d+$/', (string) $sid)) { $free = (string) $sid; break; }
    }
    $lbl = Seats::displayLabel($free, Seats::coachForSchedule($sched), (string) $bk2['booking_mode']);
    $r = desk($MGR, $pnr2 . ' Ram ko seat ' . $lbl);
    check('a seat typed as printed on the ticket (' . $lbl . ') previews that berth', ($free !== '' && (pendingOf($MGR)['change']['new'] ?? '') === $free) && str_contains(txt($r), $lbl), txt($r));
    desk($MGR, 'no');
    check('"how many tickets sold today?" is the day\'s report', str_contains(txt(desk($MGR, 'how many tickets sold today?')), '📊'));
    desk($MGR, 'nepali');
    $r = desk($MGR, $pnr2 . ' cancel');
    check('the cancel quote\'s refund rule is in the chat\'s language (no English line)', str_contains(txt($r), '*ho*') && !str_contains(txt($r), 'hours before departure'), txt($r));
    desk($MGR, 'no');
    $r = desk($MGR, 'Kamal Creditagent lai 999 pathaidiye UTR 556677889900');
    check('"<name> lai 999 pathaidiye UTR …" names the open request and refuses a different amount — nothing recorded', str_contains(txt($r), '#' . $wid)
        && str_contains(txt($r), '⚠️') && pendingOf($MGR) === null && count(AgentWallet::openPayoutRequests($crdId)) === 1, txt($r));
    $r = desk($MGR, 'Kamal Creditagent lai 400 pathaidiye UTR 556677889900');
    check('…with the right amount it is the approve PREVIEW (CONFIRM still needed)', (pendingOf($MGR)['kind'] ?? '') === 'wd_pay' && count(AgentWallet::openPayoutRequests($crdId)) === 1, txt($r));
    $r = desk($MGR, 'CONFIRM');
    check('…CONFIRM records it; the reply says the member was NOT reached (nothing was sent on this test number)', str_contains(txt($r), '✅') && str_contains(txt($r), '⚠️')
        && AgentWallet::openPayoutRequests($crdId) === [], txt($r));
    /* ================================================================= */
    section('round 2: the office in a sentence — the reference, the amount, nothing from a form / ticket / number');
    AgentWallet::record($crdId, 'commission', 400, ['note' => 'wa-desk-sim test r2']);
    $wid2 = AgentWallet::requestPayout($crdId, 400, 'test r2');
    $r = desk($MGR, $crdCode . ' lai 400 pathaidiye UTR 556677889911');
    check('"SHG-xxxx lai 400 pathaidiye UTR …" previews #' . $wid2 . ' for ₹400 (never the code\'s digits as ₹)', (pendingOf($MGR)['kind'] ?? '') === 'wd_pay'
        && str_contains(txt($r), '₹400') && str_contains(txt($r), '556677889911') && count(AgentWallet::openPayoutRequests($crdId)) === 1, txt($r));
    desk($MGR, 'no');
    foreach (['utr number 556677889912' => '556677889912', 'reference 556677889913' => '556677889913', 'ref no. 556677889914' => '556677889914'] as $how => $want) {
        $r = desk($MGR, 'Kamal Creditagent lai 400 pathaidiye ' . $how);
        check('"… pathaidiye ' . $how . '" previews Ref: ' . $want . ' (never NUMBER / ERENCE)', str_contains(txt($r), 'Ref: ' . $want) && !preg_match('/Ref: [A-Z]{3,}\b/u', txt($r)), txt($r));
        desk($MGR, 'no');
    }
    foreach (['Kamal Creditagent le form pathayo, thik cha?', 'Kamal Creditagent lai ticket pathaidiye', 'Kamal Creditagent ko number diyo?'] as $line) {
        $r = desk($MGR, $line);
        check('"' . $line . '" is no money preview', !in_array((string) (pendingOf($MGR)['kind'] ?? ''), ['wd_pay', 'dep_confirm'], true) && !str_contains(txt($r), 'CONFIRM'), txt($r));
        desk($MGR, 'no');
    }
    $r = desk($MGR, 'balance ' . $crdCode);
    check('the office looking at a member\'s wallet reads it in the third person, with office hints', !str_contains(txt($r), 'तपाईंलाई') && !str_contains(txt($r), 'withdraw 2000'), txt($r));
    Database::delete('agent_payout_requests', 'id = :i', ['i' => $wid2]);

    /* ================================================================= */
    section('round 2: the agent / counter desk');
    $r = desk($CT, '1 seat bholi Surat, Hari Prasad ' . $PAXN[3]);
    $r = desk($CT, 'What is my sale today?');
    check('"What is my sale today?" with a quote waiting runs the report and drops the quote (never re-dates it)', str_contains(txt($r), '📊') && pendingOf($CT) === null, txt($r));
    desk($CT, 'yes');
    check('…so a later "yes" sells nothing for today', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAXN[3]]));
    foreach (['aaj kitna becha?', 'how much did I sell today', 'bus today'] as $q) {
        desk($CT, '1 seat bholi Surat, Hari Prasad ' . $PAXN[3]);
        $r = desk($CT, $q);
        check('"' . $q . '" on a waiting quote answers itself and drops the quote', pendingOf($CT) === null && preg_match('/📊|🚌/u', txt($r)) === 1, txt($r));
    }
    check('"how much did I sell today" alone is the day report (not the menu)', str_contains(txt(desk($CT, 'how much did I sell today')), '📊'));
    $r = desk($CT, 'bholi Surat bata 2 jana ko ticket chahiyo, naam Gopal Thapa ra Sita Thapa ' . $PAXN[4]);
    check('"naam Gopal Thapa ra Sita Thapa" is two passengers', (pendingOf($CT)['sale']['names'] ?? []) === ['Gopal Thapa', 'Sita Thapa'], txt($r));
    desk($CT, 'no');
    $r = desk($AG, 'kal 1 ticket nikal do Surat Ram Thapa ' . $PAXN[0]);
    check('"kal 1 ticket nikal do Surat …" is a sale quote, never a withdrawal', (pendingOf($AG)['kind'] ?? '') === 'sale' && AgentWallet::openPayoutRequests($agId) === [], txt($r));
    $r = desk($AG, 'haan ticket nikal do');
    check('…and "haan ticket nikal do" is its yes (sold), never a withdrawal', Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAXN[0]])
        && AgentWallet::openPayoutRequests($agId) === [], txt($r));
    foreach (['ha ticket kaat do' => $PAXN[1], 'haan ticket bana do' => $PAXN[2]] as $yes => $ph) {
        desk($AG, '1 seat bholi Surat Ram Thapa ' . $ph);
        $r = desk($AG, $yes);
        check('"' . $yes . '" on a complete quote sells it', Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $ph]), txt($r));
    }
    desk($AG, '1 seat bholi Surat Ram Thapa ' . $PAXN[5]);
    $r = desk($AG, 'do ticket kaat do');
    check('"do ticket kaat do" on a 1-seat quote re-plans it for 2 — never sells the 1-seat quote as its yes', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => $PAXN[5]])
        && (pendingOf($AG)['kind'] ?? '') === 'sale', txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'bholi Surat bata 2 jana Ram Thapa ' . $PAXN[3]);
    $r = desk($AG, 'upi bata tircha');
    check('"upi bata tircha" sets UPI on the quote and keeps the name "Ram Thapa"', (pendingOf($AG)['sale']['pay'] ?? '') === 'upi'
        && (pendingOf($AG)['sale']['name'] ?? '') === 'Ram Thapa', txt($r));
    $r = desk($AG, 'Ram Thapa ra Sita Thapa');
    check('"Ram Thapa ra Sita Thapa" on a 2-seat quote is two passengers', (pendingOf($AG)['sale']['names'] ?? []) === ['Ram Thapa', 'Sita Thapa'], txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'bus kitne baje nikalegi?');
    check('"bus kitne baje nikalegi?" gives the buses, never a withdrawal', str_contains(txt($r), '🚌') && (pendingOf($AG)['kind'] ?? '') !== 'withdraw', txt($r));
    $r = desk($AG, '2 seat bholi Surat Ram Thapa number ' . $PAXN[3] . ', bank bata pay garchu');
    check('"… bank bata pay garchu" on a sale line is a quote, not the bank-number refusal', (pendingOf($AG)['kind'] ?? '') === 'sale' && !str_contains(txt($r), '🔒'), txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'mero account ma balance k cha?');
    check('"mero account ma balance k cha?" is the balance, not the bank refusal', str_contains(txt($r), '💰') && !str_contains(txt($r), '🔒'), txt($r));
    $r = desk($AG, 'company ko UPI id pathau');
    check('"company ko UPI id pathau" gives the pay-in UPI, never the agent\'s own ID card', str_contains(txt($r), 'UPI') && !str_contains(txt($r), '🪪')
        && !str_contains((string) ($r['media'] ?? ''), 's=id-front'), txt($r));
    $r = desk($AG, 'aaja ko ticket book garnu cha');
    check('"aaja ko ticket book garnu cha" starts a booking (not the day report)', (pendingOf($AG)['kind'] ?? '') === 'book' && !str_contains(txt($r), '📊'), txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'Surat dekhi Rupaidiha bhada kati ho?');
    check('"Surat dekhi Rupaidiha bhada kati ho?" gives the fare (not the menu)', str_contains(txt($r), '₹2,200') && !str_contains(txt($r), '1️⃣'), txt($r));
    $r = desk($AG, '15 tarik ma 500 nikalnu cha');
    check('"15 tarik ma 500 nikalnu cha" asks to withdraw ₹500 (never ₹15) — and waits', str_contains(txt($r), '₹500') && !str_contains(txt($r), '₹15')
        && (pendingOf($AG)['kind'] ?? '') === 'withdraw', txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'ticket cancel garnu cha');
    check('"ticket cancel garnu cha" (no PNR) asks which PNR — nothing is cancelled', str_contains(txt($r), 'PNR') && pendingOf($AG) === null, txt($r));
    // one seat move = one passenger notice, with the printed label
    $bkS = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PAXN[0]]);
    $schS = (int) Database::scalar("SELECT schedule_id FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound'", ['b' => (int) $bkS['id']], 0);
    $takenS = array_map('strval', Seats::bookedSeats($schS));
    $freeS = '';
    foreach (Seats::seatIdsForSchedule($schS) as $sid) {
        if (!in_array((string) $sid, $takenS, true) && preg_match('/^L\d+$/', (string) $sid)) { $freeS = (string) $sid; break; }
    }
    $lblS = Seats::displayLabel($freeS, Seats::coachForSchedule($schS), (string) $bkS['booking_mode']);
    desk($AG, (string) $bkS['pnr'] . ' Ram Thapa ko seat ' . $lblS);
    $logBefore = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
    $r = desk($AG, 'ho');
    $notices = Database::fetchAll("SELECT body FROM message_logs WHERE id > :i AND to_number LIKE :p AND body LIKE :w", ['i' => $logBefore, 'p' => '%' . $PAXN[0], 'w' => '%बदलियो%']);
    check('a seat move sends the passenger ONE notice, with the printed label (' . $lblS . '; ' . count($notices) . ' logged)', count($notices) === 1
        && ($notices === [] || (str_contains((string) $notices[0]['body'], $lblS) && !str_contains((string) $notices[0]['body'], $freeS . "\n"))), json_encode(array_column($notices, 'body'), JSON_UNESCAPED_UNICODE) . ' / ' . txt($r));

    $r = desk($AG, 'Ram Thapa ko ticket kun bus ma cha?');
    check('"Ram Thapa ko ticket kun bus ma cha?" lists the agent\'s own tickets for that passenger', str_contains(txt($r), (string) $bkS['pnr']) && str_contains(txt($r), '🔎'), txt($r));
    $r = desk($AG, 'Surat ko bus kati baje chhutcha?');
    check('"Surat ko bus kati baje chhutcha?" is still the bus answer (a town is no passenger)', str_contains(txt($r), '🚌') && !str_contains(txt($r), '🔎'), txt($r));
    $two = QuickTicket::sell(['name' => 'Ram Thapa', 'phone' => $PAXN[6], 'seats' => 2, 'date' => $TOM, 'direction' => 'toNepal', 'boarding' => 'Surat', 'pay' => 'cash'], $office);
    $r = desk($MGR, (string) $two['pnr'] . ' dosro yatri ko naam Sita Thapa');
    $second = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = (SELECT id FROM bookings WHERE pnr = :p) ORDER BY id LIMIT 1 OFFSET 1', ['p' => (string) $two['pnr']], '');
    check('"dosro yatri ko naam Sita Thapa" previews the SECOND passenger → Sita Thapa (and waits for ho)', (pendingOf($MGR)['kind'] ?? '') === 'edit'
        && str_contains(txt($r), 'Sita Thapa') && str_contains(txt($r), $second), txt($r));
    desk($MGR, 'no');

    /* ================================================================= */
    section('round 2: the passenger bot');
    pax($P[15], 'bholi 1 seat Surat bata');
    $r = pax($P[15], 'mero naam Sunil Kumar ho');
    check('"mero naam Sunil Kumar ho" gives the name "Sunil Kumar"', str_contains(txt($r), 'Sunil Kumar') && !preg_match('/mero naam|Kumar ho/iu', txt($r)), txt($r));
    pax($P[15], 'no');
    $r = pax($P[16], 'my name is John, I want 1 ticket tomorrow from Surat');
    check('"my name is John, I want …" gives the name "John" (never "John I Want")', str_contains(txt($r), 'John') && !str_contains(txt($r), 'I Want'), txt($r));
    pax($P[16], 'no');
    $holderBookings = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $P[9]], 0);
    $hb0 = $holderBookings();
    $r = pax($P[9], 'seat number');
    check('a ticket holder\'s "seat number" answers their own seat (PNR + printed seat) — never a new booking', str_contains(txt($r), $pnrA) && $holderBookings() === $hb0, txt($r));
    pax($P[9], 'ok');
    check('…and a following "ok" books nothing', $holderBookings() === $hb0);
    check('"mero seat kun ho?" answers their own seat too', str_contains(txt(pax($P[9], 'mero seat kun ho?')), $pnrA));
    $r = pax($P[9], 'kati paisa tirnu baki cha?');
    check('"kati paisa tirnu baki cha?" answers their own booking (not the fare board)', str_contains(txt($r), $pnrA) && !str_contains(txt($r), 'Private cabin'), txt($r));
    $tickets = static fn(string $p): int => (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . $p], 0);
    $r = pax($P[17], 'staff ko behaviour ekdam ramro thiyo, dhanyabad');
    check('praise + thanks is a thank-you — no complaint ticket', str_contains(txt($r), 'धन्यवाद') && $tickets($P[17]) === 0, txt($r));
    $r = pax($P[18], 'malai 2 extra seat chahiyo bholi Surat bata');
    check('"2 extra seat chahiyo" is a booking (2 seats) — never an overcharge complaint', $tickets($P[18]) === 0 && !str_contains(txt($r), 'HD-') && !str_contains(txt($r), 'Extra'), txt($r));
    pax($P[18], 'Ram Thapa ra Sita Thapa');
    $r = pax($P[18], 'arko 1 extra seat chahiyo');
    check('…mid-booking "arko 1 extra seat chahiyo" is no complaint either', $tickets($P[18]) === 0, txt($r));
    $r = pax($P[19], 'mausam kharab cha, bholi bus chalcha?');
    check('"mausam kharab cha, bholi bus chalcha?" is no complaint and promises no ticket', $tickets($P[19]) === 0 && !str_contains(txt($r), 'अनुरोध प्राप्त') && $drafts($P[19]) === 0, txt($r));
    $r = pax($P[20], 'bholi 1 seat Surat bata, Kathmandu jane ho');
    check('a booking that names Kathmandu goes on (the name is asked) with one line on where the bus ends', str_contains(txt($r), 'Kathmandu') && WaBooking::isOpen($P[20]), txt($r));
    $r = pax($P[21], 'bholi 1 jana Surat bata, naam Bikash Rai, ticket 9327055512 ma pathaunu');
    check('"ticket <mobile> ma pathaunu" is where the ticket goes: the summary with that number and the name', str_contains(txt($r), '9327055512') && str_contains(txt($r), 'Bikash Rai') && $drafts($P[21]) === 0, txt($r));
    pax($P[21], 'no');
    $r = pax($P[22], 'Mehsana counter ko number dinu');
    check('"Mehsana counter ko number dinu" gives the counters\' numbers (no draft)', str_contains(txt($r), 'Mehsana +91') && $drafts($P[22]) === 0, txt($r));
    foreach (['luggage kati kg samma lana milcha?' => '🧳', 'border ma k document chahincha?' => '🪪', 'bachha ko ticket lagcha?' => '👶'] as $q => $mark) {
        $r = pax($P[23], $q);
        check('"' . $q . '" is answered (' . $mark . ') — never a ticket promise', str_contains(txt($r), $mark) && !str_contains(txt($r), 'अनुरोध प्राप्त') && $drafts($P[23]) === 0, txt($r));
    }
    $r = pax($P[24], 'Nepalgunj samma bus jancha?');
    check('"Nepalgunj samma bus jancha?" — yes, to the Rupaidiha (Nepalgunj) border', str_contains(txt($r), 'हो') && str_contains(txt($r), '🚌'), txt($r));
    $r = pax($P[25], 'maile paisa pathaye UTR 412345678903');
    check('a UTR from a number with no booking: "no booking waiting" + the office number, nothing stored', str_contains(txt($r), Settings::officePhone()) && !str_contains(txt($r), '*ho*'), txt($r));
    $r = pax($P[26], 'Surat bata 2 jana ko 4400 lagcha?');
    check('"Surat bata 2 jana ko 4400 lagcha?" — yes, ₹2,200 each, ₹4,400 for 2', str_contains(txt($r), '✅') && str_contains(txt($r), '4,400') && $drafts($P[26]) === 0, txt($r));
    $r = pax($P[27], 'driver le gali garyo, complain');
    check('"driver le gali garyo, complain" is answered in Nepali', str_contains(txt($r), 'माफ गर्नुहोस्'), txt($r));
    $r = pax($P[9], 'Gita Sharma ko phone number k ho?');
    check('another passenger\'s number is politely refused, with the office line', str_contains(txt($r), Settings::officePhone()) && str_contains(txt($r), 'निजी'), txt($r));
    $r = pax($P[28], 'Baroda bata bus kati baje?');
    check('the time answer names the stop as shown ("Baroda", never "Barauda")', str_contains(txt($r), 'Baroda') && !str_contains(txt($r), 'Barauda'), txt($r));
    pax($P[29], 'bholi 1 jana Surat bata');
    pax($P[29], 'Sunil Rai, phone ' . $PAXN[5]);
    $r = pax($P[29], 'UPI');
    check('a UPI sale for a typed number says where the ticket goes', str_contains(txt($r), $PAXN[5]), txt($r));
    $r = pax($P[29], 'payment kasari garne?');
    check('…"payment kasari garne?" shows THAT sale\'s amount and pay link', str_contains(txt($r), 'pay.php?pnr=SHG-'), txt($r));
    $r = pax($P[29], 'ho');
    check('…a stray "ho" after the sale says it is booked (not the generic welcome)', str_contains(txt($r), '✅') && str_contains(txt($r), 'SHG-'), txt($r));

    /* ================================================================= */
    section('round 3, R1: a "no" or a "not now" drops what waits for "ho" — never a name, never a new day, never a sale');
    // these sections send many messages from one number: the desk's hourly cap (90) is reset for the test numbers
    $unlimit = static function () use ($members): void {
        Database::run("DELETE FROM rate_limits WHERE identifier IN ('" . implode("','", $members) . "')");
    };
    $unlimit();
    require_once INCLUDE_PATH . '/personname.php';
    require_once INCLUDE_PATH . '/wapayproof.php';
    $holds = ['abhi nahi', 'ahile hoina', 'ruko abhi', 'ahile nakaat', 'wait, abhi nahi', 'abhi mat kaato, customer se puchh ke batata hu', 'wait', 'ruka',
        'ekchin', 'ek chin', 'pakhnus', 'parkhanus', 'hold', 'hold on', 'rukiye', 'baad me', 'baad mein batata hu', 'pachhi', 'pachi tirchu', 'sochchu',
        'customer lai sodhchu', 'na kaato', 'nagarnu', 'rehne do', 'not now', 'dont book yet', 'later', 'cancel', 'रुको', 'अहिले होइन', 'अभी नहीं',
        'नकाट्नुस्', 'abhi nhi', 'pardaina ahile', 'chhod deu'];
    $notHold = ['ho', 'ok', 'haan ticket kaat do', 'Ram Thapa', 'naam Sita Thapa', 'mobile no 9812345678', 'seat no. 5', 'no problem', 'Surat bus stop',
        'bholi', 'parsi', 'upi', 'upi bata tircha', 'dinus na', 'Rukmini Devi', 'Ram Rokaya', 'Nagarkot', '2 seat', 'abhi', 'ahile', 'now', 'today'];
    $miss = array_values(array_filter($holds, static fn(string $h): bool => !TicketBot::holdOrNo($h)));
    check('TicketBot::holdOrNo() reads every refusal / pause (' . count($holds) . ' phrasings: Nepali, Hindi, English, Devanagari, typos)', $miss === [], implode(' | ', $miss));
    $miss = array_values(array_filter($notHold, static fn(string $h): bool => TicketBot::holdOrNo($h)));
    check('…and never a yes, a name, "mobile no 98…", "no problem", "bus stop", "dinus na", a day or "abhi" alone', $miss === [], implode(' | ', $miss));
    $r3pax = $PAXN[7];
    $r3sold = static fn(string $p): int => (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $p], 0);
    foreach (['abhi nahi', 'ahile hoina', 'ruko abhi', 'ahile nakaat', 'abhi mat kaato, customer se puchh ke batata hu', 'wait', 'ek chin', 'pakhnus',
              'baad me', 'customer lai sodhchu', 'रुको', 'not now'] as $hold) {
        $unlimit();
        desk($AG, '1 seat bholi Surat Gopal Rana ' . $r3pax);
        $r = desk($AG, $hold);
        $after = desk($AG, 'ho');
        check('"' . $hold . '" on a waiting quote drops it (no passenger "' . mb_convert_case(explode(' ', $hold)[0], MB_CASE_TITLE) . '", no re-date) — the next "ho" sells nothing',
            str_contains(txt($r), '👍') && !str_contains(txt($r), '⏳') && str_contains(txt($after), 'ℹ️') && $r3sold($r3pax) === 0, txt($r) . ' / ' . txt($after));
    }
    $unlimit();
    desk($AG, '1 seat bholi Surat Gopal Rana ' . $r3pax);
    foreach (['abhi', 'ahile', 'now', 'aile'] as $now) {
        $r = desk($AG, $now);
        check('"' . $now . '" alone never re-dates the quote to today (still tomorrow, still waiting)', (pendingOf($AG)['sale']['plan']['date'] ?? '') === $TOM
            && (pendingOf($AG)['sale']['name'] ?? '') === 'Gopal Rana', txt($r));
    }
    foreach (['Sita', 'Hira', 'Gopal Thapa'] as $bare) {
        $r = desk($AG, $bare);
        check('a bare word "' . $bare . '" on a quote that has a name never renames it — the desk asks for "naam …"', (pendingOf($AG)['sale']['name'] ?? '') === 'Gopal Rana'
            && str_contains(txt($r), 'naam'), txt($r));
    }
    $r = desk($AG, 'ho, naam Sita Thapa');
    check('"ho, naam Sita Thapa" renames the passenger and waits for a fresh "ho" (nothing sold)', (pendingOf($AG)['sale']['name'] ?? '') === 'Sita Thapa' && $r3sold($r3pax) === 0, txt($r));
    foreach (['upi ma pathaucha' => 'upi', 'esewa bata pathaunchu' => 'esewa', 'gpay cha' => 'upi', 'ho tara upi ma' => 'upi', 'cash ma dinchu' => 'cash'] as $line => $mode) {
        $r = desk($AG, $line);
        check('"' . $line . '" on a waiting quote sets the pay mode (' . $mode . '), keeps the name — never the company pay-in card', (pendingOf($AG)['sale']['pay'] ?? '') === $mode
            && (pendingOf($AG)['sale']['name'] ?? '') === 'Sita Thapa' && !str_contains(txt($r), 'jama 1500'), txt($r));
    }
    foreach (['seat kun kun ho?', 'kati bhayo?', 'paisa ghar pugera dinchu'] as $side) {
        $r = desk($AG, $side);
        check('"' . $side . '" keeps the quote waiting and shows it (seats / total) — never the menu', str_contains(txt($r), '⏳') && (pendingOf($AG)['kind'] ?? '') === 'sale'
            && !str_contains(txt($r), '1️⃣'), txt($r));
    }
    desk($AG, 'no');
    $dep0 = $deps();
    desk($AG, 'jama 700 UTR 412345670102');
    $r = desk($AG, 'ek chin');
    desk($AG, 'ho');
    check('"ek chin" on a deposit preview drops it — the later "ho" files nothing', str_contains(txt($r), '👍') && $deps() === $dep0, txt($r));
    desk($AG, 'withdraw 300');
    $r = desk($AG, 'ruko, pachhi');
    desk($AG, 'ho');
    check('"ruko, pachhi" on a withdrawal preview drops it — no request filed', str_contains(txt($r), '👍') && AgentWallet::openPayoutRequests($agId) === [], txt($r));
    // the passenger's summary waits for "ho" the same way
    pax($PAXN[8], 'bholi 1 jana Surat bata');
    $r = pax($PAXN[8], 'wait');
    check('a passenger\'s "wait" at the name question is never the name "Wait" (asked again)', !str_contains(txt($r), 'Wait') && WaBooking::isOpen($PAXN[8]), txt($r));
    pax($PAXN[8], 'Maya Rai');
    $r = pax($PAXN[8], 'ruko, pachi bhanchu');
    $after = pax($PAXN[8], 'ho');
    check('…"ruko, pachi bhanchu" while the summary waits drops it — the later "ho" books nothing', !WaBooking::isOpen($PAXN[8]) && $r3sold($PAXN[8]) === 0, txt($r) . ' / ' . txt($after));
    foreach (['Paisa Pathaye Utr', 'Utr', 'Wait', 'Ruko', 'Nakaat', 'Customer Sodhchu', 'Ram Wait'] as $nn) {
        check('PersonName never takes "' . $nn . '" as a passenger', PersonName::clean($nn) === '');
    }

    /* ================================================================= */
    section('round 3, R2: a payment line never reaches the booking engine or a sale');
    $unlimit();
    $payYes = ['UTR 412345678901', 'utr no 412345678901', 'ref 4123', 'reference number 4123', 'txn id 412345678901', 'transaction failed', 'rrn 412345678901',
        'paisa pathaye', 'maile paisa pathaye', '9810969875 bata paisa pathaye', 'pathaisake', 'पैसा पठाएँ', 'U.T.R 412345678901'];
    $payNo = ['refund chahiyo', 'UPI bata tirchu', 'ticket pathaidinu', 'bholi 2 seat Surat bata', 'payment kasari garne?', 'mero ticket pathaunu'];
    $miss = array_values(array_filter($payYes, static fn(string $s): bool => !WaPayProof::payTalk($s)));
    check('WaPayProof::payTalk() reads utr / ref / txn / transaction / rrn / "paisa pathaye" (' . count($payYes) . ' phrasings)', $miss === [], implode(' | ', $miss));
    $miss = array_values(array_filter($payNo, static fn(string $s): bool => WaPayProof::payTalk($s)));
    check('…and never "refund", "UPI bata tirchu", "ticket pathaidinu", a booking line or a pay question', $miss === [], implode(' | ', $miss));
    $p2 = $P[30];
    pax($p2, 'bholi 1 jana Surat bata');
    pax($p2, 'Dipak Rai');
    pax($p2, 'UPI');
    $pend = Database::fetch('SELECT * FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $p2]);
    $count0 = $r3sold($p2);
    foreach (['paisa pathaye UTR 917612345678', 'utr 9812345678', '9810969875 bata paisa pathaye', 'UTR +91 98123 45678', 'paisa pathaye', 'maile paisa pathaisake',
              'payment gareko ref 9876543210'] as $line) {
        $r = pax($p2, $line);
        $after = pax($p2, 'ho');
        check('"' . $line . '" after a UPI sale: asked for the 12-digit UTR / screenshot — no summary, no new booking, the next "ho" sells nothing',
            str_contains(txt($r), 'UTR') && str_contains(txt($r), 'screenshot') && !str_contains(txt($r), 'नाम:') && !str_contains(txt($r), 'Name:')
            && $r3sold($p2) === $count0 && !WaBooking::isOpen($p2), txt($r) . ' / ' . txt($after));
    }
    check('…and the pending booking was never touched (status, payment)', (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $pend['id']], '') === 'pending'
        && $utrOf((int) $pend['id']) === '');
    $r = pax($p2, 'UTR 6304 1234 5678');
    check('"UTR 6304 1234 5678" (grouped as payment apps show it) is one reference — previewed for "ho"', str_contains(txt($r), '…5678') && str_contains(txt($r), '*ho*'), txt($r));
    pax($p2, 'no');
    $p3 = $P[31];
    $r = pax($p3, 'UTR 9868426937');
    $after = pax($p3, 'yes');
    check('a number with no booking: "UTR 98…" (a mobile) is never a booking for "Utr" — and "yes" sells nothing', str_contains(txt($r), 'UTR') && $r3sold($p3) === 0
        && !WaBooking::isOpen($p3), txt($r) . ' / ' . txt($after));
    $r = pax($p3, 'maile paisa pathaye UTR 412345678903');
    check('"maile paisa pathaye UTR …" (no booking) is answered in Nepali', str_contains(txt($r), 'पायौं') && str_contains(txt($r), Settings::officePhone()), txt($r));
    $r = pax($p3, 'maine payment kar diya UTR 412345678918');
    check('"maine payment kar diya UTR …" (no booking) is answered in Hindi', str_contains(txt($r), 'मिला'), txt($r));
    $r = desk($AG, '2 seat bholi Surat Ram Thapa ' . $PAXN[9] . ', paisa pathaye UTR 412345678999');
    check('the desk: a sale line carrying a payment reference is no sale (two messages, please)', (pendingOf($AG)['kind'] ?? '') !== 'sale' && str_contains(txt($r), '✂️')
        && $r3sold($PAXN[9]) === 0, txt($r));
    desk($AG, '1 seat bholi Surat Gopal Rana ' . $PAXN[9]);
    $r = desk($AG, 'UTR 9812345678');
    check('the desk: "UTR 98…" on a waiting quote never becomes its phone', (pendingOf($AG)['sale']['phone'] ?? '') === $PAXN[9], txt($r));
    desk($AG, 'no');

    /* ================================================================= */
    section('round 3, R3: an office sentence naming a member goes to THAT member — never the office\'s own account');
    $unlimit();
    AgentWallet::record($crdId, 'commission', 400, ['note' => 'wa-desk-sim test r3']);
    $wid3 = AgentWallet::requestPayout($crdId, 400, 'test r3');
    $mgrMoney = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a', ['a' => $mgrId], 0)
        + count(AgentWallet::openPayoutRequests($mgrId));
    AgentWallet::record($mgrId, 'commission', 1000, ['note' => 'wa-desk-sim test r3 (the manager has a wallet)']);
    foreach ([$crdCode . ' ko 400 withdraw approve gardeu UTR 532559718080', 'Kamal Creditagent ko 400 withdraw approve gardeu UTR 528333315503',
              'approve Kamal Creditagent ko withdraw UTR 540161151018', 'I paid Kamal Creditagent 400, UTR 540161151021', 'paid 400 to ' . $crdCode . ' UTR 653567824477',
              'maile Kamal Creditagent lai 400 pathaye UTR 540161151022', 'Kamal ko withdraw approve gardinu, UTR 540161151023'] as $line) {
        $r = desk($MGR, $line);
        check('"' . $line . '" → the approve preview of #' . $wid3 . ' (names the member, waits for CONFIRM)', (pendingOf($MGR)['kind'] ?? '') === 'wd_pay'
            && (int) (pendingOf($MGR)['id'] ?? 0) === $wid3 && str_contains(txt($r), 'Creditagent'), txt($r));
        desk($MGR, 'no');
    }
    foreach (['Kamal Creditagent ko 400 ko withdraw pass gardeu', $crdCode . ' ko withdraw 400 approve'] as $line) {
        $r = desk($MGR, $line);
        check('"' . $line . '" (no reference) asks for it — nothing previewed or filed', pendingOf($MGR) === null && str_contains(txt($r), 'approve ' . $wid3), txt($r));
    }
    foreach (['Kamal Creditagent ko 5000 jama bhayo UTR 412345678951', 'maile 5000 pathaye UTR 512345678999', '400 withdraw gardeu', 'Kamal Creditagent ko cash diyo?'] as $line) {
        $r = desk($MGR, $line);
        desk($MGR, 'ho');
        check('"' . $line . '" from the office never previews or files the office member\'s OWN deposit / withdrawal', !in_array((string) (pendingOf($MGR)['kind'] ?? ''), ['deposit', 'withdraw'], true)
            && $mgrMoney() === 0, txt($r));
    }
    check('"maile 5000 pathaye UTR …" from the office asks whose payment it is', str_contains(txt(desk($MGR, 'maile 5000 pathaye UTR 512345678998')), '❓'));
    check('"Kamal Creditagent ko cash diyo?" lists that member\'s open requests (#' . $wid3 . ')', str_contains(txt(desk($MGR, 'Kamal Creditagent ko cash diyo?')), '#' . $wid3));
    foreach (['utr number 580247381283' => '580247381283', 'reference 580247381284' => '580247381284', 'transaction id 580247381285' => '580247381285',
              'payment UTR 580247381286' => '580247381286', 'UTR no 580247381287' => '580247381287'] as $how => $want) {
        $r = desk($MGR, 'approve ' . $wid3 . ' ' . $how);
        check('typed "approve #' . $wid3 . ' ' . $how . '" stores the digits (Ref: ' . $want . ', never a label word)', (string) (pendingOf($MGR)['ref'] ?? '') === $want, txt($r));
        desk($MGR, 'no');
    }
    foreach (['Kamal Creditagent lai 400 pathaye utr 556677889900', 'Kamal lai 400 pathaidiye utr number 556677889911', 'Kamal Creditagent ko 400 jama gareko UTR 556677889922'] as $line) {
        $r = desk($CT, $line);
        check('a counter\'s "' . $line . '" is never its own company deposit (the office does member money)', (pendingOf($CT)['kind'] ?? '') !== 'deposit' && str_contains(txt($r), '🔒'), txt($r));
    }
    $r = desk($AG, 'maile 900 tireko chu UTR 412345670131');
    check('a member\'s own payment preview names the payer (whose account it goes on)', str_contains(txt($r), 'APWS Bikash Agent') && (pendingOf($AG)['kind'] ?? '') === 'deposit', txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'customer le 2200 pathayo UTR 412345678971');
    check('a passenger\'s payment relayed by a member ("customer le … UTR …") is explained, never filed as the member\'s own', (pendingOf($AG)['kind'] ?? '') !== 'deposit'
        && str_contains(txt($r), 'Verify Payments'), txt($r));
    Database::delete('agent_payout_requests', 'id = :i', ['i' => $wid3]);
    Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $mgrId]);

    /* ================================================================= */
    section('round 3, R4: a complaint ticket needs a complaint that is meant');
    $noTicket = ['kunai complain chaina dai, sabai ramro, dhanyabad', 'no complaints, thank you', 'bus bahut acchi thi, AC bhi bilkul kharab nahi tha, shukriya',
        'driver dai le kunai gali garena, ekdam ramro manche', 'staff rude thiena, sabai ramro thiyo', 'seat kharab cha bhane arko dinu hunchha?',
        'AC bus ho ki non AC? AC chalena bhane k garne?', 'mero saathi lai ticket ma jyada paisa liyo bhanera sunye, sahi ho?', 'conductor ne gali nahi di, bahut accha tha',
        'koi shikayat nahi hai, sab badhiya', 'complain kasari garne?', 'staff ko vyavahar kharab thiyo ki ramro, kya lagta hai'];
    foreach ($noTicket as $i => $line) {
        $who = $P[32 + ($i % 4)];
        $before = $tickets($who);
        $r = pax($who, $line);
        check('"' . $line . '" — no Help Desk ticket; answered with the office number', $tickets($who) === $before && str_contains(txt($r), Settings::officePhone()), txt($r));
    }
    foreach (['conductor ne 200 rupaye zyada liye' => ['payment', 'माफ़ कीजिए'], 'conductor le 200 rupaya badi magyo' => ['payment', 'माफ गर्नुहोस्'],
              'driver le gali garyo, complain' => ['staff', 'माफ गर्नुहोस्'], 'the conductor was rude to my mother' => ['staff', 'sorry']] as $line => [$cat, $mark]) {
        $who = $PAXN[10 + array_search($line, array_keys(['conductor ne 200 rupaye zyada liye' => 0, 'conductor le 200 rupaya badi magyo' => 0,
            'driver le gali garyo, complain' => 0, 'the conductor was rude to my mother' => 0]), true)];
        $r = pax($who, $line);
        check('"' . $line . '" IS a complaint: a ' . $cat . ' ticket, answered in the passenger\'s language', str_contains(txt($r), 'HD-') && str_contains(txt($r), $mark)
            && Database::exists('SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = :c AND message LIKE :m', ['p' => '%' . $who, 'c' => $cat, 'm' => '%' . mb_substr($line, 0, 20) . '%']), txt($r));
    }

    /* ================================================================= */
    section('round 3, R5: a passenger never sees another booking\'s PNR');
    $victim = $P[37];
    pax($victim, 'bholi 1 jana Surat bata');
    pax($victim, 'Maya Gurung');
    pax($victim, 'ho');
    $vPnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $victim], '');
    $att = $P[38];
    pax($att, 'bholi 1 jana Surat bata');
    $r1 = pax($att, 'Hari Lal, phone ' . $victim);
    $r2 = pax($att, 'ho');
    check('a chat that types another person\'s number is never told that person\'s PNR (' . $vPnr . ')', $vPnr !== '' && !str_contains(txt($r1) . txt($r2), $vPnr), txt($r2));
    $again = pax($att, 'bholi 1 jana Surat bata');
    pax($att, 'Gita Lal, phone ' . $victim);
    $r3 = pax($att, 'ho');
    check('…nor on a second ticket for that number (the chat\'s own PNR at most)', !str_contains(txt($again) . txt($r3), $vPnr), txt($r3));

    /* ================================================================= */
    section('round 3: the cheap medium fixes');
    $unlimit();
    $r = desk($CT, '3 seat bholi Surat, Ram Thapa, Sita Thapa and Gita Thapa ' . $PAXN[14]);
    check('"…, Ram Thapa, Sita Thapa and Gita Thapa 98…" is three passengers (never one "Ram Thapa Sita Thapa Gita")',
        (pendingOf($CT)['sale']['names'] ?? []) === ['Ram Thapa', 'Sita Thapa', 'Gita Thapa'], txt($r));
    desk($CT, 'no');
    $r = desk($CT, '2 seats tomorrow from Surat, Ram Thapa and Sita Thapa ' . $PAXN[14]);
    check('"2 seats tomorrow from Surat, Ram Thapa and Sita Thapa 98…" is two passengers', (pendingOf($CT)['sale']['names'] ?? []) === ['Ram Thapa', 'Sita Thapa'], txt($r));
    desk($CT, 'no');
    foreach (['maile 2,500 tireko chu UTR 412345670120' => '2,500', '₹1,500 pathaye UTR 412345670121' => '1,500', 'rs. 12,000 jama gareko, utr 412345670122' => '12,000'] as $line => $amt) {
        $r = desk($AG, $line);
        check('"' . $line . '" previews ₹' . $amt . ' (the comma is part of the number)', str_contains(txt($r), '₹' . $amt) && (pendingOf($AG)['kind'] ?? '') === 'deposit', txt($r));
        desk($AG, 'no');
    }
    desk($AG, 'maile 2000 jama gareko chu');
    $r = desk($AG, 'UTR 412345670106');
    check('the UTR answering "maile 2000 jama gareko chu" is the ₹2,000 preview (never the menu)', str_contains(txt($r), '2,000') && (pendingOf($AG)['kind'] ?? '') === 'deposit', txt($r));
    desk($AG, 'no');
    $r = desk($AG, 'jama 700 UTR 412345670107');
    $r = desk($AG, 'maile 800 tireko chu, UTR 412345670108');
    check('a new preview replacing a waiting one says the earlier one was dropped', str_contains(txt($r), 'ℹ️') && (float) (pendingOf($AG)['amount'] ?? 0) === 800.0, txt($r));
    desk($AG, 'no');
    foreach (['maine 3000 bhej diye hai UTR 412345670111', 'payment kar diya 3000 UTR 412345670112', '3000 ko UTR 412345670113'] as $line) {
        $r = desk($AG, $line);
        check('"' . $line . '" (Hindi) previews ₹3,000 — never the menu', str_contains(txt($r), '3,000') && (pendingOf($AG)['kind'] ?? '') === 'deposit', txt($r));
        desk($AG, 'no');
    }
    $r = desk($AG, 'kal 500 jama karunga UTR 412345670110');
    check('"kal 500 jama karunga UTR …" (a payment still to come) says how to send it after paying — nothing previewed', pendingOf($AG) === null && str_contains(txt($r), 'jama 500 UTR'), txt($r));
    $r = desk($AG, 'kati samma withdraw garna milcha');
    check('"kati samma withdraw garna milcha" is answered with the balance — no withdrawal preview', (pendingOf($AG)['kind'] ?? '') !== 'withdraw' && str_contains(txt($r), '💰'), txt($r));
    $r = desk($AG, 'Kamal Creditagent ko id card pathau');
    check('an agent asking for another member\'s card is told no (never the menu, never their own card)', str_contains(txt($r), '🔒') && empty($r['media']), txt($r));

    $orphan = (int) Database::insert('agent_payout_requests', ['agent_admin_id' => 4000000000 + random_int(1, 999999), 'amount' => 100, 'note' => 'wa-desk-sim orphan']);
    $listed = array_map(static fn(array $w): int => (int) $w['id'], AgentWallet::openPayoutRequests(null));
    check('a payout request of a member that no longer exists is not listed as pending work', !in_array($orphan, $listed, true));
    Database::delete('agent_payout_requests', 'id = :i', ['i' => $orphan]);
    Database::run("DELETE FROM agent_ledger WHERE agent_admin_id = :a", ['a' => $crdId]);

    /* ================================================================= */
    /* Round 4 — the two safety reviews of round 3 (A: actions, B: data / complaints), each finding with its phrasings. */
    $pi = 40;                                              // $P[40..94]: a fresh passenger chat for every check below
    $nextPax = static function () use (&$pi, $P): string { return $P[$pi++]; };
    $holdTxt = static fn(?array $r): bool => str_contains(txt($r), '✋');

    section('round 4, A1 (R1): on a passenger\'s summary a pause / "no" / question is read BEFORE any yes or pay word');
    $unlimit();
    foreach (['ok wait', 'ho tara ruko', 'haan par abhi nahi', 'ok but not now', 'hajur ek chin', 'cash? ruko', 'upi wait', 'ok soch ke batata hu'] as $line) {
        $who = $nextPax();
        pax($who, 'bholi 1 jana Surat bata');
        pax($who, 'Dipak Rai');
        $r = pax($who, $line);
        $after = pax($who, 'ho');
        check('summary + "' . $line . '" drops it — nothing sold, the later "ho" sells nothing', $r3sold($who) === 0 && !WaBooking::isOpen($who), txt($r) . ' / ' . txt($after));
    }
    foreach (['ho tara date galat cha', 'upi chaina', 'upi chalcha?', 'ho ki?', 'kal confirm karunga'] as $line) {
        $who = $nextPax();
        pax($who, 'parsi 1 jana Surat bata');
        pax($who, 'Hira Rai');
        $r = pax($who, $line);
        check('summary + "' . $line . '" sells nothing and keeps the summary (same day)', $r3sold($who) === 0 && WaBooking::isOpen($who)
            && !str_contains(txt($r), 'SHG-20'), txt($r));
        pax($who, 'no');
    }
    $yesOk = ['ho', 'ok', 'yes', 'haan ji', 'ho dai', 'thik cha', 'book', 'ok book garnus', 'हो', 'हजुर'];
    $yesNo = ['ok wait', 'ho tara date galat cha', 'ho ki?', 'ha ha', 'haha', 'thik cha tara bholi ko garnu', 'ok soch ke batata hu', 'upi chaina'];
    check('WaBooking::isYes is the WHOLE line (' . implode(' | ', $yesOk) . ')', array_filter($yesOk, static fn(string $s): bool => !WaBooking::isYes($s)) === []);
    $bad = array_values(array_filter($yesNo, static fn(string $s): bool => WaBooking::isYes($s)));
    check('…and never a line that only starts with a yes-word, a question or laughter', $bad === [], implode(' | ', $bad));
    $who = $nextPax();
    pax($who, 'bholi 1 jana Surat bata');
    pax($who, 'Dipak Rai');
    $r = pax($who, 'paisa pathaidiye');
    check('summary + "paisa pathaidiye": asked for the UTR, and told the booking is NOT made yet (what ho / UPI / no do)', str_contains(txt($r), 'UTR')
        && str_contains(txt($r), 'अझै बनेको छैन') && $r3sold($who) === 0, txt($r));
    pax($who, 'no');
    check('…and "no" after it books nothing', $r3sold($who) === 0 && !WaBooking::isOpen($who));

    section('round 4, A2 (R1): on the desk a question / hedge / laughter is never the "ho" of a complete quote');
    $unlimit();
    desk($AG, '1 seat bholi Surat Ram Thapa ' . $PAXN[15]);
    foreach (['ticket confirm ho?', 'confirm?', 'ho?', 'pakka ho?', 'ticket done?', 'ok hola', 'ha ha'] as $line) {
        $r = desk($AG, $line);
        check('agent quote + "' . $line . '" — not sold, the quote still waits', $r3sold($PAXN[15]) === 0 && (pendingOf($AG)['kind'] ?? '') === 'sale', txt($r));
    }
    desk($AG, 'no');
    desk($CT, '1 seat bholi Surat Mina Thapa ' . $PAXN[16]);
    foreach (['thik cha?', 'ok?'] as $line) {
        $r = desk($CT, $line);
        check('counter quote + "' . $line . '" — not sold, the quote still waits', $r3sold($PAXN[16]) === 0 && (pendingOf($CT)['kind'] ?? '') === 'sale', txt($r));
    }
    desk($CT, 'no');

    section('round 4, A5 (R1): a promise / pause with a day word never re-dates a waiting quote');
    $unlimit();
    $qDate = static fn(string $ph): string => (string) ((pendingOf($ph)['sale']['plan']['date'] ?? '') ?: (pendingOf($ph)['sale']['date'] ?? ''));
    desk($AG, '1 seat parsi Surat Kiran Rai ' . $PAXN[17]);
    foreach (['kal confirm karunga', 'bholi pheri msg garchu', 'customer bholi aaucha'] as $line) {
        $r = desk($AG, $line);
        check('quote for ' . $PARSI . ' + "' . $line . '" keeps the day and waits (never re-quoted for tomorrow)', $qDate($AG) === $PARSI
            && (pendingOf($AG)['kind'] ?? '') === 'sale' && $r3sold($PAXN[17]) === 0, txt($r));
    }
    $r = desk($AG, 'bholi ko garnu');
    check('…while "bholi ko garnu" (a real change) re-quotes it for tomorrow (still waiting for ho)', $qDate($AG) === $TOM && $r3sold($PAXN[17]) === 0, txt($r));
    desk($AG, 'no');

    section('round 4, A3 (R2): other spellings of the payment words never reach a booking or a sale');
    $unlimit();
    $payYes4 = ['UTRno 9812345731', 'TransactionID 9812345732', 'यूटीआर 9812345733', 'paisa pathaidiye 9812345730 bata', 'customer le 9812345741 bata paisa pathaidiyo',
        'maine 9812345734 se payment kar diya', 'refno 412345678901', 'txnid412345678901', 'maine paise bhej diye'];
    $miss = array_values(array_filter($payYes4, static fn(string $s): bool => !WaPayProof::payTalk($s)));
    check('payTalk() reads glued labels, Devanagari UTR and every past "sent" (' . count($payYes4) . ' phrasings)', $miss === [], implode(' | ', $miss));
    $miss = array_values(array_filter(['refund chahiyo', 'ticket pathaidinu', 'mero ticket pathaidinu na', 'payment kasari garne?', 'UPI bata tirchu', 'paisa kasari pathaune'],
        static fn(string $s): bool => WaPayProof::payTalk($s)));
    check('…and still never "refund", "ticket pathaidinu", a pay question or "UPI bata tirchu"', $miss === [], implode(' | ', $miss));
    check('refFrom() reads "UTRno 412345670145" and "TransactionID 412345670146"', WaPayProof::refFrom('UTRno 412345670145') === '412345670145'
        && WaPayProof::refFrom('TransactionID 412345670146') === '412345670146');
    foreach (['Utrno', 'Transactionid', 'यूटीआर', 'Pathaidiye', 'Nakatnu', 'Karunga', 'Ram Thapa Kaatna', 'Driver Dinus'] as $nn) {
        check('PersonName never takes "' . $nn . '" as a passenger', PersonName::clean($nn) === '');
    }
    foreach (['UTRno 9812345731', 'TransactionID 9812345732', 'यूटीआर 9812345733', 'paisa pathaidiye 9812345730 bata'] as $line) {
        $who = $nextPax();
        $r = pax($who, $line);
        $after = pax($who, 'ho');
        check('passenger "' . $line . '": asked for the UTR — no summary, and "ho" sells nothing', str_contains(txt($r), 'UTR') && !str_contains(txt($r), 'नाम:')
            && !str_contains(txt($r), 'Name:') && $r3sold($who) === 0 && $r3sold(substr((string) preg_replace('/\D/', '', $line), -10)) === 0, txt($r) . ' / ' . txt($after));
    }
    desk($CT, '1 seat bholi Surat Ram Thapa ' . $PAXN[18]);
    foreach (['UTRno 9812345743', 'customer ko TransactionID 9812345739'] as $line) {
        $r = desk($CT, $line);
        $s = (array) (pendingOf($CT)['sale'] ?? []);
        check('counter quote + "' . $line . '" never changes its passenger or phone', (string) ($s['phone'] ?? '') === $PAXN[18] && (string) ($s['name'] ?? '') === 'Ram Thapa', txt($r));
    }
    desk($CT, 'no');
    $r = desk($AG, '1 seat bholi Surat Ram Thapa ' . $PAXN[19] . ' UTRno 412345670145');
    check('a sale line with "UTRno …" is no sale (two messages, please)', (pendingOf($AG)['kind'] ?? '') !== 'sale' && str_contains(txt($r), '✂️'), txt($r));

    section('round 4, A4 (R1): with nothing waiting, a line that says no / not now / wait starts nothing');
    $unlimit();
    $agRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $agId]);
    $bk4 = QuickTicket::sell(['name' => 'Gopal Rai', 'phone' => $PAXN[20], 'date' => $TOM, 'direction' => 'toNepal', 'boarding' => 'Surat', 'pay' => 'cash'], $agRow);
    $pnr4 = (string) $bk4['pnr'];
    $bid4 = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr4], 0);
    $stOf = static fn(): string => (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pnr4], '');
    foreach ([[$MGR, $pnr4 . ' cancel nagarnu hai'], [$MGR, "don't cancel " . $pnr4], [$MGR, $pnr4 . ' cancel garnu pardaina'], [$AG, $pnr4 . ' cancel mat karna'],
              [$AG, $pnr4 . ' seat change nahi karna'], [$MGR, $pnr4 . ' phone 9812345790 nahi'], [$CT, $pnr4 . ' cancel pardaina']] as [$from, $line]) {
        $hdBefore = (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE booking_id = :b', ['b' => $bid4], 0);
        $r = desk($from, $line);
        $after = desk($from, 'ho');
        check('"' . $line . '" does nothing (no preview, no request) — the next "ho" changes nothing', $holdTxt($r) && $stOf() === 'confirmed'
            && (string) Database::scalar('SELECT contact_phone FROM bookings WHERE pnr = :p', ['p' => $pnr4], '') === $PAXN[20]
            && (int) Database::scalar('SELECT COUNT(*) FROM support_tickets WHERE booking_id = :b', ['b' => $bid4], 0) === $hdBefore, txt($r) . ' / ' . txt($after));
    }
    AgentWallet::record($agId, 'commission', 900, ['note' => 'wa-desk-sim test r4']);
    $agMoney = static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM ap_deposit_requests WHERE admin_id = :a', ['a' => $agId], 0) + count(AgentWallet::openPayoutRequests($agId));
    $m0 = $agMoney();
    foreach (['withdraw 500 abhi nahi', '500 withdraw nagarnu', "don't withdraw 500", 'jama 700 UTR 412345670401 abhi nahi'] as $line) {
        $r = desk($AG, $line);
        $after = desk($AG, 'ho');
        check('"' . $line . '" files nothing — no preview, and "ho" files nothing', $holdTxt($r) && pendingOf($AG) === null && $agMoney() === $m0, txt($r) . ' / ' . txt($after));
    }
    foreach ([[$AG, '1 seat bholi Surat Ram Thapa ' . $PAXN[21] . ' nakaatnu', $PAXN[21]], [$AG, 'Ram Thapa ' . $PAXN[22] . ' ko 1 seat bholi Surat ticket mat kaatna', $PAXN[22]],
              [$CT, '1 seat bholi Surat Hari Thapa ' . $PAXN[23] . ' wait garnu', $PAXN[23]], [$AG, 'bholi Surat 1 seat Hari Thapa ' . $PAXN[24] . ' chaidaina', $PAXN[24]]] as [$from, $line, $ph]) {
        $r = desk($from, $line);
        desk($from, 'ho');
        check('sale line "' . $line . '" is not quoted — and "ho" sells nothing', $holdTxt($r) && $r3sold($ph) === 0, txt($r));
    }
    foreach (['bholi 1 jana Surat bata ticket nakatnu', 'kal 1 seat Surat se baad me book karunga', 'bholi Surat bata 1 jana ticket chaidaina'] as $line) {
        $who = $nextPax();
        $r = pax($who, $line);
        pax($who, 'ho');
        check('passenger "' . $line . '" starts no summary — and "ho" sells nothing', !WaBooking::isOpen($who) && $r3sold($who) === 0
            && !str_contains(txt($r), 'Nakatnu') && !str_contains(txt($r), 'Karunga'), txt($r));
    }
    $depId = AgentPro::requestDeposit($crdId, 300, 'handover', 'upi', '412345679990', null, 'wa-desk-sim r4');
    $r = desk($MGR, 'jama no ' . $depId . ' bank ma nahi aayo');
    check('a typed office decline ("jama no ' . $depId . ' bank ma nahi aayo") is still the decline preview (its "nahi" is the reason)', (pendingOf($MGR)['kind'] ?? '') === 'dep_decline', txt($r));
    desk($MGR, 'no');
    Database::delete('ap_deposit_requests', 'id = :i', ['i' => $depId]);

    section('round 4, A6 (R3): an office "jama / deposit / withdraw" that names a member never touches the office\'s own account');
    $unlimit();
    AgentWallet::record($mgrId, 'commission', 1000, ['note' => 'wa-desk-sim test r4 (the manager has a wallet)']);
    foreach (['jama 900 UTR 412345670108 (Kamal)', 'deposit 900 for Kamal UTR 412345670111', 'withdraw 700 for Kamal', 'jama 900 UTR 412345670112 Kamal Creditagent ko'] as $line) {
        $r = desk($MGR, $line);
        desk($MGR, 'ho');
        check('office "' . $line . '" — no deposit / withdrawal on the office member\'s own account', !in_array((string) (pendingOf($MGR)['kind'] ?? ''), ['deposit', 'withdraw'], true)
            && $mgrMoney() === 0, txt($r));
    }
    $r = desk($MGR, 'jama 5000 UTR 412345670198');
    check('…while the bare typed "jama 5000 UTR …" is still the office\'s own preview (it names the payer)', (pendingOf($MGR)['kind'] ?? '') === 'deposit' && str_contains(txt($r), 'APWS Office Manager'), txt($r));
    desk($MGR, 'no');
    $mgrRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $mgrId]);
    $offCtx = ['role' => 'admin', 'admin' => $mgrRow, 'adminId' => $mgrId, 'scopeAdminId' => null, 'name' => (string) $mgrRow['full_name'], 'phone' => $MGR,
        'channel' => 'whatsapp', 'raw_text' => 'Kamal ko paisa 700 milayo', 'messageText' => 'Kamal ko paisa 700 milayo', 'turn' => 1, 't0' => microtime(true), 'office' => true];
    applyOverrides();
    foreach ([['intent' => 'withdraw', 'amount' => 700], ['intent' => 'deposit', 'amount' => 700]] as $intent) {
        $r = ApWa::act($intent, $mgrRow, $offCtx, 'ne');
        check('a learnt / AI "' . $intent['intent'] . '" reading for the office never previews its own ' . $intent['intent'], !in_array((string) (pendingOf($MGR)['kind'] ?? ''), ['deposit', 'withdraw'], true)
            && $mgrMoney() === 0, txt($r));
    }
    Database::run('DELETE FROM agent_ledger WHERE agent_admin_id IN (:a, :b)', ['a' => $mgrId, 'b' => $agId]);

    section('round 4, B1 + B3 (R5): no member\'s personal mobile or address to a counter, an agent or a passenger');
    $unlimit();
    AgentWallet::saveProfile($crdId, ['address' => 'Ward 9 Secret Lane']);
    foreach ([[$CT, 'khali seat kati cha bholi ' . $crdCode], [$CT, 'bholi Surat bata khali seat kati cha ani Kamal agent ko number pani'], [$AG, 'khali seat kati cha bholi ' . $crdCode],
              [$CT, 'kati seat baki cha bholi, Kamal ko number pani dinus hai dai']] as [$from, $line]) {
        $r = desk($from, $line);
        check('desk "' . $line . '" answers the seats only — never Kamal\'s mobile or address', !str_contains((string) preg_replace('/\D/', '', txt($r)), $CRD)
            && !str_contains(txt($r), 'Secret Lane') && txt($r) !== '', txt($r));
    }
    foreach ([$crdCode, $crdCode . ' agent ko number k ho'] as $line) {
        $r = pax($nextPax(), $line);
        check('passenger "' . $line . '" (agent not listed publicly) — never the agent\'s mobile or address, the office number instead', !str_contains((string) preg_replace('/\D/', '', txt($r)), $CRD)
            && !str_contains(txt($r), 'Secret Lane') && str_contains(txt($r), Settings::officePhone()), txt($r));
    }

    section('round 4, B2 (R4): negated lines, questions and taking a complaint back open no ticket');
    $unlimit();
    foreach (['gunaso garnu parne kehi chaina, sabai thik thiyo', 'complain garnu parne kura kei chaina', 'ma complain gardina dai, sab thik cha', 'complain garnu pardaina, sab thik thiyo',
              "I don't have any complaints", 'nothing to complain about', 'no one was rude to us', 'nobody was rude', 'the staff was not at all rude', 'shikayat karne ki zarurat nahi hai',
              'hamro driver le gali gardaina', 'staff ko kunai kura naramro lagena', 'extra paisa liyo bhanne kura galat ho', 'complain kaha garne', 'gunaso kaha dine',
              'shikayat kahan karein', 'complain garna milcha', 'complaint number dinus', 'agar AC kharab ho to paise wapas milenge', 'AC kharab bhaye paisa firta hunchha',
              'is the AC broken in your bus', 'maile pahile gareko complain firta linchu', 'complaint cancel gardinus, problem solve bhayo'] as $line) {
        $who = $nextPax();
        $r = pax($who, $line);
        check('"' . $line . '" — no Help Desk ticket; answered with the office number', $tickets($who) === 0 && str_contains(txt($r), Settings::officePhone()), txt($r));
    }
    foreach (['ड्राइवर ने गाली दी, शिकायत दर्ज करो' => 'staff', 'the conductor was rude and shouted at my mother' => 'staff', 'AC kaam nahi kar raha tha, shikayat darj karo' => 'other',
              'AC chalena, complain' => 'other'] as $line => $cat) {
        $who = $nextPax();
        $r = pax($who, $line);
        check('"' . $line . '" IS a complaint: a ' . $cat . ' ticket', str_contains(txt($r), 'HD-')
            && Database::exists('SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = :c', ['p' => '%' . $who, 'c' => $cat]), txt($r));
        if ($line === 'AC chalena, complain') {
            check('…and "AC chalena, complain" is answered in Nepali (B4)', str_contains(txt($r), 'माफ गर्नुहोस्'), txt($r));
        }
    }

    section('round 4, B4: the payment ask in the writer\'s language');
    $r = pax($nextPax(), 'मैंने पैसे भेज दिए UTR 9812345678');
    check('Hindi in Devanagari ("मैंने पैसे भेज दिए UTR 98…") is asked in Hindi', str_contains(txt($r), 'यह मोबाइल नंबर'), txt($r));
    $r = pax($nextPax(), 'મેં પૈસા મોકલ્યા UTR 9812345678');
    check('Gujarati ("મેં પૈસા મોકલ્યા UTR 98…") is asked in Gujarati', str_contains(txt($r), 'મોબાઇલ'), txt($r));

    /* ================================================================= */
    /* Post-deploy probes of release idc6 (31 Oct 2026) — A: actions (R1), B: complaints (R4) and the writer's language. */
    $qi = 0;
    $nextQ = static function () use (&$qi, $Q): string { return $Q[$qi++]; };        // a fresh passenger chat
    $si = 0;
    $nextQS = static function () use (&$si, $QS): string { return $QS[$si++]; };     // a fresh passenger number for a desk sale
    $deva = static fn(string $n): string => strtr($n, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
    $paxName = static fn(string $p): string => (string) Database::scalar('SELECT GROUP_CONCAT(bp.full_name ORDER BY bp.id) FROM booking_passengers bp
        JOIN bookings b ON b.id = bp.booking_id WHERE b.contact_phone = :p', ['p' => $p], '');
    $sellPax = static function (string $who): void { pax($who, 'bholi 1 jana Surat bata'); pax($who, 'Dipak Rai'); pax($who, 'ho'); };

    section('post-deploy A1 (R1): after a WhatsApp sale a "naam …" line renames nothing without a later, separate, clear yes');
    foreach (['naam thik cha', 'नाम ठीक छ', 'naam sahi cha dai', 'naam ta Dipak Rai nai ho ni', 'naam Sita Thapa hoina', 'naam ma kehi problem chaina',
              'naam pachi pathauchu', 'naam sahi hai na?', 'naam Sita Thapa rakhne ki?', 'name ok', 'naam ma Rai hoina Roy ho'] as $line) {
        $who = $nextQ();
        $sellPax($who);
        $r = pax($who, $line);
        $after = pax($who, 'ho');
        check('sold to "Dipak Rai", then "' . $line . '" (+ a later "ho") — the ticket is still "Dipak Rai"', $r3sold($who) === 1 && $paxName($who) === 'Dipak Rai',
            txt($r) . ' / ' . txt($after) . ' / ' . $paxName($who));
    }
    $who = $nextQ();
    $sellPax($who);
    $r = pax($who, 'naam galat bhayo, naam Sita Thapa ho');
    check('"naam galat bhayo, naam Sita Thapa ho" is a PREVIEW — it asks "ho?" and renames nothing yet', $paxName($who) === 'Dipak Rai'
        && str_contains(txt($r), 'Sita Thapa') && str_contains(txt($r), '*ho*') && WaBooking::nameFixWaiting($who), txt($r));
    $r = pax($who, 'ho');
    check('…and the later "ho" renames it and re-sends the ticket', $paxName($who) === 'Sita Thapa' && str_contains(txt($r), 'Sita Thapa')
        && !empty($r['media']) && !WaBooking::nameFixWaiting($who), txt($r));
    $who = $nextQ();
    $sellPax($who);
    $r = pax($who, 'naam Sita Thapa rakhnu');
    check('"naam Sita Thapa rakhnu" (a real correction) is only the preview — the ticket is still "Dipak Rai"', $paxName($who) === 'Dipak Rai'
        && WaBooking::nameFixWaiting($who) && str_contains(txt($r), 'Sita Thapa'), txt($r));
    $r = pax($who, 'no');
    $after = pax($who, 'ho');
    check('preview + "no" keeps the name — and a later "ho" renames nothing', $paxName($who) === 'Dipak Rai' && !WaBooking::nameFixWaiting($who), txt($r) . ' / ' . txt($after));
    foreach (['kata ho', 'ho la', 'ok?', 'ho ' . $QS[59], 'bus kati baje chhutcha', 'ha ha ok'] as $mid) {
        $who = $nextQ();
        $sellPax($who);
        pax($who, 'naam Sita Thapa');
        $r = pax($who, $mid);
        $after = pax($who, 'ho');
        check('preview + "' . $mid . '", then "ho" — renames nothing (the preview answers ONE line, and only a clear yes)', $paxName($who) === 'Dipak Rai',
            txt($r) . ' / ' . txt($after));
    }

    section('post-deploy A2 / A3 / A4 (R1): a number, "kata" (where?), "la" (≈ maybe), laughter or "cash ho" is never the yes of a summary');
    $bad = array_values(array_filter(['ho 9812342020', 'ho 2', 'हो २', 'ok ji 3', 'ok 4400', 'ho 1', 'ok confirm 9812342098', 'kata ho', 'kata ho dai', 'kata',
        'ho la', 'ok ho la', 'ha ha ok', 'haha ok'], static fn(string $s): bool => WaBooking::isYes($s)));
    check('WaBooking::isYes never takes a digit, "kata", "la" or a laugh first', $bad === [], implode(' | ', $bad));
    foreach (['ho {N}', 'ho 2', 'हो २', 'ok ji 3', 'ok 4400', 'kata ho', 'kata ho dai', 'kata', 'ho la', 'ok ho la', 'ha ha ok', 'cash ho', 'upi bata garne ho'] as $tpl) {
        $who = $nextQ();
        $n = $nextQS();
        $line = str_replace('{N}', $n, $tpl);
        pax($who, 'bholi 1 jana Surat bata');
        pax($who, 'Dipak Rai');
        $r = pax($who, $line);
        check('passenger summary + "' . $line . '" — nothing sold, no seat held', $r3sold($who) === 0 && $r3sold($n) === 0, txt($r));
        pax($who, 'no');
    }
    $who = $nextQ();
    pax($who, 'bholi 2 jana Surat bata');
    pax($who, 'Ram Thapa ra Sita Thapa');
    $r = pax($who, 'ho 1');
    check('a 2-name summary + "ho 1" — nothing sold', $r3sold($who) === 0, txt($r));
    pax($who, 'no');
    $who = $nextQ();
    pax($who, 'bholi 1 jana Surat bata');
    pax($who, 'Dipak Rai');
    $r = pax($who, 'ho, cash');
    check('…while "ho, cash" (a yes that opens the line, then the pay word) still sells', $r3sold($who) === 1, txt($r));

    $unlimit();
    foreach ([[$AG, 1, 'ho {N}'], [$CT, 1, 'ok sir {N}'], [$MGR, 1, 'हो {DN}'], [$AG, 1, 'ok confirm {N}'], [$AG, 1, 'ok 2'], [$AG, 1, 'ok ji 3'],
              [$AG, 1, 'ok 4400'], [$AG, 1, 'ok 15'], [$AG, 2, 'ok 1'], [$AG, 1, 'ho la'], [$CT, 1, 'ok ho la'], [$AG, 1, 'ha ha ok']] as [$from, $seats, $tpl]) {
        $ph = $nextQS();
        $n = $nextQS();
        $line = str_replace(['{N}', '{DN}'], [$n, $deva($n)], $tpl);
        desk($from, $seats . ' seat bholi Surat Ram Thapa ' . $ph);
        $r = desk($from, $line);
        check('desk quote (' . $seats . ' seat) + "' . $line . '" — not sold, the quote still waits', $r3sold($ph) === 0 && $r3sold($n) === 0
            && (pendingOf($from)['kind'] ?? '') === 'sale', txt($r));
        desk($from, 'no');
    }
    $ph = $nextQS();
    desk($AG, '1 seat bholi Surat Ram Thapa ' . $ph);
    $r = desk($AG, 'ho ' . $QS[58]);
    check('agent quote + "ho <another number>" re-quotes with that number and waits (never the old number sold)', $r3sold($ph) === 0 && $r3sold($QS[58]) === 0
        && (string) (pendingOf($AG)['sale']['phone'] ?? '') === $QS[58], txt($r));
    desk($AG, 'no');

    section('post-deploy B1 (R4): questions, a negation inside the words, jokes, other companies and praise open no Help Desk ticket');
    foreach (['Where do I complain about the bus', 'where to complain', 'who should I complain to', 'Do you take complaints on WhatsApp', 'complain kasko ma garne',
              'shikayat kisse karein', 'bus ko AC kharab chha ki thik chha', 'bus ka AC kharab hai ya theek hai', 'bus ma toilet fohor huncha ki safa huncha',
              'the seat was not broken', 'the toilet was never dirty', "the AC wasn't broken, the bus was on time",
              'lol the only rude thing was my snoring neighbour, the crew were lovely', 'mazak me bol raha tha ki driver rude hai, actually bahut acche hain',
              'Other buses overcharge, but yours never did. Thanks!', 'sathi le extra paisa liyo bhanyo tara tyo galat raichha', '10/10, zero complaints from my family'] as $line) {
        $who = $nextQ();
        $r = pax($who, $line);
        check('"' . $line . '" — no Help Desk ticket; the office number instead', $tickets($who) === 0 && str_contains(txt($r), Settings::officePhone()), txt($r));
    }
    $r = pax($nextQ(), 'shikayat kisse karein');
    check('…and "shikayat kisse karein" is answered in Hindi', str_contains(txt($r), 'ऑफिस'), txt($r));
    foreach (['the AC was broken the whole night and nobody fixed it' => ['other', 'sorry'], 'Please log my complaint, the driver was rude' => ['staff', 'sorry'],
              'the conductor who checked tickets was rude to my mother' => ['staff', 'sorry']] as $line => [$cat, $mark]) {
        $who = $nextQ();
        $r = pax($who, $line);
        check('"' . $line . '" IS a complaint: a ' . $cat . ' ticket, answered in English', str_contains(txt($r), 'HD-') && str_contains(txt($r), $mark)
            && Database::exists('SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = :c', ['p' => '%' . $who, 'c' => $cat]), txt($r));
    }

    section('post-deploy B2 + B3: the writer\'s language — "।" is not Gujarati; "bata do" is Hindi, "log in" / "mate" English, "wapas" both');
    $wrong = [];
    foreach (['भोलि सुरतबाट बस कति बजे छुट्छ।' => 'ne', 'मलाई भोलि सुरतबाट नेपाल जान २ सिट चाहियो।' => 'ne', 'मुझे कल सूरत से नेपाल के लिए 2 सीट चाहिए।' => 'hi',
              'मेरो सिट कुन हो।' => 'ne', 'बस कब चलेगी?' => 'hi', 'मेरा टिकट फिर से भेजो' => 'hi', 'बस अभी कहाँ पहुँची?' => 'hi',
              'kal ki bus ka time bata do' => 'hi', 'Surat se bus kab chalti hai, bata dijiye' => 'hi', 'Surat se bus kab niklegi, bata do' => 'hi',
              'bus kahan pahunchi bata do' => 'hi', 'ticket cancel garda paisa wapas aauncha?' => 'ne', 'टिकट रद्द गर्दा पैसा वापस आउँछ?' => 'ne',
              "I can't log in, please send my ticket again" => 'en', 'Please log my complaint, the driver was rude' => 'en',
              'Hi mate, I need 2 seats tomorrow from Surat to Nepal' => 'en', 'bholi Surat bata 2 seat chahiyo' => 'ne', 'kal 2 log Surat se jaana hai' => 'hi',
              'સુરતથી બસ ક્યારે છે' => 'gu'] as $line => $want) {
        $got = TicketBot::detectLang($line);
        if ($got !== $want) {
            $wrong[] = $line . ' → ' . $got . ' (want ' . $want . ')';
        }
    }
    check('TicketBot::detectLang reads each writer right (19 lines)', $wrong === [], implode(' | ', $wrong));
    check('WaPayProof::replyLang("मैले पैसा पठाएँ। UTR …") stays Nepali', WaPayProof::replyLang('मैले पैसा पठाएँ। UTR 412345678921', 'ne') === 'ne');
    $who = $nextQ();
    $r = pax($who, 'कन्डक्टरले हामीलाई गाली गर्यो। गुनासो दर्ता गर्नुहोस्।');
    check('a Nepali complaint ending in "।" is answered in Nepali (a staff ticket)', str_contains(txt($r), 'माफ गर्नुहोस्') && str_contains(txt($r), 'HD-')
        && Database::exists('SELECT 1 FROM support_tickets WHERE phone LIKE :p AND category = :c', ['p' => '%' . $who, 'c' => 'staff']), txt($r));
    $r = pax($nextQ(), 'ड्राइवर ने बहुत बदतमीज़ी की। मेरी शिकायत दर्ज कीजिए।');
    check('a Hindi complaint ending in "।" is answered in Hindi', str_contains(txt($r), 'माफ़ कीजिए') && str_contains(txt($r), 'HD-'), txt($r));
    $r = pax($nextQ(), 'भोलि सुरतबाट बस कति बजे छुट्छ।');
    check('"भोलि सुरतबाट बस कति बजे छुट्छ।" gets the time in Nepali', str_contains(txt($r), 'छुट्छ'), txt($r));

    section('post-deploy B4: WaBot\'s own fallback lines in the writer\'s language — and still no other booking\'s details');
    $r = pax($nextQ(), 'Please check my booking ' . $vPnr);
    check('a stranger\'s "Please check my booking ' . $vPnr . '" — the English lock line (status only, never the passenger or number)',
        str_contains(txt($r), '🔒 Booking ' . $vPnr) && !str_contains(txt($r), 'Maya Gurung') && !str_contains((string) preg_replace('/\D/', '', txt($r)), $victim), txt($r));
    $r = pax($nextQ(), $vPnr . ' ko paisa maile tireko chu');
    check('…and in Nepali for a Nepali line', str_contains(txt($r), '🔒 बुकिङ') && !str_contains(txt($r), 'Maya Gurung'), txt($r));
    $r = pax($nextQ(), 'Please send the PNR of Maya Gurung');
    check('"Please send the PNR of Maya Gurung" — the English welcome, never her PNR', str_contains(txt($r), 'ticket assistant') && !str_contains(txt($r), $vPnr), txt($r));
    $r = pax($nextQ(), 'dusri company wale zyada paise lete hain, aap log sahi ho');
    check('a Hindi line gets the Hindi welcome (no complaint ticket)', str_contains(txt($r), 'टिकट सहायक हूँ'), txt($r));

    /* ================================================================= */
    /* Usability leftovers a-g (31 Oct 2026) — closed WITHOUT weakening R1-R5: none of these lines sells, books, renames
       or re-dates by itself; a later, separate, clear yes is still the only sale. */
    $li = 0;
    $nextL = static function () use (&$li, $L): string { return $L[$li++]; };       // a fresh passenger chat
    $lsi = 0;
    $nextLS = static function () use (&$lsi, $LS): string { return $LS[$lsi++]; };  // a fresh passenger number for a desk sale
    $dateAsk = static fn(string $s): bool => str_contains($s, 'कुन मिति') || str_contains($s, 'किस तारीख') || str_contains($s, 'Which date')
        || str_contains($s, 'which date') || str_contains($s, 'कौन सी तारीख');
    $tomLabel = formatDate($TOM, 'D, j M');

    section('leftover a: "ho tara date galat cha" / a correction without the new value asks for it — the booking waits, nothing sells');
    $unlimit();
    $miss = array_values(array_filter(['ho tara date galat cha', 'miti galat bhayo', 'date galat hai', 'wrong date', 'date badalnu cha', 'arko date ma'],
        static fn(string $s): bool => TicketBot::fixWithoutValue($s) !== 'date'));
    check('TicketBot::fixWithoutValue reads a wrong / changed date with no new day (6 phrasings)', $miss === [], implode(' | ', $miss));
    $bad = array_values(array_filter(['date galat, parsi', 'bholi ko garnu', 'ho', 'naam galat, Sita Thapa ho', 'bholi pheri msg garchu', '1 jana galat'],
        static fn(string $s): bool => TicketBot::fixWithoutValue($s) !== ''));
    check('…and never a line that carries the value ("date galat, parsi", "bholi ko garnu") or no correction', $bad === [], implode(' | ', $bad));
    check('…"naam galat cha" asks the name, "kati jana galat" the count, "pickup galat" the pickup', TicketBot::fixWithoutValue('naam galat cha') === 'name'
        && TicketBot::fixWithoutValue('kati jana galat') === 'seats' && TicketBot::fixWithoutValue('pickup galat') === 'boarding');
    foreach (['ho tara date galat cha', 'miti galat bhayo', 'date galat hai', 'wrong date', 'date badalnu cha'] as $line) {
        $who = $nextL();
        pax($who, 'parsi 1 jana Surat bata');
        pax($who, 'Hira Rai');
        $r = pax($who, $line);
        $after = pax($who, 'ho');
        check('passenger summary + "' . $line . '": asked "which date?" — nothing sold, and the "ho" after it only shows the summary again',
            $dateAsk(txt($r)) && $r3sold($who) === 0 && WaBooking::isOpen($who) && !str_contains(txt($after), 'SHG-20'), txt($r) . ' / ' . txt($after));
        pax($who, 'no');
    }
    $who = $nextL();
    pax($who, 'parsi 1 jana Surat bata');
    pax($who, 'Hira Rai');
    pax($who, 'date galat cha');
    $r = pax($who, 'bholi');
    check('…the day that answers it re-plans the summary for that day (still waiting for ho)', str_contains(txt($r), $tomLabel) && $r3sold($who) === 0 && WaBooking::isOpen($who), txt($r));
    pax($who, 'no');
    $who = $nextL();
    pax($who, 'parsi 1 jana Surat bata');
    pax($who, 'Hira Rai');
    pax($who, 'wrong date');
    $r = pax($who, 'no');
    $after = pax($who, 'ho');
    check('…and "no" to "which date?" drops the booking — the later "ho" sells nothing', !WaBooking::isOpen($who) && $r3sold($who) === 0, txt($r) . ' / ' . txt($after));
    foreach ([[$AG, 'ho tara date galat cha'], [$CT, 'date galat bhayo'], [$AG, 'miti galat cha dai']] as [$from, $line]) {
        $ph = $nextLS();
        desk($from, '1 seat parsi Surat Kiran Rai ' . $ph);
        $r = desk($from, $line);
        check('desk quote + "' . $line . '": "kun miti? · which date?" — the quote waits for that day', $dateAsk(txt($r)) && (pendingOf($from)['kind'] ?? '') === 'sale'
            && $qDate($from) === $PARSI && $r3sold($ph) === 0, txt($r));
        $r = desk($from, 'ho');
        check('…the next "ho" is not that day: nothing sold, asked again', str_contains(txt($r), '✋') && $r3sold($ph) === 0 && (pendingOf($from)['kind'] ?? '') === 'sale', txt($r));
        $r = desk($from, 'bholi');
        check('…"bholi" re-quotes it for tomorrow (still waiting for a fresh ho)', $qDate($from) === $TOM && $r3sold($ph) === 0
            && empty(pendingOf($from)['sale']['ask']), txt($r));
        desk($from, 'no');
    }
    $ph = $nextLS();
    desk($AG, '1 seat bholi Surat Kiran Rai ' . $ph);
    $r = desk($AG, 'naam galat cha');
    check('desk quote + "naam galat cha": asks the right name, keeps "Kiran Rai" until it comes', str_contains(txt($r), 'naam Sita Thapa')
        && (pendingOf($AG)['sale']['name'] ?? '') === 'Kiran Rai', txt($r));
    $r = desk($AG, 'Sita Thapa');
    check('…the bare name that answers it renames the passenger (a fresh quote, nothing sold)', (pendingOf($AG)['sale']['name'] ?? '') === 'Sita Thapa' && $r3sold($ph) === 0, txt($r));
    desk($AG, 'no');

    section('leftover b: an innocent "nahi / no" inside a request is a wish, not a pause — only a no to the booking drops it');
    $unlimit();
    $wish = ['non AC nahi chahiyo', 'no luggage', 'window seat nahi chahiye', 'AC ki zarurat nahi', 'without luggage', "don't want a window seat", 'khana chaidaina dai',
        'upper berth nahi', 'no AC', 'samaan chaina'];
    $bad = array_values(array_filter($wish, static fn(string $s): bool => TicketBot::holdOrNo($s) || WaBooking::isNo($s)));
    check('holdOrNo() / isNo() never read a wish about the trip as a no (' . count($wish) . ' phrasings)', $bad === [], implode(' | ', $bad));
    $still = ['AC nahi chahiyo, ticket pani nahi chahiyo', 'no luggage, ruko', 'ticket nahi chahiyo', 'no', 'seat chahidaina', 'non AC nahi chahiyo, abhi nahi',
        'luggage chaina, pachi bhanchu', 'booking nahi chahiye',
        // 31 Oct 2026 (review R1): "<trip thing> mat / nahi <issue verb>" refuses the issue itself
        'AC bus mat kaato', 'AC wala mat karo', 'non AC mat karna', 'sleeper bus mat book karna', 'AC gadi mat kaatnu', 'window seat mat kaato abhi',
        'seater mat banao', 'sleeper mat nikalo', 'double mat kaato', 'single seat mat kaato', 'lower berth mat do', 'AC mat', 'sleeper nahi book karna',
        'AC wali bus mat book karo'];
    $miss = array_values(array_filter($still, static fn(string $s): bool => !TicketBot::holdOrNo($s)));
    check('…while a no to the booking itself — even beside a wish — is still a no (' . count($still) . ' phrasings)', $miss === [], implode(' | ', $miss));
    foreach (['non AC nahi chahiyo', 'no luggage', 'window seat nahi chahiye'] as $line) {
        $who = $nextL();
        pax($who, 'bholi 1 jana Surat bata');
        pax($who, 'Hira Rai');
        $r = pax($who, $line);
        check('passenger summary + "' . $line . '": noted, the summary still waits (not dropped, not sold)', WaBooking::isOpen($who) && $r3sold($who) === 0
            && str_contains(txt($r), 'नोट') && str_contains(txt($r), 'Hira Rai'), txt($r));
        pax($who, 'no');
    }
    $ph = $nextLS();
    desk($AG, '1 seat bholi Surat Kiran Rai ' . $ph);
    foreach (['non AC nahi chahiyo', 'no luggage', 'window seat nahi chahiye dai', 'AC ki zarurat nahi'] as $line) {
        $r = desk($AG, $line);
        check('desk quote + "' . $line . '": noted — the quote still waits, same passenger, nothing sold', (pendingOf($AG)['kind'] ?? '') === 'sale'
            && (pendingOf($AG)['sale']['name'] ?? '') === 'Kiran Rai' && str_contains(txt($r), '👍') && $r3sold($ph) === 0, txt($r));
    }
    $r = desk($AG, 'AC nahi chahiyo, ticket pani nahi chahiyo');
    $after = desk($AG, 'ho');
    check('…"AC nahi chahiyo, ticket pani nahi chahiyo" still drops it — the later "ho" sells nothing', pendingOf($AG) === null && $r3sold($ph) === 0, txt($r) . ' / ' . txt($after));

    section('leftover c: a clear Nepali / Hindi writer is answered in their language — "upi chalcha?", a bare agent code');
    $unlimit();
    $wrong = [];
    foreach (['upi chalcha?' => 'ne', 'esewa chalchha ki?' => 'ne', 'bus kaha pugyo' => 'ne', 'kun bus ho' => 'ne', 'online payment milcha?' => 'ne'] as $line => $want) {
        if (TicketBot::detectLang($line) !== $want) {
            $wrong[] = $line;
        }
    }
    check('TicketBot::detectLang: "upi chalcha?", "esewa chalchha ki?", "kun bus ho" … are Nepali', $wrong === [], implode(' | ', $wrong));
    $r = pax($nextL(), 'upi chalcha?');
    check('passenger "upi chalcha?" — the ways to pay in Nepali', str_contains(txt($r), 'तिर्ने तरिका') && !str_contains(txt($r), 'Ways to pay'), txt($r));
    $who = $nextL();
    pax($who, 'bholi 1 jana Surat bata');
    pax($who, 'Hira Rai');
    $r = pax($who, 'upi chalcha?');
    check('…and on an open summary: answered in Nepali, the summary still waits (nothing sold)', str_contains(txt($r), 'तिर्ने तरिका') && WaBooking::isOpen($who)
        && $r3sold($who) === 0 && str_contains(txt($r), 'अझै बनेको छैन'), txt($r));
    pax($who, 'no');
    $who = $nextL();
    pax($who, 'Nepalgunj ma office cha?');
    $r = pax($who, $crdCode);
    check('a bare agent code after a Nepali line — answered in Nepali (never the agent\'s mobile)', str_contains(txt($r), 'एजेन्ट') && !str_contains(txt($r), 'one of our agents')
        && !str_contains((string) preg_replace('/\D/', '', txt($r)), $CRD), txt($r));
    $who = $nextL();
    pax($who, 'office kahan hai bhai?');
    $r = pax($who, $crdCode);
    check('…and after a Hindi line, not English', !str_contains(txt($r), 'one of our agents') && preg_match('/\p{Devanagari}/u', txt($r)) === 1, txt($r));

    section('leftover d: an open booking answers an unrelated question and keeps waiting — the question is never the name');
    $unlimit();
    foreach (['driver ko number', 'bus kaha pugyo', 'office kaha cha', 'bus kata pugyo?', 'Surat counter ko number'] as $line) {
        $who = $nextL();
        pax($who, 'bholi 1 jana Surat bata');
        $r = pax($who, $line);
        $after = pax($who, 'Hira Rai');
        check('at the name question "' . $line . '" is answered (the booking waits) — the next line is the name', str_contains(txt($r), 'पर्खिरहेको') && WaBooking::isOpen($who)
            && str_contains(txt($after), 'Hira Rai') && !str_contains(mb_strtolower(txt($after)), mb_strtolower($line)) && $r3sold($who) === 0, txt($r) . ' / ' . txt($after));
        pax($who, 'no');
    }
    foreach (['office kaha cha', 'driver ko number', 'bus kaha pugyo'] as $line) {
        $who = $nextL();
        pax($who, 'bholi 1 jana Surat bata');
        pax($who, 'Hira Rai');
        $r = pax($who, $line);
        check('at the summary "' . $line . '" is answered, the summary still waits (told it is NOT booked)', str_contains(txt($r), 'अझै बनेको छैन') && WaBooking::isOpen($who)
            && $r3sold($who) === 0, txt($r));
        pax($who, 'no');
    }
    $who = $nextL();
    pax($who, 'bholi 1 jana Surat bata');
    pax($who, 'bus kaha pugyo');
    $r = pax($who, 'ho');
    check('…a "ho" at the name question after a side answer sells nothing (it is no name)', $r3sold($who) === 0, txt($r));
    pax($who, 'no');

    section('leftover e: "naam X ho, arko Y" names two travellers');
    $unlimit();
    foreach (['mero naam Ram Thapa ho, arko Sita Thapa', 'Ram Thapa ra arko Sita Thapa', 'ek jana Ram Thapa, arko ko naam Sita Thapa', 'mera naam Ram Thapa hai aur dusra Sita Thapa',
              'pahilo Ram Thapa dosro Sita Thapa'] as $line) {
        $who = $nextL();
        pax($who, 'bholi 2 seat Surat bata');
        $r = pax($who, $line);
        check('2 seats + "' . $line . '" → Ram Thapa and Sita Thapa on the summary (nothing sold)', str_contains(txt($r), '1. Ram Thapa') && str_contains(txt($r), '2. Sita Thapa')
            && $r3sold($who) === 0, txt($r));
        pax($who, 'no');
    }
    $who = $nextL();
    pax($who, 'bholi 1 seat Surat bata');
    $r = pax($who, 'naam Ram Thapa ho, arko Sita Thapa');
    check('1 seat + "naam Ram Thapa ho, arko Sita Thapa" → a 2-traveller summary to say yes to', str_contains(txt($r), '2. Sita Thapa') && $r3sold($who) === 0, txt($r));
    pax($who, 'no');
    $ph = $nextLS();
    desk($AG, '2 seat bholi Surat Kiran Rai ' . $ph);
    $r = desk($AG, 'naam Ram Thapa ho, arko Sita Thapa');
    check('desk quote + "naam Ram Thapa ho, arko Sita Thapa" → passengers Ram Thapa, Sita Thapa (never "arko Sita Thapa")',
        (array) (pendingOf($AG)['sale']['names'] ?? []) === ['Ram Thapa', 'Sita Thapa'] && $r3sold($ph) === 0, txt($r));
    desk($AG, 'no');
    // 31 Oct 2026 (review): "arko / aru / other" only as a WORD - never inside "Tarun", "Charu" or "mother"
    $ph = $nextLS();
    desk($AG, '1 seat bholi Surat Kiran Rai ' . $ph);
    foreach (['naam Tarun Thapa' => 'Tarun Thapa', 'naam Charu Thapa' => 'Charu Thapa', 'naam Varun Shah' => 'Varun Shah'] as $line => $want) {
        $r = desk($AG, $line);
        check('desk quote + "' . $line . '" → passenger "' . $want . '", still 1 seat (no split inside the name)', (pendingOf($AG)['sale']['name'] ?? '') === $want
            && (int) (pendingOf($AG)['sale']['seats'] ?? 0) === 1 && $r3sold($ph) === 0, txt($r));
    }
    $r = desk($AG, 'names Tarun, Sita');
    check('desk quote + "names Tarun, Sita" → Tarun and Sita, 2 seats', (array) (pendingOf($AG)['sale']['names'] ?? []) === ['Tarun', 'Sita']
        && (int) (pendingOf($AG)['sale']['seats'] ?? 0) === 2 && $r3sold($ph) === 0, txt($r));
    desk($AG, 'no');
    foreach (['Tarun Thapa ra arko Sita Thapa' => ['Tarun Thapa', 'Sita Thapa'], 'Charu Thapa, arko Sita Rai' => ['Charu Thapa', 'Sita Rai']] as $line => [$n1, $n2]) {
        $who = $nextL();
        pax($who, 'bholi 2 seat Surat bata');
        $r = pax($who, $line);
        check('2 seats + "' . $line . '" → ' . $n1 . ' and ' . $n2 . ' only (nothing sold)', str_contains(txt($r), '1. ' . $n1) && str_contains(txt($r), '2. ' . $n2)
            && !str_contains(txt($r), '3. ') && $r3sold($who) === 0, txt($r));
        pax($who, 'no');
    }
    // 31 Oct 2026 (review, leftover a for passengers): "naam galat cha" asks the name - never renames the passenger "galat cha"
    $who = $nextL();
    pax($who, 'bholi 1 jana Surat bata');
    pax($who, 'Hira Rai');
    $r = pax($who, 'naam galat cha');
    $after = pax($who, 'ho');
    check('passenger summary + "naam galat cha": the name is asked, never "galat cha"; the "ho" after it sells nothing',
        !str_contains(mb_strtolower(txt($r) . txt($after)), 'galat cha') && $r3sold($who) === 0 && WaBooking::isOpen($who), txt($r) . ' / ' . txt($after));
    $r = pax($who, 'Sita Thapa');
    check('…the name that answers it goes on the summary (still waiting for ho)', str_contains(txt($r), 'Sita Thapa') && $r3sold($who) === 0 && WaBooking::isOpen($who), txt($r));
    pax($who, 'no');

    section('leftover f: one more traveller mid-booking = one more seat, and the new name is asked (never sold without it)');
    $unlimit();
    $wrong = [];
    foreach (['arko 1 jana thapnu' => 1, 'arko 1 extra seat chahiyo' => 1, 'ek jana aru thap' => 1, 'one more seat' => 1, 'aur ek seat chahiye' => 1, 'add 1 seat' => 1,
              '2 jana thapdinu' => 2, 'ek jana Ram Thapa, arko ko naam Sita Thapa' => 0, 'Gopal Thapa 2 jana' => 0, 'arko seat dinus' => 0, 'arko din' => 0, 'extra luggage' => 0, 'arko 1 jana thapnu tara ruko' => 0, 'arko 1 jana thapnu?' => 0] as $line => $n) {
        if (TicketBot::extraSeats($line) !== $n) {
            $wrong[] = $line . ' → ' . TicketBot::extraSeats($line);
        }
    }
    check('TicketBot::extraSeats reads an added traveller — never "arko seat" (a different seat), another day, luggage, a pause or a question', $wrong === [], implode(' | ', $wrong));
    $who = $nextL();
    pax($who, 'bholi 1 seat Surat bata');
    $r = pax($who, 'arko 1 extra seat chahiyo');
    check('at the name question "arko 1 extra seat chahiyo" → 2 seats, both names asked', str_contains(txt($r), '2 जनाकै') && WaBooking::isOpen($who), txt($r));
    $r = pax($who, 'Ram Thapa, Sita Thapa');
    check('…and the two names give a 2-seat summary (nothing sold)', str_contains(txt($r), '2. Sita Thapa') && $r3sold($who) === 0, txt($r));
    pax($who, 'no');
    foreach (['arko 1 jana thapnu', 'ek jana aru thap', 'one more seat'] as $line) {
        $who = $nextL();
        pax($who, 'bholi 1 seat Surat bata');
        pax($who, 'Ram Thapa');
        $r = pax($who, $line);
        $yes = pax($who, 'ho');
        check('summary + "' . $line . '": a seat is added and the NEW name asked — the "ho" before it sells nothing', str_contains(txt($r), '➕')
            && str_contains(txt($r), 'Ram Thapa') && $r3sold($who) === 0 && WaBooking::isOpen($who), txt($r) . ' / ' . txt($yes));
        if ($line === 'arko 1 jana thapnu') {
            $r = pax($who, 'Sita Thapa');
            check('…the new name joins Ram Thapa on a 2-seat summary (Ram Thapa stays first)', str_contains(txt($r), '1. Ram Thapa') && str_contains(txt($r), '2. Sita Thapa')
                && $r3sold($who) === 0, txt($r));
            pax($who, 'ho');
            check('…and only the later, separate "ho" sells it — to both travellers', $r3sold($who) === 1 && $paxName($who) === 'Ram Thapa,Sita Thapa', $paxName($who));
        } else {
            pax($who, 'no');
        }
    }
    $ph = $nextLS();
    desk($AG, '1 seat bholi Surat Kiran Rai ' . $ph);
    $r = desk($AG, 'arko 1 jana thapnu');
    check('desk quote + "arko 1 jana thapnu" → 2 seats, the added name asked', (int) (pendingOf($AG)['sale']['seats'] ?? 0) === 2 && (pendingOf($AG)['sale']['ask'] ?? '') === 'names'
        && str_contains(txt($r), 'Kiran Rai') && $r3sold($ph) === 0, txt($r));
    foreach (['ho', 'ok ticket kaat do'] as $line) {
        $r = desk($AG, $line);
        check('…"' . $line . '" before the added name issues nothing (asked for the name)', str_contains(txt($r), '✋') && $r3sold($ph) === 0 && (pendingOf($AG)['kind'] ?? '') === 'sale', txt($r));
    }
    $r = desk($AG, 'Sita Thapa');
    check('…"Sita Thapa" completes it: Kiran Rai + Sita Thapa, a fresh quote waiting for ho', (array) (pendingOf($AG)['sale']['names'] ?? []) === ['Kiran Rai', 'Sita Thapa']
        && empty(pendingOf($AG)['sale']['ask']) && $r3sold($ph) === 0, txt($r));
    desk($AG, 'no');

    section('leftover g: a sale line that ends in a question is answered with availability — never quoted as if ordered');
    $unlimit();
    foreach (['bholi Surat bata 2 jana ko ticket milcha?', 'bholi 2 seat Surat bata milcha?', 'parsi Surat bata 3 jana milchha', 'kal Surat se 2 log ka ticket milega?'] as $line) {
        $who = $nextL();
        $r = pax($who, $line);
        $after = pax($who, 'ho');
        check('passenger "' . $line . '" → the free seats, no booking opened — and "ho" sells nothing', str_contains(txt($r), '💺') && !WaBooking::isOpen($who)
            && $r3sold($who) === 0, txt($r) . ' / ' . txt($after));
    }
    foreach (['milcha?', 'painchha?', 'available?', 'milega kya?'] as $tail) {
        $ph = $nextLS();
        $r = desk($AG, '1 seat bholi Surat Kiran Rai ' . $ph . ' ' . $tail);
        check('desk "1 seat bholi Surat Kiran Rai … ' . $tail . '" → "yes, available" (not a 🎫 quote) — nothing sold', str_contains(txt($r), '✅')
            && !str_contains(txt($r), '🎫') && $r3sold($ph) === 0, txt($r));
        desk($AG, 'no');
    }
    $ph = $nextLS();
    desk($CT, '1 seat bholi Surat Kiran Rai ' . $ph . ' milcha?');
    $r = desk($CT, 'ho');
    check('…the availability answer is sold only by the later, separate "ho"', $r3sold($ph) === 1, txt($r));
} finally {
    $cleanup();
}

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL > 0 ? 1 : 0);
