<?php
/**
 * =====================================================================
 *  wa-manager-tools-test.php — the assistant as the company's manager
 *  (24 Sep 2026).
 *
 *  What this suite pins down, all in includes/aitools.php:
 *
 *    • PINNED NAME + NUMBER  a name or mobile given with the quote is read
 *                back and the sale is refused if a different one arrives;
 *                a place, a yes-word or a number is never a name; +977
 *                survives to the booking; one quote sells once
 *    • THE SELLER'S OWN  my_sales / my_wallet see their own book only;
 *                request_payout is two steps and agent-only
 *    • THE OFFICE  office_agent / office_agents / office_customer /
 *                office_payout_requests read the register; the write
 *                buttons (settle cash, reject, activate) are two steps,
 *                behind wa_agent_admin_write, admin only
 *    • THE CATALOGUE  each role sees exactly its own tools
 *
 *  Creates real bookings on throwaway dates and cleans up after itself.
 *      php tests/wa-manager-tools-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/personname.php';

const WM_CUST   = '9100009001';   // a passenger, Indian number
const WM_NPCUST = '9100009002';   // a passenger writing from +977
const WM_PAX    = '9100009003';   // somebody the agent sells to
const WM_PAX2   = '9100009004';   // a Nepali passenger the agent sells to
const WM_AGENT  = '9100009005';   // the counter agent's own mobile
const WM_BOSS   = '9100009006';   // the office
const WM_COUNTER = '9100009007';  // a counter-role account
const WM_LIKE   = '910000900';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== The manager's tools — pinned names, the seller's own book, the office ===\n\n";

$PINNED = ['wa_agent_on', 'wa_agent_sell', 'wa_agent_oneshot', 'wa_agent_rewrite', 'wa_agent_admin_write', 'wa_agent_payout',
           'daily_service_on', 'allow_cod', 'quick_ticket_customer_on', 'quick_ticket_customer_boarding', 'quick_ticket_max_open',
           'whatsapp_driver', 'whatsapp_notify_customer', 'whatsapp_notify_admin', 'agent_daily_booking_limit', 'agent_payout_min'];
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
Settings::set('wa_agent_sell', true, 'bool', 'ai', false);
Settings::set('wa_agent_oneshot', false, 'bool', 'ai', false);
Settings::set('wa_agent_rewrite', true, 'bool', 'ai', false);
Settings::set('wa_agent_admin_write', true, 'bool', 'ai', false);
Settings::set('wa_agent_payout', true, 'bool', 'ai', false);
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('allow_cod', true, 'bool', 'payment', true);
Settings::set('quick_ticket_customer_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_customer_boarding', '', 'string', 'booking', false);
Settings::set('quick_ticket_max_open', 6, 'int', 'booking', false);
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);   // journal only, no network
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::set('whatsapp_notify_admin', false, 'bool', 'notify', false);
Settings::set('agent_daily_booking_limit', 0, 'int', 'agent', false);
Settings::set('agent_payout_min', 0, 'float', 'agent', false);
Settings::flush();

$route = null;
foreach (QuickTicket::routes() as $r) {
    if ($r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $route = $r; break; }
}
if ($route === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; $restoreSettings(); exit(0); }

$today  = todayISO();
$window = bookingWindow();
$D1 = addDaysISO($today, 21);
$D2 = addDaysISO($today, 22);
$D3 = addDaysISO($today, 23);
if ($D3 > (string) $window['to']) {
    echo "  SKIP  the public booking window is shorter than 23 days (" . $window['to'] . ")\n";
    $restoreSettings(); exit(0);
}

$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', [
            'username' => $username, 'password_hash' => password_hash('Wa@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name, 'must_change_pw' => 0], 'id = :i', ['i' => $id]);
    return $id;
};
$agentId   = $mkStaff('wa-mgr-agent',   'WA Mgr Agent',   'agent',      WM_AGENT);
$bossId    = $mkStaff('wa-mgr-boss',    'WA Mgr Boss',    'superadmin', WM_BOSS);
$counterId = $mkStaff('wa-mgr-counter', 'WA Mgr Counter', 'counter',    WM_COUNTER);
$agentCode = AgentWallet::agentCodeFor($agentId);
if ($agentCode === null) {
    $agentCode = AgentWallet::nextFreeAgentCode('person');
    if ($agentCode !== null) { AgentWallet::setAgentCode($agentId, $agentCode, 0); }
}
$codeLabel = AgentWallet::agentCodeLabel($agentId);

$cleanup = static function () use ($D1, $D2, $D3, $agentId): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . WM_LIKE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        try { Database::delete('ai_agent_calls', 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
    try { Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    try { Database::delete('agent_payout_requests', 'agent_admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
    try { Database::delete('ai_agent_calls', "phone LIKE '" . WM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn','wa_bulk','wa_ticket_fix') AND kkey LIKE '" . WM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '" . WM_LIKE . "%'", []); } catch (Throwable $e) {}
    foreach ([$D1, $D2, $D3] as $d) {
        try { Database::delete('seat_locks', 'schedule_id IN (SELECT id FROM schedules WHERE travel_date = :d)', ['d' => $d]); } catch (Throwable $e) {}
    }
};
$cleanup();

$ctxFor = static function (string $phone, int $turn, string $said = ''): array {
    $c = AiTools::whoIs($phone);
    $c['turn'] = $turn;
    $c['channel'] = 'whatsapp';
    $c['messageText'] = $said;
    return $c;
};
$names = static fn(array $tools): array => array_column($tools, 'name');

try {
    /* ================================================================
     *  1. A name is a name
     * ================================================================ */
    echo "== a name is a name ==\n";
    check('"ram thapa" prints as Ram Thapa', PersonName::clean('ram thapa') === 'Ram Thapa');
    check('a Devanagari name is kept as written', PersonName::clean('राम बहादुर थापा') === 'राम बहादुर थापा');
    check('"naam: Sita Rai" drops the label', PersonName::clean('naam: Sita Rai') === 'Sita Rai');
    check('a bus stop is refused', PersonName::clean('Mehsana') === '' && str_contains(PersonName::why('Mehsana'), 'bus stop'));
    check('a yes-word is refused', PersonName::clean('ho') === '' && PersonName::clean('ठिक छ') === '');
    check('digits are refused', PersonName::clean('Ram 9876543210') === '' && str_contains(PersonName::why('Ram 9876543210'), 'digits'));
    check('"2 seat" is refused', PersonName::clean('2 seat') === '');
    check('a gender word alone is refused', PersonName::clean('Female') === '');
    check('same() ignores case, spacing and dots', PersonName::same('Ram  Thapa', 'ram thapa') && PersonName::same('R. Thapa', 'r thapa') && !PersonName::same('Ram Thapa', 'Sita Thapa'));
    check('a given name that is also a town is a person (Anand, Dang, Nadia, Gorakh)',
        PersonName::clean('Anand') === 'Anand' && PersonName::clean('Dang') === 'Dang' && PersonName::clean('Nadia') === 'Nadia' && PersonName::clean('Gorakh') === 'Gorakh');
    check('  but the town itself still is a place', PersonName::clean('Mehsana') === '' && PersonName::clean('Rupaidiha') === '' && PersonName::clean('Surat') === '');
    check('request words are never a name', PersonName::clean('ticket chahiyo') === '' && PersonName::clean('Chahiyo') === '' && PersonName::clean('bata') === ''
        && PersonName::clean('seat chahiyo') === '' && PersonName::clean('जाने') === '');

    /* ================================================================
     *  2. The catalogue per role
     * ================================================================ */
    echo "\n== each role sees its own tools ==\n";
    $cTools = $names(AiTools::catalogue($ctxFor(WM_CUST, 1)));
    $aTools = $names(AiTools::catalogue($ctxFor(WM_AGENT, 1)));
    $kTools = $names(AiTools::catalogue($ctxFor(WM_COUNTER, 1)));
    $bTools = $names(AiTools::catalogue($ctxFor(WM_BOSS, 1)));
    check('a seller has my_sales and my_wallet', in_array('my_sales', $aTools, true) && in_array('my_wallet', $aTools, true));
    check('  and request_payout (agent role, switch on)', in_array('request_payout', $aTools, true));
    check('  a counter account has no request_payout', in_array('my_wallet', $kTools, true) && !in_array('request_payout', $kTools, true));
    check('a passenger has none of them', !in_array('my_sales', $cTools, true) && !in_array('my_wallet', $cTools, true) && !in_array('request_payout', $cTools, true));
    check('the office has the people tools', in_array('office_agent', $bTools, true) && in_array('office_agents', $bTools, true)
        && in_array('office_customer', $bTools, true) && in_array('office_payout_requests', $bTools, true));
    check('  and the write buttons while wa_agent_admin_write is ON', in_array('office_settle_cod', $bTools, true)
        && in_array('office_reject', $bTools, true) && in_array('office_agent_status', $bTools, true));
    check('  a seller has no office tool', !in_array('office_agent', $aTools, true) && !in_array('office_agent_status', $aTools, true));
    Settings::set('wa_agent_admin_write', false, 'bool', 'ai', false); Settings::flush();
    $bOff = $names(AiTools::catalogue($ctxFor(WM_BOSS, 1)));
    check('  with the write switch OFF the buttons vanish, the readers stay',
        !in_array('office_settle_cod', $bOff, true) && !in_array('office_reject', $bOff, true) && in_array('office_agent', $bOff, true));
    check('  and cannot be reached by name', AiTools::run('office_reject', ['pnr' => 'SHG-X', 'reason' => 'x'], $ctxFor(WM_BOSS, 2))['ok'] === false);
    Settings::set('wa_agent_admin_write', true, 'bool', 'ai', false); Settings::flush();

    /* ================================================================
     *  3. Pinned name and number — the desk sale
     * ================================================================ */
    echo "\n== the name and the number are pinned to the quote ==\n";
    $q = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D1, 'direction' => 'toNepal', 'name' => 'Mehsana', 'phone' => WM_PAX], $ctxFor(WM_AGENT, 1));
    check('a quote with a place for a name still quotes', $q['ok'] === true, $q['say']);
    check('  but does not pin it and says why', ($q['data']['pinnedName'] ?? 'x') === '' && str_contains($q['say'], 'NOT accepted'));
    check('  the number IS pinned', ($q['data']['pinnedPhone'] ?? '') === WM_PAX);

    $q = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D1, 'direction' => 'toNepal', 'name' => 'bipin rai', 'phone' => WM_PAX], $ctxFor(WM_AGENT, 2));
    check('a real name is pinned, title-cased', ($q['data']['pinnedName'] ?? '') === 'Bipin Rai' && str_contains($q['say'], 'Bipin Rai'));

    $wrongName = AiTools::run('staff_sell', ['name' => 'Bimal Rai', 'phone' => WM_PAX, 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 3));
    check('selling under a DIFFERENT name is refused', $wrongName['ok'] === false && str_contains($wrongName['say'], 'changed since the quote'), $wrongName['say']);
    check('  and nothing was sold', !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WM_PAX]));
    $wrongPhone = AiTools::run('staff_sell', ['name' => 'Bipin Rai', 'phone' => WM_PAX2, 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 4));
    check('selling to a DIFFERENT number is refused', $wrongPhone['ok'] === false && str_contains($wrongPhone['say'], 'mobile changed'), $wrongPhone['say']);
    $badPhone = AiTools::run('staff_sell', ['name' => 'Bipin Rai', 'phone' => '98765', 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 5));
    check('a short number is refused, never repaired', $badPhone['ok'] === false && str_contains($badPhone['say'], '10-digit'));
    $sold = AiTools::run('staff_sell', ['name' => 'BIPIN RAI', 'phone' => WM_PAX, 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 6));
    check('the SAME name (any case) sells', $sold['ok'] === true, (string) ($sold['data']['pnr'] ?? $sold['say']));
    check('  the result echoes the name and number the ticket went to', ($sold['data']['name'] ?? '') === 'Bipin Rai' && ($sold['data']['phone'] ?? '') === WM_PAX);
    $soldPnr = (string) ($sold['data']['pnr'] ?? '');
    $paxName = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => (int) $sold['data']['bookingId']], '');
    check('  the register holds Bipin Rai, not BIPIN RAI', $paxName === 'Bipin Rai', $paxName);
    $twice = AiTools::run('staff_sell', ['name' => 'Bipin Rai', 'phone' => WM_PAX, 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 7));
    check('one quote sells ONCE — the next message finds no quote', $twice['ok'] === false && str_contains($twice['say'], 'No quote'), $twice['say']);
    check('  the passenger still has exactly one booking',
        (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => WM_PAX], 0) === 1);

    AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D2, 'direction' => 'toNepal', 'name' => 'Kamala Gurung', 'phone' => '+977 ' . WM_PAX2], $ctxFor(WM_AGENT, 8));
    $wrongCountry = AiTools::run('staff_sell', ['name' => 'Kamala Gurung', 'phone' => WM_PAX2, 'country' => 'IN', 'gender' => 'Female', 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 9));
    check('a +977 pinned at the quote cannot be turned into +91 at the sale', $wrongCountry['ok'] === false && str_contains($wrongCountry['say'], 'country changed')
        && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p', ['p' => WM_PAX2]), $wrongCountry['say']);
    $np = AiTools::run('staff_sell', ['name' => 'Kamala Gurung', 'phone' => WM_PAX2, 'gender' => 'Female', 'pay' => 'cash', 'confirm' => true], $ctxFor(WM_AGENT, 10));
    check('a +977 number sells', $np['ok'] === true, (string) ($np['data']['pnr'] ?? $np['say']));
    $npRow = Database::fetch('SELECT contact_phone, contact_country_code, id_type FROM bookings WHERE contact_phone = :p', ['p' => WM_PAX2]);
    check('  the ten digits are stored', $npRow !== null && (string) $npRow['contact_phone'] === WM_PAX2);
    check('  and the booking is stamped Nepal, so the ticket is addressed to +977',
        $npRow !== null && ((string) ($npRow['contact_country_code'] ?? '') === '977' || str_contains(strtolower((string) $npRow['id_type']), 'nepal')),
        (string) ($npRow['contact_country_code'] ?? '') . ' / ' . (string) ($npRow['id_type'] ?? ''));

    /* ---- the passenger's own sale, from a +977 number --------------- */
    echo "\n== a passenger writing from +977 ==\n";
    $npCtx = $ctxFor('+977' . WM_NPCUST, 1);
    check('whoIs reads the country off the webhook number', ($npCtx['country'] ?? '') === 'NP' && $npCtx['phone'] === WM_NPCUST);
    $cq = AiTools::run('plan_ticket', ['seats' => 1, 'date' => $D3, 'direction' => 'toNepal', 'name' => 'Dhan Bahadur'], $npCtx);
    check('their quote pins the name', $cq['ok'] === true && ($cq['data']['pinnedName'] ?? '') === 'Dhan Bahadur', $cq['say']);
    $cWrong = AiTools::run('issue_ticket', ['name' => 'Sher Bahadur', 'confirm' => true], $ctxFor('+977' . WM_NPCUST, 2));
    check('a different name at ho is refused', $cWrong['ok'] === false && str_contains($cWrong['say'], 'changed since the quote'));
    $cSold = AiTools::run('issue_ticket', ['name' => 'Dhan Bahadur', 'confirm' => true], $ctxFor('+977' . WM_NPCUST, 3));
    check('the pinned name sells', $cSold['ok'] === true, (string) ($cSold['data']['pnr'] ?? $cSold['say']));
    $cRow = Database::fetch('SELECT contact_country_code, id_type, is_cod, status FROM bookings WHERE contact_phone = :p', ['p' => WM_NPCUST]);
    check('  and the booking is stamped +977 from the sender\'s own number',
        $cRow !== null && ((string) ($cRow['contact_country_code'] ?? '') === '977' || str_contains(strtolower((string) $cRow['id_type']), 'nepal')),
        (string) ($cRow['contact_country_code'] ?? ''));
    $codPnr = (string) ($cSold['data']['pnr'] ?? '');

    /* ================================================================
     *  4. The seller's own book
     * ================================================================ */
    echo "\n== the seller's own book ==\n";
    $mine = AiTools::run('my_sales', [], $ctxFor(WM_AGENT, 10));
    $minePnrs = array_column($mine['data']['sales'] ?? [], 'pnr');
    check('my_sales lists the agent\'s two sales', $mine['ok'] === true && in_array($soldPnr, $minePnrs, true) && in_array((string) $np['data']['pnr'], $minePnrs, true), implode(',', $minePnrs));
    check('  with name, number, date, seats and status', isset($mine['data']['sales'][0]['name'], $mine['data']['sales'][0]['phone'], $mine['data']['sales'][0]['dateLabel'], $mine['data']['sales'][0]['status']));
    check('  and NOT the passenger\'s own booking', !in_array($codPnr, $minePnrs, true));
    $byDate = AiTools::run('my_sales', ['date' => $D2], $ctxFor(WM_AGENT, 11));
    check('  by travel date it narrows to that day', count($byDate['data']['sales'] ?? []) === 1 && $byDate['data']['sales'][0]['pnr'] === (string) $np['data']['pnr']);
    check('a passenger cannot call my_sales', AiTools::run('my_sales', [], $ctxFor(WM_CUST, 2))['ok'] === false);

    $wallet = AiTools::run('my_wallet', [], $ctxFor(WM_AGENT, 12));
    check('my_wallet answers with both balances apart', $wallet['ok'] === true && isset($wallet['data']['commissionDue'], $wallet['data']['cashDue']), $wallet['say']);
    check('  and the agent code, KYC and limits', ($wallet['data']['agentCode'] ?? '') === $codeLabel && isset($wallet['data']['kyc'], $wallet['data']['dailyLimit']));
    $due = (float) ($wallet['data']['commissionDue'] ?? 0);

    // Give the wallet something to pay out, whatever the commission rate is.
    if ($due <= 0) {
        AgentWallet::record($agentId, 'adjustment', 250.0, ['note' => 'test credit', 'created_by' => $bossId]);
        $due = (float) (AgentWallet::balances($agentId)['commission'] ?? 0);
    }
    $preview = AiTools::run('request_payout', ['amount' => 0], $ctxFor(WM_AGENT, 13));
    check('request_payout previews the amount without filing', $preview['ok'] === true && abs((float) $preview['data']['amount'] - $due) < 0.01
        && AgentWallet::openPayoutRequests($agentId) === [], $preview['say']);
    $sameTurn = AiTools::run('request_payout', ['amount' => 0, 'confirm' => true], $ctxFor(WM_AGENT, 13, 'ho'));
    check('  confirming in the SAME message is refused', $sameTurn['ok'] === false && AgentWallet::openPayoutRequests($agentId) === []);
    $flagOnly = AiTools::run('request_payout', ['amount' => 0, 'confirm' => true], $ctxFor(WM_AGENT, 14, 'kati din lagcha?'));
    check('  a confirm flag without the agent\'s own ho is refused', $flagOnly['ok'] === false && AgentWallet::openPayoutRequests($agentId) === [], $flagOnly['say']);
    AiTools::run('request_payout', ['amount' => 0], $ctxFor(WM_AGENT, 14));
    $filed = AiTools::run('request_payout', ['amount' => 0, 'confirm' => true], $ctxFor(WM_AGENT, 15, 'ho'));
    check('  the next message files it', $filed['ok'] === true && count(AgentWallet::openPayoutRequests($agentId)) === 1, $filed['say']);
    $second = AiTools::run('request_payout', ['amount' => 0], $ctxFor(WM_AGENT, 16));
    check('  a second request is refused while one is open', $second['ok'] === false && str_contains($second['say'], 'still with the office'));
    $tooMuch = AiTools::run('request_payout', ['amount' => $due + 1000], $ctxFor(WM_COUNTER, 3));
    check('a counter account cannot request a payout at all', $tooMuch['ok'] === false);

    /* ================================================================
     *  5. The office over people
     * ================================================================ */
    echo "\n== the office over people ==\n";
    $oa = AiTools::run('office_agent', ['q' => $codeLabel], $ctxFor(WM_BOSS, 3));
    check('office_agent finds the agent by code', $oa['ok'] === true && ($oa['data']['name'] ?? '') === 'WA Mgr Agent', $oa['say']);
    check('  with their day, wallet and last sales', isset($oa['data']['day']['tickets'], $oa['data']['wallet']['commissionDue']) && count($oa['data']['lastSales'] ?? []) === 2);
    check('  and the open payout request', ($oa['data']['wallet']['openPayoutRequest']['amount'] ?? 0) > 0);
    $oaName = AiTools::run('office_agent', ['q' => 'Mgr Agent'], $ctxFor(WM_BOSS, 4));
    check('  by name', $oaName['ok'] === true && ($oaName['data']['name'] ?? '') === 'WA Mgr Agent');
    $oaPhone = AiTools::run('office_agent', ['q' => WM_AGENT], $ctxFor(WM_BOSS, 5));
    check('  by mobile', $oaPhone['ok'] === true && ($oaPhone['data']['name'] ?? '') === 'WA Mgr Agent');
    $oaMany = AiTools::run('office_agent', ['q' => 'WA Mgr'], $ctxFor(WM_BOSS, 6));
    check('  several matches come back as a list to choose from', $oaMany['ok'] === true && count($oaMany['data']['matches'] ?? []) >= 2);
    check('  a seller cannot call it', AiTools::run('office_agent', ['q' => $codeLabel], $ctxFor(WM_AGENT, 17))['ok'] === false);

    $all = AiTools::run('office_agents', [], $ctxFor(WM_BOSS, 7));
    $names2 = array_column($all['data']['agents'] ?? [], 'name');
    check('office_agents lists every agent with today\'s tickets and balances', $all['ok'] === true && in_array('WA Mgr Agent', $names2, true)
        && isset($all['data']['agents'][0]['ticketsToday'], $all['data']['agents'][0]['cashDue']));

    $oc = AiTools::run('office_customer', ['phone' => WM_PAX], $ctxFor(WM_BOSS, 8));
    check('office_customer reads a passenger\'s history', $oc['ok'] === true && (int) ($oc['data']['trips'] ?? 0) === 1 && ($oc['data']['name'] ?? '') === 'Bipin Rai', $oc['say']);
    check('  names the seller and the upcoming trip', ($oc['data']['bookings'][0]['seller'] ?? '') === 'WA Mgr Agent' && count($oc['data']['upcoming'] ?? []) === 1);
    $ocNone = AiTools::run('office_customer', ['phone' => '9100009099'], $ctxFor(WM_BOSS, 9));
    check('  an unknown number is said plainly', $ocNone['ok'] === true && (int) ($ocNone['data']['trips'] ?? 1) === 0);

    $pr = AiTools::run('office_payout_requests', [], $ctxFor(WM_BOSS, 10));
    check('office_payout_requests shows the open request with the commission actually due',
        $pr['ok'] === true && count($pr['data']['requests'] ?? []) >= 1
        && count(array_filter($pr['data']['requests'], static fn($r) => $r['code'] === $codeLabel && isset($r['commissionDue']))) === 1);

    /* ---- the write buttons, two steps each ------------------------ */
    echo "\n== the office's buttons, two steps each ==\n";
    $codPrev = AiTools::run('office_settle_cod', ['pnr' => $codPnr], $ctxFor(WM_BOSS, 11));
    check('settle_cod previews the pay-at-boarding booking', $codPrev['ok'] === true && ($codPrev['data']['pnr'] ?? '') === $codPnr, $codPrev['say']);
    check('  nothing recorded yet', !Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $cSold['data']['bookingId']]));
    $codSame = AiTools::run('office_settle_cod', ['pnr' => $codPnr, 'confirm' => true], $ctxFor(WM_BOSS, 11, 'ho'));
    check('  confirming in the same message is refused', $codSame['ok'] === false);
    $codNo = AiTools::run('office_settle_cod', ['pnr' => $codPnr, 'confirm' => true], $ctxFor(WM_BOSS, 12, 'na, pahila UTR check gara'));
    check('  a confirm flag on a "no" message is refused', $codNo['ok'] === false
        && !Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $cSold['data']['bookingId']]), $codNo['say']);
    $codDone = AiTools::run('office_settle_cod', ['pnr' => $codPnr, 'confirm' => true], $ctxFor(WM_BOSS, 12, 'ho'));
    check('  the next message records the cash', $codDone['ok'] === true
        && Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $cSold['data']['bookingId']]), $codDone['say']);
    $codAgain = AiTools::run('office_settle_cod', ['pnr' => $codPnr], $ctxFor(WM_BOSS, 13));
    check('  and says so the second time', $codAgain['ok'] === false && str_contains($codAgain['say'], 'already'));
    $notCod = AiTools::run('office_settle_cod', ['pnr' => $soldPnr], $ctxFor(WM_BOSS, 14));
    check('  a counter cash sale is not a pay-at-boarding booking', $notCod['ok'] === false);

    // A PENDING booking to reject: the passenger's Quick Ticket with pay-at-boarding off.
    Settings::set('allow_cod', false, 'bool', 'payment', true); Settings::flush();
    $pending = QuickTicket::sellCustomer(['name' => 'Pending Passenger', 'phone' => WM_CUST, 'seats' => 1, 'date' => $D3, 'direction' => 'toNepal'], null);
    Settings::set('allow_cod', true, 'bool', 'payment', true); Settings::flush();
    $pendPnr = (string) ($pending['pnr'] ?? '');
    check('(fixture) a pending booking exists', $pendPnr !== '' && (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pendPnr], '') === 'pending');
    $rjNoReason = AiTools::run('office_reject', ['pnr' => $pendPnr, 'reason' => ''], $ctxFor(WM_BOSS, 15));
    check('reject needs a reason the passenger can read', $rjNoReason['ok'] === false);
    $rjPrev = AiTools::run('office_reject', ['pnr' => $pendPnr, 'reason' => 'Payment proof did not match'], $ctxFor(WM_BOSS, 16));
    check('  previews first', $rjPrev['ok'] === true && (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pendPnr], '') === 'pending');
    $rjDone = AiTools::run('office_reject', ['pnr' => $pendPnr, 'reason' => 'Payment proof did not match', 'confirm' => true], $ctxFor(WM_BOSS, 17, 'ho'));
    check('  then rejects in the next message', $rjDone['ok'] === true && (string) Database::scalar('SELECT status FROM bookings WHERE pnr = :p', ['p' => $pendPnr], '') === 'rejected', $rjDone['say']);
    $rjConfirmed = AiTools::run('office_reject', ['pnr' => $soldPnr, 'reason' => 'x'], $ctxFor(WM_BOSS, 18));
    check('  a confirmed booking cannot be rejected, only cancelled', $rjConfirmed['ok'] === false && str_contains($rjConfirmed['say'], 'cancel_ticket'));

    $selfOff = AiTools::run('office_agent_status', ['q' => WM_BOSS, 'active' => false], $ctxFor(WM_BOSS, 19));
    check('nobody switches off their own account', $selfOff['ok'] === false);
    $stPrev = AiTools::run('office_agent_status', ['q' => $codeLabel, 'active' => false, 'reason' => 'left the company'], $ctxFor(WM_BOSS, 20));
    check('deactivating an agent previews first', $stPrev['ok'] === true && ($stPrev['data']['willBe'] ?? true) === false
        && (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $agentId], 1) === 1, $stPrev['say']);
    $stDone = AiTools::run('office_agent_status', ['q' => $codeLabel, 'active' => false, 'confirm' => true], $ctxFor(WM_BOSS, 21, 'हो'));
    check('  the next message deactivates', $stDone['ok'] === true && (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $agentId], 1) === 0, $stDone['say']);
    check('  and that number is a customer to the assistant now', (string) AiTools::whoIs(WM_AGENT)['role'] === 'customer');
    check('  the audit trail has it', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'staff.toggle' AND entity_id = 'wa-mgr-agent' AND detail LIKE '%WhatsApp%'"));
    AiTools::run('office_agent_status', ['q' => $codeLabel, 'active' => true], $ctxFor(WM_BOSS, 22));
    $stBack = AiTools::run('office_agent_status', ['q' => $codeLabel, 'active' => true, 'confirm' => true], $ctxFor(WM_BOSS, 23, 'yes'));
    check('  and reactivates the same way', $stBack['ok'] === true && (string) AiTools::whoIs(WM_AGENT)['role'] === 'staff');

    check('the office may cancel ANY booking: refund_quote works on the agent\'s sale',
        AiTools::run('refund_quote', ['pnr' => $soldPnr], $ctxFor(WM_BOSS, 24))['ok'] === true);

    /* ================================================================
     *  6. The wiring
     * ================================================================ */
    echo "\n== the wiring ==\n";
    $root = dirname(__DIR__);
    $agentSrc = (string) file_get_contents($root . '/includes/aiagent.php');
    check('the staff briefing makes the assistant the manager\'s voice', str_contains($agentSrc, "YOU ARE THEIR MANAGER'S VOICE"));
    check('  and tells every role to pin the name and number', str_contains($agentSrc, 'NAMES AND NUMBERS'));
    check('  the office briefing names the people tools', str_contains($agentSrc, 'office_customer'));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'wa-manager-tools-test.php'));

} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    try { Database::delete('audit_logs', "entity_id = 'wa-mgr-agent' AND action = 'staff.toggle'", []); } catch (Throwable $e) {}
    $restoreSettings();
}

echo "\n----------------------------------------\n";
echo "  \033[32m$PASS passed\033[0m, " . ($FAIL > 0 ? "\033[31m$FAIL failed\033[0m" : "0 failed") . "\n\n";
exit($FAIL > 0 ? 1 : 0);
