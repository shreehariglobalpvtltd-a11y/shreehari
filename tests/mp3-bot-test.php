<?php
/**
 * =====================================================================
 *  mp3-bot-test.php — the chatbot answers what was asked (25 Nov 2026, mp3-bot).
 *
 *   A directory: one source of truth (CounterDesk::publicOffices + CompanyLocations + the published CEO / Nepal
 *     lines + the public agent directory); every public place's number is answered for its town; a desk that is
 *     not public is shown to staff only; the CEO line follows wa_dir_ceo_public_on
 *   B real-shaped questions from the 15-25 Nov history (masked) reach an answer, never a booking prompt or a draft;
 *     a machine's auto-reply gets NO reply, no draft, no sale; a question in the middle of an open booking is
 *     answered and the booking keeps waiting; Devanagari / Roman Nepali / Hindi / English are mirrored
 *   C PNR: the customer's own number -> the card; another number -> status + the last-4-digits check (wrong,
 *     right, 3 wrong = locked); a walk-in placeholder never challenged; "PNR 963" short form; an agent only their
 *     own sale, the office any
 *   D name search: refused for a customer, the office the whole register (masked 2+3), an agent / counter only
 *     their own sales (last 3 digits); every PNR / name search is in ai_agent_calls with its result count
 *   E the desk: "Chalni deu", "K xa halkhbr", "<name> ka xan", "Nepalgunj office ko number?", luggage — answered;
 *     an unknown question gets the honest line (never the "1️⃣ टिकट बुक" menu); "Helo" still the menu
 *   F a ticket-menu button from a seller goes to WaEdit (never "नयाँ मान बुझिएन")
 *   G the ticket text fallback prints each value under its own label (P6)
 *   H the AI layer: directory_lookup / pnr_lookup / name_search with the same gates, the read-only hand-off,
 *     the "answer what was asked" rule in the chat briefings (the website briefing unchanged)
 *   I switches off = the old paths
 *
 *    php -c .claude/php-dev.ini tests/mp3-bot-test.php
 *  Nothing leaves this machine (Meta constants empty, transports captured, AI keys blank, settings in memory only).
 *  Its own admins / bookings / counter (phones 70000933xx, 98123933xx) are removed at the end.
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
          'wabooking', 'wabot', 'waroute', 'waagentroute', 'aitools', 'aiagent', 'aiprompt', 'counterdesk', 'companylocations',
          'wadirectory', 'walookup', 'waanswer', 'waedit', 'sitefacts'] as $f) {
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
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? ' — ' . str_replace("\n", ' ⏎ ', mb_substr($extra, 0, 500)) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }

$SENT = [];
WaRoute::$transport = static function (string $to, string $text, ?string $img = null) use (&$SENT): array {
    $SENT[] = $to;
    return ['success' => true, 'message_id' => 'wamid.MP3' . count($SENT), 'error' => ''];
};
WaAgentRoute::$transport = static function (string $to, string $text, ?string $img = null, ?array $ui = null) use (&$SENT): array {
    $SENT[] = $to;
    return ['success' => true, 'message_id' => 'wamid.MP3' . count($SENT), 'error' => ''];
};

/* ---- settings, in memory only ---- */
$OVR = ['whatsapp_driver' => 'click_to_chat', 'wa_cloud_fallback_on' => '0', 'aisensy_api_key' => '', 'wa_meta_templates_on' => '0',
    'email_enabled' => '0', 'push_enabled' => '0', 'staffmsg_push_on' => '0', 'sms_enabled' => '0', 'sms_send_otp' => '0',
    'wa_agent_on' => '0', 'wa_ai_enabled' => '0', 'ap_ai_on' => '0', 'ai_manager_enabled' => '0', 'ai_manager_whatsapp' => '0',
    'gemini_api_key' => '', 'anthropic_api_key' => '', 'openai_api_key' => '', 'grok_api_key' => '', 'openrouter_api_key' => '', 'cerebras_api_key' => '',
    'ai_local_on' => '0', 'wa_faq_on' => '1', 'wa_local_first' => '1', 'wa_local_brain_on' => '1', 'ap_wa_on' => '1', 'ap_wa_sell' => '1',
    'ap_wa_admin_on' => '1', 'agent_kyc_required' => '0', 'helpdesk_on' => '0', 'ap_learn_fast' => '0', 'wa_ops_stepup_on' => '0',
    'whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'wa_instant_sale' => 'all', 'wa_short_booking_on' => '1',
    'wa_answer_first_on' => '1', 'wa_directory_on' => '1', 'wa_lookup_on' => '1', 'wa_pnr_last4_on' => '1', 'wa_auto_sender_guard_on' => '1',
    'wa_dir_ceo_public_on' => '1', 'wa_draft_only_bookings_on' => '1', 'ap_wa_answer_first_on' => '1', 'ap_wa_cloud_fallback_on' => '1',
    'ap_wa_honest_fallback_on' => '1', 'ap_wa_edit_yield_on' => '1', 'wa_ticket_text_fix_on' => '1', 'ai_answer_asked_rule_on' => '1',
    'ai_kb_on' => '0', 'wa_login_on' => '0'];
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
/** One message through the whole bot, as the webhook sends it. */
function wa(string $from, string $text, string $kind = 'text'): array { applyOverrides(); return WaBot::reply('+91' . $from, $text, $kind, []); }

const OWN = '7000093301';     // superadmin (the owner's number, not OTP-linked — like live)
const AG1 = '7000093302';     // agent 1
const AG2 = '7000093303';     // agent 2
const C1  = '9812393301';     // passenger 1 (booking B1)
const C2  = '9812393302';     // passenger 2 (booking B2)
const STR = '9812393309';     // a stranger
$CUST = array_map(static fn(int $i): string => '98123933' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(10, 60));
const DESK = 'ZMP3';

$cleanup = static function () use ($CUST): void {
    $phones = array_merge([C1, C2, STR, OWN, AG1, AG2, '0000000000'], $CUST);
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'SHG-2026-9933%' OR contact_phone IN ('" . implode("','", array_merge([C1, C2, STR], $CUST)) . "')
                                  OR sold_by_admin_id IN (SELECT id FROM admins WHERE username LIKE 'mp3bot\\_%')") as $b) {
        $bid = (int) $b['id'];
        try { Seats::releaseBooking($bid); } catch (Throwable $e) {}
        foreach (Database::fetchAll("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'booking_id' AND TABLE_NAME <> 'bookings'") as $r) {
            try { Database::run('DELETE FROM `' . $r['t'] . '` WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('bookings', 'id = :b', ['b' => $bid]); } catch (Throwable $e) {}
    }
    foreach ($phones as $p) {
        if ($p === '0000000000') { continue; }
        try { Database::run('DELETE FROM brain_drafts WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM ai_agent_calls WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM support_tickets WHERE phone LIKE :p', ['p' => '%' . $p]); } catch (Throwable $e) {}
        Database::run('DELETE FROM kv_store WHERE kkey LIKE :p', ['p' => '%' . $p . '%']);
        Database::run('DELETE FROM ap_wa_state WHERE phone = :p', ['p' => $p]);
        Database::run('DELETE FROM rate_limits WHERE identifier LIKE :p', ['p' => '%' . $p . '%']);
    }
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'mp3bot\\_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ai_tool_log' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::run('DELETE FROM counter_locations WHERE code = :c', ['c' => DESK]); } catch (Throwable $e) {}
    CounterDesk::flush();
};
$cleanup();
register_shutdown_function($cleanup);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'wa\\_%' OR bucket LIKE 'ap\\_%'");

echo "\n=== mp3-bot: the chatbot answers what was asked ===\n\n";

/* ---- fixtures ---- */
$mk = static function (string $user, string $name, string $phone, string $role, string $area = 'Surat'): int {
    $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    AgentWallet::saveProfile($id, ['counter_name' => $area, 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
$ownId = $mk('mp3bot_owner', 'MP3 Owner Bishwokarma', OWN, 'superadmin');
$ag1 = $mk('mp3bot_ag1', 'MP3 Dalbir Agentone', AG1, 'agent');
AgentWallet::setAgentCode($ag1, AgentWallet::nextFreeAgentCode('person'));
$ag2 = $mk('mp3bot_ag2', 'MP3 Sunil Agenttwo', AG2, 'agent', 'Nepalgunj');
AgentWallet::setAgentCode($ag2, AgentWallet::nextFreeAgentCode('person'));

$sched = Database::fetch("SELECT s.id, s.travel_date, r.from_city, r.to_city FROM schedules s JOIN routes r ON r.id = s.route_id
                           WHERE s.travel_date >= DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND r.is_active = 1 AND s.is_blocked = 0
                           ORDER BY s.travel_date, r.sort_order LIMIT 1");
check('a future departure exists for the fixtures', $sched !== null);
$n = 0;
$book = static function (string $phone, int $seller, array $names, string $status = 'confirmed') use (&$n, $sched): array {
    $n++;
    $pnr = 'SHG-2026-9933' . $n;
    $id = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => '91', 'status' => $status,
        'total_amount' => 2200 * count($names), 'sold_by_admin_id' => $seller ?: null, 'booked_by_admin_id' => $seller ?: null,
        'confirmed_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
    $leg = (int) Database::insert('booking_legs', ['booking_id' => $id, 'schedule_id' => (int) $sched['id'], 'leg_type' => 'outbound',
        'travel_date' => (string) $sched['travel_date'], 'boarding_stop' => 'Surat · Kamrej @ 13:00', 'boarding_time' => '13:00:00',
        'fare_per_seat' => 2200, 'seat_count' => count($names), 'leg_total' => 2200 * count($names)]);
    foreach ($names as $i => $nm) {
        Database::insert('booking_passengers', ['booking_id' => $id, 'leg_id' => $leg, 'passenger_ref' => 'PAX-MP3-' . $n . $i, 'seat_no' => 'U' . (20 + $n * 2 + $i),
            'full_name' => $nm, 'gender' => 'Male', 'is_primary' => $i === 0 ? 1 : 0]);
    }
    return ['id' => $id, 'pnr' => $pnr];
};
$B1 = $book(C1, $ag1, ['Ramesh Bahadur Thapa', 'Sita Thapa']);
$B2 = $book(C2, $ag2, ['Ramesh Kumar Shah']);
$B3 = $book('0000000000', $ag1, ['Walkin Person Mp']);
$audit = static fn(string $phone, string $tool): array => Database::fetchAll('SELECT ok, detail FROM ai_agent_calls WHERE phone = :p AND tool = :t ORDER BY id', ['p' => $phone, 't' => $tool]);
$isMenu = static fn(string $t): bool => str_contains($t, '1️⃣') || str_contains($t, 'टिकट बुक —');
$bookPrompt = static fn(string $t): bool => (bool) preg_match('/कति सिट|kati seat|how many seats|कितनी सीट|which date|कुन मिति|desk will (confirm|send)|डेस्कले|अनुरोध प्राप्त/iu', $t);

/* ===================================================================== */
section('A directory: one source of truth from the live tables');
$offices = CounterDesk::publicOffices();
foreach ($offices as $o) {
    if ((string) $o['phone'] === '') { continue; }
    $r = WaDirectory::lookup($o['city'] . ' office ko number?', 'customer', 'rn');
    check('A "' . $o['city'] . ' office ko number?" carries that office\'s number ' . $o['phone'], $r !== null && str_contains((string) $r['text'], (string) $o['phone']), (string) ($r['text'] ?? 'null'));
}
foreach (CompanyLocations::forPublic() as $p) {
    if (trim($p['phone']) === '' || WaDirectory::townKeyOf($p['town']) === '') { continue; }
    $r = WaDirectory::lookup($p['town'] . ' ' . ($p['kind'] === 'parking' ? 'parking' : ($p['kind'] === 'pickup' ? 'pickup' : 'office')) . ' ko number', 'customer', 'rn');
    check('A the public place "' . $p['name'] . '" is answered with its number ' . $p['phone'], $r !== null && str_contains((string) $r['text'], trim($p['phone'])), (string) ($r['text'] ?? 'null'));
}
$npj = WaDirectory::lookup('Nepalgunj office ko number?', 'customer', 'rn');
check('A "Nepalgunj office ko number?" names the in-charge and a map link', $npj !== null && str_contains($npj['text'], 'maps') && str_contains($npj['text'], 'Sampark'), (string) ($npj['text'] ?? ''));
$ceo = SiteFacts::all()['ceo'];
$r = WaDirectory::lookup('CEO ko number', 'customer', 'rn');
check('A "CEO ko number" — the CEO\'s name + the line the website publishes', $r !== null && str_contains($r['text'], (string) $ceo['name']) && ((string) $ceo['phone'] === '' || str_contains($r['text'], (string) $ceo['phone'])), (string) ($r['text'] ?? ''));
$r = WaDirectory::lookup('CEO ko personal number', 'customer', 'rn');
check('A a PERSONAL number is still refused (the office line instead)', $r !== null && str_contains($r['text'], 'Niji number') && str_contains($r['text'], Settings::officePhone()), (string) ($r['text'] ?? ''));
setOvr('wa_dir_ceo_public_on', '0');
$r = WaDirectory::lookup('CEO ko number', 'customer', 'rn');
check('A wa_dir_ceo_public_on = 0: the old "no personal numbers" answer', $r !== null && str_contains($r['text'], 'Niji number'), (string) ($r['text'] ?? ''));
setOvr('wa_dir_ceo_public_on', '1');
$r = WaDirectory::lookup('Ahmedabad pickup kaha?', 'customer', 'rn');
check('A "Ahmedabad pickup kaha?" — the Ahmedabad pickup / parking places', $r !== null && $r['hits'] > 0 && preg_match('/chiloda|ahmedabad|paldi/i', $r['text']) === 1, (string) ($r['text'] ?? ''));
$r = WaDirectory::lookup('Nepalgunj agent', 'staff', 'rn');
check('A "Nepalgunj agent" (staff) lists the Nepalgunj agent by name + code, never the phone', $r !== null && str_contains($r['text'], 'MP3 Sunil Agenttwo') && !str_contains($r['text'], AG2), (string) ($r['text'] ?? ''));
$r = WaDirectory::lookup('Nepalgunj agent', 'admin', 'rn');
check('A …the office also sees the agent\'s number', $r !== null && str_contains($r['text'], AG2), (string) ($r['text'] ?? ''));
$r = WaDirectory::lookup('Nepalgunj agent', 'customer', 'rn');
check('A …a passenger never sees a private roster phone', $r !== null && !str_contains($r['text'], AG2), (string) ($r['text'] ?? ''));
Database::insert('counter_locations', ['code' => DESK, 'name' => 'Palanpur — ZZ Desk', 'country' => 'IN', 'currency' => 'INR', 'fx_rate' => 1,
    'entity' => 'IN', 'is_active' => 1, 'phone' => '+91 70000 93399', 'contact_person' => 'ZZ Mp3 Incharge', 'show_public' => 0]);
CounterDesk::flush();
$r = WaDirectory::lookup('Palanpur counter ko number', 'staff', 'rn');
check('A a desk that is NOT public: staff see it with its in-charge', $r !== null && str_contains($r['text'], '70000 93399') && str_contains($r['text'], 'ZZ Mp3 Incharge'), (string) ($r['text'] ?? ''));
$r = WaDirectory::lookup('Palanpur counter ko number', 'customer', 'rn');
check('A …a passenger does not', $r === null || !str_contains($r['text'], '70000 93399'), (string) ($r['text'] ?? ''));
check('A a trip line is never a directory question', !WaDirectory::isQuestion('bholi Surat bata 2 seat chahiyo') && !WaDirectory::isQuestion('bus kaha pugyo'));

/* ===================================================================== */
section('B questions from the live history (masked): an answer, never a booking prompt');
$ci = 0;
$next = static function () use (&$ci, $CUST): string { return $CUST[$ci++]; };
$draftsOf = static fn(string $p): int => (int) Database::scalar('SELECT COUNT(*) FROM brain_drafts WHERE phone LIKE :p', ['p' => '%' . $p], 0);
$samples = [
    ['Nepalgunj office ko number?', '/9866201375|986-6201375/', 'directory'],
    ['Ahmedabad pickup kaha?', '/chiloda|ahmedabad/i', 'directory'],
    ['CEO ko number', '/' . preg_quote((string) $ceo['name'], '/') . '/', 'directory'],
    ['office kaha cha', '/📍|🏢/u', 'directory'],
    ['Mehsana parking ko location', '/Mehsana/i', 'directory'],
    ['Surat counter ko number dinu', '/Surat/i', 'directory'],
    ['luggage kati kg samma lana milcha?', '/🧳/u', 'wafaq'],
    ['border ma k document chahincha?', '/🪪/u', 'wafaq'],
    ['रुपैडिहा पार्किङ कहाँ छ?', '/रुपैडिहा|Rupaidiha|📞/u', 'directory'],
    ['Where is your Nepalgunj office?', '/Nepalgunj/i', 'directory'],
    ['bholi office khulcha?', '/9104801507|91048 01507|office|अफिस/iu', ''],
    ['aaj mausam kasto cha hola?', '/./u', ''],
];
foreach ($samples as [$q, $re, $router]) {
    $p = $next();
    $r = wa($p, $q);
    check('B "' . $q . '" -> answered' . ($router !== '' ? ' (' . $router . ')' : '') . ', no booking prompt, no draft',
        preg_match($re, txt($r)) === 1 && !$bookPrompt(txt($r)) && $draftsOf($p) === 0 && ($router === '' || WaBot::$lastRouter === $router),
        WaBot::$lastRouter . ' :: ' . txt($r));
}
// machines (P1)
foreach (['*Thank you for using LIC\'s WhatsApp Services* 🙏 If you need any help in future, just type Hi . We will be happy to assist you. Android app : https://play.google.com/store/apps/details?id=com.lic.x',
          'Thank you for contacting zzfastfood! Please let us inquiry on 9726401599 .', 'We are Always For Your support,'] as $q) {
    $p = $next();
    $before = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => '9726401599']);
    $r = wa($p, $q);
    check('B an auto-reply ("' . mb_substr($q, 0, 34) . '…") gets NO reply, no draft, no ticket',
        WaBot::$silent && txt($r) === '' && $draftsOf($p) === 0 && (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => '9726401599']) === $before,
        WaBot::$lastRouter . ' :: ' . txt($r));
}
$r = wa($next(), '', 'unsupported');
check('B an unsupported message type with no words — silent', WaBot::$silent && txt($r) === '', txt($r));
$r = wa($next(), 'Thank you for the ride, 2 seat bholi Surat bata chahiyo');
check('B a real passenger line that happens to say "thank you" is answered as usual', !WaBot::$silent && txt($r) !== '', txt($r));
// mid-booking
$p = $next();
wa($p, 'bholi 2 seat Surat bata');
$open = WaBooking::isOpen(normalisePhone('+91' . $p));
$r = wa($p, 'office ko number k ho?');
check('B mid-booking "office ko number k ho?" — answered, the booking keeps waiting (said under it)',
    $open && preg_match('/📞|🏢/u', txt($r)) === 1 && WaBooking::isOpen(normalisePhone('+91' . $p)) && txt($r) !== WaBooking::waitingNote(normalisePhone('+91' . $p)), WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa($p, 'luggage kati kg?');
check('B mid-booking "luggage kati kg?" — the luggage rule + the waiting line', str_contains(txt($r), '🧳') && WaBooking::isOpen(normalisePhone('+91' . $p)), WaBot::$lastRouter . ' :: ' . txt($r));
$sold = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone = :p AND status = 'confirmed'", ['p' => normalisePhone('+91' . $p)]);
check('B …and nothing was sold by those questions', $sold === 0);
// language mirror
$r = wa($next(), 'नेपालगञ्ज अफिसको नम्बर के हो?');
check('B Devanagari Nepali -> Devanagari Nepali labels', str_contains(txt($r), 'ठेगाना र सम्पर्क') || str_contains(txt($r), 'मुख्य अफिस'), txt($r));
$r = wa($next(), 'Nepalgunj office ko number dinus');
check('B Roman Nepali -> Roman Nepali labels', str_contains(txt($r), 'thegana ra sampark') || str_contains(txt($r), 'Mukhya office'), txt($r));
$r = wa($next(), 'नेपालगंज ऑफिस का नंबर क्या है?');
check('B Hindi -> Hindi labels', str_contains(txt($r), 'पता और संपर्क') || str_contains(txt($r), 'मुख्य ऑफिस'), txt($r));
$r = wa($next(), 'What is the phone number of the Nepalgunj office?');
check('B English -> English labels', str_contains(txt($r), 'address and contact') || str_contains(txt($r), 'Head office'), txt($r));
$r = wa($next(), 'what is the capital of mars? tell me now');
check('B an unknown English question: an honest English line + the office, no "send the date and seats"',
    preg_match('/do not have a sure answer|office will answer/', txt($r)) === 1 && str_contains(txt($r), Settings::officePhone()) && !$bookPrompt(txt($r))
    && !str_contains(txt($r), 'how many people'), WaBot::$lastRouter . ' :: ' . txt($r));
check('B the welcome shows the real PNR shape, never SHG-XXXX', !str_contains(WaBot::greeting(), 'XXXX') && str_contains(WaBot::greeting(), 'SHG-2026-'));

/* ===================================================================== */
section('C PNR: own number / last 4 digits / staff scope');
$r = wa(C1, $B1['pnr']);
check('C the booking\'s own number -> the full card (route, date, boarding + time, seats, paid, ticket link)',
    str_contains(txt($r), (string) $sched['from_city']) && str_contains(txt($r), '13:00') && str_contains(txt($r), 'U') && str_contains(txt($r), 'download-ticket.php') && $r['media'] !== null,
    WaBot::$lastRouter . ' :: ' . txt($r));
$r = wa(C1, 'Hi ' . $B1['pnr']);
check('C "Hi SHG-…" is the same one card (never a second contradicting reply)', str_contains(txt($r), 'download-ticket.php') && substr_count(txt($r), 'PNR:') === 1, txt($r));
$r = wa(C1, 'PNR ' . substr($B1['pnr'], -5));
check('C the short form "PNR 9933x" resolves to the same booking', str_contains(txt($r), $B1['pnr']) && str_contains(txt($r), 'download-ticket.php'), txt($r));
$r = wa(STR, $B1['pnr']);
check('C another number -> status only + the last-4-digits question (no name, no seat, no number)',
    str_contains(txt($r), '🔒') && str_contains(txt($r), '4') && !str_contains(txt($r), 'Ramesh') && !str_contains(txt($r), C1) && $r['media'] === null, txt($r));
$r = wa(STR, '1111');
check('C a wrong 4 digits -> refused, tries left', str_contains(txt($r), '❌') && !str_contains(txt($r), 'download-ticket'), txt($r));
$r = wa(STR, substr(C1, -4));
check('C the right last 4 digits -> the card (no ticket picture pushed to this number)', str_contains(txt($r), 'download-ticket.php') && $r['media'] === null, txt($r));
$p = $next();
wa($p, $B2['pnr']);
wa($p, '0001'); wa($p, '0002');
$r = wa($p, '0003');
check('C three wrong answers lock that PNR for this number', str_contains(txt($r), '🔒') && !WaLookup::challengeOpen(normalisePhone('+91' . $p)), txt($r));
$r = wa($p, $B2['pnr']);
check('C …asked again: status only, no new question', str_contains(txt($r), '🔒') && !WaLookup::challengeOpen(normalisePhone('+91' . $p)) && !str_contains(txt($r), 'Ramesh'), txt($r));
$p = $next();
$r = wa($p, $B3['pnr']);
check('C a walk-in placeholder booking: status only, never a digits question', str_contains(txt($r), '🔒') && !WaLookup::challengeOpen(normalisePhone('+91' . $p)), txt($r));
$r = wa(AG1, 'PNR ' . substr($B1['pnr'], -5));
check('C the selling agent (desk) reads their own sale in full, phone masked to the last 3', str_contains(txt($r), 'Ramesh Bahadur Thapa') && str_contains(txt($r), substr(C1, -3)) && !str_contains(txt($r), C1), txt($r));
$r = wa(AG2, 'PNR ' . substr($B1['pnr'], -5));
check('C another agent: status only ("not your sale")', !str_contains(txt($r), 'Ramesh') && (str_contains(txt($r), 'becheko hoina') || str_contains(txt($r), 'बेचेको होइन') || str_contains(txt($r), 'not one of your')), txt($r));
$r = WaLookup::pnrAnswer($B2['pnr'], AiTools::whoIs('+91' . OWN), OWN, 'rn');
check('C the office: any PNR in full', str_contains($r['text'], 'Ramesh Kumar Shah') && $r['access'] === 'full', $r['text']);
check('C every PNR lookup is audited (customer, stranger, office)', $audit(C1, 'pnr_lookup') !== [] && $audit(STR, 'pnr_lookup') !== [] && $audit(OWN, 'pnr_lookup') !== []);
$det = array_column($audit(STR, 'pnr_lookup'), 'detail');
check('C …the stranger\'s rows say challenge / wrong / matched with a result count', (bool) preg_grep('/challenge/', $det) && (bool) preg_grep('/wrong/', $det) && (bool) preg_grep('/matched/', $det) && (bool) preg_grep('/^results \d/', $det), json_encode($det));

/* ===================================================================== */
section('D name search: refused for a customer, the office everything, an agent own sales');
$r = wa($next(), 'Ramesh Bahadur Thapa ko ticket kun bus ma cha?');
check('D a customer asking by someone\'s name: polite refusal + how to find their own', WaBot::$lastRouter === 'lookup' && str_contains(txt($r), 'PNR') && !str_contains(txt($r), $B1['pnr']), txt($r));
$p = $CUST[$ci - 1];
$rows = $audit(normalisePhone('+91' . $p), 'name_search');
check('D …audited as refused', $rows !== [] && (int) $rows[0]['ok'] === 0 && str_contains((string) $rows[0]['detail'], 'refused'), json_encode($rows));
$r = wa(OWN, 'naam le khoja Ramesh');
check('D the office (superadmin, not OTP-linked): both agents\' Ramesh bookings, phones masked 2 + 3',
    str_contains(txt($r), $B1['pnr']) && str_contains(txt($r), $B2['pnr']) && !str_contains(txt($r), C1) && str_contains(txt($r), substr(C1, 0, 2) . '•'), txt($r));
$r = wa(AG1, 'Ramesh ko ticket kun bus ma cha?');
check('D agent 1: only their own sale, last 3 digits', str_contains(txt($r), $B1['pnr']) && !str_contains(txt($r), $B2['pnr']) && str_contains(txt($r), '•••' . substr(C1, -3)), txt($r));
$r = wa(AG2, 'name search Ramesh');
check('D agent 2: only their own sale', str_contains(txt($r), $B2['pnr']) && !str_contains(txt($r), $B1['pnr']), txt($r));
$r = wa(AG1, 'Ramesh ko ticket katdinus bholi Surat bata');
check('D "<name> ko ticket katdinus" is a SALE line, never a search', !str_contains(txt($r), '🔎'), txt($r));
check('D …name searches are audited with their result count', ($a = $audit(OWN, 'name_search')) !== [] && str_contains((string) end($a)['detail'], 'results 2'), json_encode($audit(OWN, 'name_search')));

/* ===================================================================== */
section('E the desk answers what was asked');
$r = wa(OWN, 'Chalni deu');
check('E office "Chalni deu" -> the passengers / chalani, never the menu', !$isMenu(txt($r)) && preg_match('/यात्रु|Passengers|yatri|🚌|यो मितिको बस छैन/iu', txt($r)) === 1, txt($r));
$r = wa(OWN, 'मलाई आजको चलानी पठाइदेउ त');
check('E office "मलाई आजको चलानी पठाइदेउ त" -> the chalani', !$isMenu(txt($r)) && preg_match('/यात्रु|🚌|बस छैन/u', txt($r)) === 1, txt($r));
$r = wa(OWN, 'K xa halkhbr');
check('E office "K xa halkhbr" -> today\'s report, not the menu', !$isMenu(txt($r)) && txt($r) !== '', txt($r));
$r = wa(OWN, 'Dalbir ka xan');
check('E office "Dalbir ka xan" -> that person in the team directory', str_contains(txt($r), 'MP3 Dalbir Agentone') && !$isMenu(txt($r)), txt($r));
$r = wa(OWN, 'Nepalgunj office ko number?');
check('E office "Nepalgunj office ko number?" -> the directory', str_contains(txt($r), '9866201375') || str_contains(txt($r), '986-6201375'), txt($r));
$r = wa(AG1, 'luggage kati kg samma lana milcha?');
check('E agent "luggage kati kg samma?" -> the luggage rule', str_contains(txt($r), '🧳'), txt($r));
$r = wa(AG1, 'aaj mausam kasto cha hola?');
check('E agent, a question nothing knows (AI off) -> the honest line + office, never the ticket menu',
    !$isMenu(txt($r)) && str_contains(txt($r), Settings::officePhone()) && str_contains(txt($r), 'menu'), txt($r));
$r = wa(AG1, 'Helo');
check('E "Helo" is still the menu (that is the answer to a hello)', $isMenu(txt($r)) || str_contains(txt($r), 'टिकट'), txt($r));
setOvr('ap_wa_honest_fallback_on', '0');
$r = wa(AG1, 'aaj mausam kasto cha hola?');
check('E ap_wa_honest_fallback_on = 0 -> the old menu', $isMenu(txt($r)) || str_contains(txt($r), 'टिकट'), txt($r));
setOvr('ap_wa_honest_fallback_on', '1');

/* ===================================================================== */
section('F a ticket-menu button from a seller goes to WaEdit');
if (WaEdit::ready()) {
    $r = wa(AG1, 'ed name ' . $B1['pnr']);            // the webhook strips the button id's "shg:"
    check('F agent taps "✏️ नाम · Name" -> which passenger / the new name (never "नयाँ मान बुझिएन")',
        !str_contains(txt($r), 'बुझिएन') && (str_contains(txt($r), '✏️') || str_contains(txt($r), '🔒')), txt($r));
    WaEdit::handle('+91' . AG1, 'stop', null);
} else {
    check('F ticket_edit_audit missing — WaEdit not ready (skipped)', true);
}

/* ===================================================================== */
section('G the ticket text prints each value under its own label (P6)');
$bRow = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $B1['id']]);
$v = Notify::ticketTextVars($bRow);
check('G {1} PNR, {2} route, {4} boarding, {6} total', $v['1'] === $B1['pnr'] && str_contains($v['2'], '→') && str_contains($v['4'], 'Surat') && str_contains($v['6'], '4,400'), json_encode($v, JSON_UNESCAPED_UNICODE));

/* ===================================================================== */
section('H the AI layer: the same tools, the same gates, the answer-what-was-asked rule');
$cust = AiTools::whoIs('+91' . STR) + ['channel' => 'whatsapp'];
$names = array_column(AiTools::catalogue($cust), 'name');
check('H a customer gets directory_lookup + pnr_lookup, never name_search', in_array('directory_lookup', $names, true) && in_array('pnr_lookup', $names, true) && !in_array('name_search', $names, true));
$staffCtx = AiTools::whoIs('+91' . AG1) + ['channel' => 'whatsapp'];
check('H staff get name_search', in_array('name_search', array_column(AiTools::catalogue($staffCtx), 'name'), true));
$ro = array_column(AiTools::catalogue($staffCtx + ['readOnly' => true]), 'name');
check('H the desk\'s read-only hand-off: no sale / change / money tool', !array_intersect($ro, ['staff_sell', 'plan_ticket', 'issue_ticket', 'cancel_ticket', 'request_payout', 'fix_ticket', 'rename_passenger'])
    && in_array('my_sales', $ro, true) && in_array('directory_lookup', $ro, true), json_encode($ro));
$t = AiTools::run('name_search', ['name' => 'Ramesh'], $cust);
check('H name_search from a customer is refused by the gate', !$t['ok']);
$t = AiTools::run('directory_lookup', ['query' => 'Nepalgunj office'], $cust);
check('H directory_lookup returns the live number', $t['ok'] && (str_contains((string) $t['data']['text'], '9866201375') || str_contains((string) $t['data']['text'], '986-6201375')), json_encode($t['data'] ?? [], JSON_UNESCAPED_UNICODE));
$t = AiTools::run('pnr_lookup', ['pnr' => $B1['pnr']], $cust);
check('H pnr_lookup for a stranger: status + the digits question, no passenger', $t['ok'] && $t['data']['access'] === 'challenge' && !str_contains((string) $t['data']['text'], 'Ramesh'));
WaLookup::clearChallenge(STR);
$t = AiTools::run('name_search', ['name' => 'Ramesh'], $staffCtx);
check('H name_search for agent 1: own sale only', $t['ok'] && str_contains((string) $t['data']['text'], $B1['pnr']) && !str_contains((string) $t['data']['text'], $B2['pnr']));
$sp = new ReflectionMethod('AiAgent', 'systemPrompt');
$prompt = (string) $sp->invoke(null, $staffCtx + ['readOnly' => true]);
check('H the chat briefing opens with "answer what was asked" and the read-only line', str_contains($prompt, 'ANSWER WHAT WAS ASKED') && str_contains($prompt, 'A5.') && str_contains($prompt, 'ANSWER EXACTLY WHAT WAS ASKED') && !str_contains($prompt, 'ANSWER ONLY about this bus service: booking'));
check('H the website briefing (ai_system_prompt()) is unchanged', str_contains(ai_system_prompt(), 'ANSWER ONLY about this bus') && str_contains(ai_system_prompt(), '👉'));
check('H a chat briefing has no "👉 Book" closing rule', !str_contains(ai_system_prompt(true), 'quick-action line starting with 👉'));

/* ===================================================================== */
section('I switches off = the old paths');
setOvr('wa_answer_first_on', '0');
$r = wa($next(), 'Nepalgunj office ko number?');
check('I wa_answer_first_on = 0: the directory hook does not answer', WaBot::$lastRouter !== 'directory', WaBot::$lastRouter);
setOvr('wa_answer_first_on', '1');
setOvr('wa_auto_sender_guard_on', '0');
$r = wa($next(), 'We are Always For Your support,');
check('I wa_auto_sender_guard_on = 0: an auto-reply is answered as before', !WaBot::$silent && txt($r) !== '', txt($r));
setOvr('wa_auto_sender_guard_on', '1');
setOvr('wa_lookup_on', '0');
check('I wa_lookup_on = 0: no name search on the desk path either', WaAnswer::forCustomer('+91' . STR, STR, 'Ramesh Bahadur Thapa ko ticket kun bus ma cha?', $cust) === null
    || WaBot::$lastRouter !== 'lookup');
setOvr('wa_lookup_on', '1');

echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
