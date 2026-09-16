<?php
/**
 * =====================================================================
 *  ticket-bot-test.php — 🤖 AI Ticket Bot (6 Sep 2026): learns from
 *  VERIFIED sales, suggests, never decides.
 *
 *    • parse(): one typed line → name / mobile (+977 aware) / seats /
 *      date words (kal, bholi, friday, 12/9, 5 sep) / boarding town /
 *      gender words / payment / direction — "jane" is never January,
 *      "Devi" stays in the name, "12/9" is a date not 12 seats
 *    • profile(): passenger memory from confirmed tickets only — a
 *      cancelled ticket drops out, a number with only cancellations is
 *      unknown; usual pickup + share, direction, gender as recorded
 *    • suggest(): explicit input > typed line > history > desk pattern >
 *      auto; the date is NEVER taken from history; gender is NEVER
 *      guessed; the customer seat cap holds; the fare is the engine's
 *      (Fare::quote), untouched; an unsellable suggestion falls back
 *    • patterns(): aggregated from the window, cached in kv_store
 *    • feedback()/accuracy(): hits and misses per field; a field whose
 *      suggestions keep being changed stops being auto-applied
 *    • static mirrors: API actions, desk panel, app card, i18n, run-all
 *
 *  Creates real rows on throwaway dates (today + 43/44) and cleans up.
 *      php -c .claude/php-dev.ini tests/ticket-bot-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticketbot.php';   // pulls quickticket + everything under it

const TB_PHONE = '9100005';   // + 3 digits = a 10-digit test mobile (prefix unique to this suite)

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(string $l, callable $fn, string $needle = ''): void {
    try { $fn(); check($l . ' (expected a refusal)', false, 'no exception'); }
    catch (Throwable $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        check($l, $ok, '"' . mb_substr($e->getMessage(), 0, 90) . '"');
    }
}

echo "\n=== AI Ticket Bot — learns from verified sales, suggests, never decides ===\n\n";

/* ---- pin the switches this suite depends on; restore exactly as found -- */
$PINNED = ['daily_service_on', 'quick_ticket_default_boarding', 'ai_bot_on', 'ai_bot_window_days', 'ai_bot_cache_min', 'whatsapp_notify_customer'];
$prior  = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$KV = ['ticketbot.patterns.v1', 'ticketbot.feedback.v1'];
$priorKv = [];
foreach ($KV as $k) {
    $priorKv[$k] = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => $k]);
}
$restore = static function () use ($PINNED, $prior, $KV, $priorKv): void {
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
    foreach ($KV as $k) {
        try {
            if ($priorKv[$k] === null) {
                Database::delete('kv_store', "kscope = 'global' AND kkey = :k", ['k' => $k]);
            } else {
                Database::update('kv_store', ['kvalue' => (string) $priorKv[$k]], "kscope = 'global' AND kkey = :k", ['k' => $k]);
            }
        } catch (Throwable $e) {}
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};
Settings::set('daily_service_on', true, 'bool', 'booking', true);
Settings::set('quick_ticket_default_boarding', '', 'string', 'booking', false);
Settings::set('ai_bot_on', true, 'bool', 'booking', false);
Settings::set('ai_bot_window_days', 90, 'int', 'booking', false);
Settings::set('ai_bot_cache_min', 30, 'int', 'booking', false);
Settings::set('whatsapp_notify_customer', true, 'bool', 'notify', false);
Settings::flush();
$dropKv = static function () use ($KV): void {
    foreach ($KV as $k) {
        try { Database::delete('kv_store', "kscope = 'global' AND kkey = :k", ['k' => $k]); } catch (Throwable $e) {}
    }
};
$dropKv();

/* ---- the route under test ------------------------------------------- */
$routes = QuickTicket::routes();
$out = null;
foreach ($routes as $r) {
    if ($r['direction'] === 'toNepal' && (string) $r['coach_type'] === 'sleeper') { $out = $r; break; }
}
if ($out === null) { echo "  SKIP  no active sleeper route towards Nepal\n"; exit(0); }
$rid   = (int) $out['id'];
$stops = Boarding::stopsFor($rid);
if (count($stops) < 2) { echo "  SKIP  route has fewer than two pickups\n"; exit(0); }
$first = $stops[0];
$last  = $stops[count($stops) - 1];
$firstTown = (string) Boarding::stopDisplay((string) $first['name'])['name'];
$lastTown  = (string) Boarding::stopDisplay((string) $last['name'])['name'];
$lastCode  = (string) Boarding::stopDisplay((string) $last['name'])['code'];
$dir   = Fare::dirFares();
$today = todayISO();
$D  = addDaysISO($today, 43);
$D2 = addDaysISO($today, 44);
$dates = [$D, $D2];

/* ---- throwaway counter agent ----------------------------------------- */
$agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'tb-agent'], 0);
if ($agentId === 0) {
    $agentId = (int) Database::insert('admins', [
        'username' => 'tb-agent', 'password_hash' => password_hash('Tb@12345', PASSWORD_BCRYPT),
        'full_name' => 'Ticket Bot Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
    ]);
} else {
    Database::update('admins', ['role' => 'agent', 'is_active' => 1], 'id = :i', ['i' => $agentId]);
}
$staffRow = ['id' => $agentId, 'username' => 'tb-agent', 'role' => 'agent', 'full_name' => 'Ticket Bot Agent'];
$_SESSION[ADMIN_SESSION_KEY] = ['id' => $agentId, 'username' => 'tb-agent', 'role' => 'agent', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];

$cleanup = static function () use ($dates): void {
    foreach (Database::fetchAll("SELECT id, pnr FROM bookings WHERE contact_phone LIKE '" . TB_PHONE . "%'") as $r) {
        $bid = (int) $r['id'];
        foreach (['agent_ledger', 'tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $t) {
            try { Database::delete($t, 'booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => $bid]);
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.png');
        @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . '.pdf');
        @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
    }
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

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);
$sell = static fn(array $in): array => QuickTicket::sell($in + ['direction' => 'toNepal', 'pay' => 'cash'], $staffRow);

try {
    /* ================================================================
     *  1. parse() — one typed line
     * ================================================================ */
    echo "== parse(): one line → fields ==\n";
    $p = TicketBot::parse('Ram Bahadur 9876543210 2 seats ' . $lastTown . ' kal female');
    check('name · mobile · seats · town · tomorrow · gender from one line',
        $p['name'] === 'Ram Bahadur' && $p['phone'] === '9876543210' && $p['seats'] === 2 && $p['date'] === addDaysISO($today, 1) && $p['gender'] === 'Female',
        json_encode(array_intersect_key($p, array_flip(['name', 'phone', 'seats', 'date', 'gender'])), JSON_UNESCAPED_UNICODE));
    check('  the town resolves to the route\'s own pickup', Boarding::stopDisplay((string) $p['boarding'])['code'] === $lastCode, (string) $p['boarding']);
    $p = TicketBot::parse('+977 9812345678 sita devi aaj');
    check('a +977 number is Nepali; "Devi" stays in the name; aaj = today',
        $p['phone'] === '9812345678' && $p['country'] === 'NP' && $p['name'] === 'Sita Devi' && $p['date'] === $today && $p['gender'] === '',
        json_encode(array_intersect_key($p, array_flip(['name', 'phone', 'country', 'date', 'gender'])), JSON_UNESCAPED_UNICODE));
    $p = TicketBot::parse('Hari Prasad 98765 43210 x3 friday cash');
    check('a spaced mobile, x3, a weekday and a payment word',
        $p['phone'] === '9876543210' && $p['seats'] === 3 && $p['pay'] === 'cash' && $p['date'] >= $today && date('w', strtotime($p['date'])) === '5',
        json_encode(array_intersect_key($p, array_flip(['phone', 'seats', 'date', 'pay']))));
    $p = TicketBot::parse('return 9100004001 tomorrow male');
    check('"return" = towards Gujarat; "male" = Male', $p['direction'] === 'toIndia' && $p['gender'] === 'Male' && $p['name'] === '', json_encode(array_intersect_key($p, array_flip(['direction', 'gender', 'name']))));
    $p = TicketBot::parse('9100004002 nepal jane 12/9');
    check('"nepal jane" = towards Nepal; "12/9" is a date (12 Sep), never 12 seats and never "jan"',
        $p['direction'] === 'toNepal' && $p['seats'] === 0 && str_ends_with((string) $p['date'], '-09-12'),
        json_encode(array_intersect_key($p, array_flip(['direction', 'seats', 'date']))));
    $p = TicketBot::parse('dui jana 9000000001');
    check('"dui jana" = a party of two', $p['seats'] === 2 && $p['name'] === '', json_encode(array_intersect_key($p, array_flip(['seats', 'name']))));
    $p = TicketBot::parse('5 sep 9000000003 upi');
    check('"5 sep" stays this year (a day just gone is a paper ticket, not next year)',
        str_ends_with((string) $p['date'], '-09-05') && (int) substr((string) $p['date'], 0, 4) <= (int) substr($today, 0, 4) + 1 && $p['pay'] === 'upi',
        (string) $p['date']);
    $p = TicketBot::parse('');
    check('an empty line yields nothing (no crash)', $p['name'] === '' && $p['phone'] === '' && $p['found'] === []);

    /* ================================================================
     *  2. profile() — passenger memory from VERIFIED tickets only
     * ================================================================ */
    echo "\n== profile(): verified tickets only ==\n";
    check('an unknown number has no profile', TicketBot::profile(TB_PHONE . '000') === null);
    $a1 = $sell(['name' => 'Bot Test Ram', 'phone' => TB_PHONE . '001', 'date' => $D,  'boarding' => $lastTown, 'gender' => 'Male']);
    $a2 = $sell(['name' => 'Bot Test Ram', 'phone' => TB_PHONE . '001', 'date' => $D2, 'boarding' => $lastTown, 'gender' => 'Male']);
    $a3 = $sell(['name' => 'Bot Test Ram', 'phone' => TB_PHONE . '001', 'date' => $D,  'boarding' => $firstTown, 'gender' => 'Male']);
    $pr = TicketBot::profile(TB_PHONE . '001');
    check('three verified tickets → a profile with 3 trips', $pr !== null && $pr['trips'] === 3, json_encode($pr['trips'] ?? null));
    check('  name = the name on the tickets', ($pr['name'] ?? '') === 'Bot Test Ram', (string) ($pr['name'] ?? ''));
    check('  usual pickup = the one used most, with its share', ($pr['boarding']['code'] ?? '') === $lastCode && abs((float) ($pr['boarding']['share'] ?? 0) - 0.67) < 0.02, json_encode($pr['boarding'] ?? null));
    check('  direction learned (3 of 3 towards Nepal)', ($pr['direction']['value'] ?? '') === 'toNepal' && (float) ($pr['direction']['share'] ?? 0) === 1.0);
    check('  gender only as RECORDED on the tickets', ($pr['gender'] ?? '') === 'Male' && (int) ($pr['genderN'] ?? 0) === 3);
    check('  party size 1, lower deck', ($pr['party']['size'] ?? 0) === 1 && ($pr['deck']['value'] ?? '') === 'lower', json_encode([$pr['party'] ?? null, $pr['deck'] ?? null]));
    Database::update('bookings', ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], 'pnr = :p', ['p' => $a3['pnr']]);
    $pr = TicketBot::profile(TB_PHONE . '001');
    check('a cancelled ticket drops out of the memory', $pr !== null && $pr['trips'] === 2 && (float) ($pr['boarding']['share'] ?? 0) === 1.0, json_encode([$pr['trips'] ?? null, $pr['boarding']['share'] ?? null]));
    $c1 = $sell(['name' => 'Bot Test Gone', 'phone' => TB_PHONE . '002', 'date' => $D]);
    Database::update('bookings', ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s')], 'pnr = :p', ['p' => $c1['pnr']]);
    check('a number with only cancellations is unknown', TicketBot::profile(TB_PHONE . '002') === null);

    /* ================================================================
     *  3. suggest() — the merge order, and what is never touched
     * ================================================================ */
    echo "\n== suggest(): explicit > typed > history > desk > auto ==\n";
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001'], $staffRow);
    check('a known number alone → name from history', ($s['prefill']['name'] ?? '') === 'Bot Test Ram' && ($s['fields']['name']['source'] ?? '') === 'history', json_encode($s['fields']['name'] ?? null, JSON_UNESCAPED_UNICODE));
    check('  pickup from history, with a reason', Boarding::stopDisplay((string) ($s['prefill']['boarding'] ?? ''))['code'] === $lastCode && ($s['fields']['boarding']['source'] ?? '') === 'history' && str_contains((string) ($s['fields']['boarding']['reason'] ?? ''), 'Boarded at'), (string) ($s['fields']['boarding']['reason'] ?? ''));
    check('  direction from history', ($s['prefill']['direction'] ?? '') === 'toNepal' && ($s['fields']['direction']['source'] ?? '') === 'history');
    check('  gender from the recorded tickets', ($s['prefill']['gender'] ?? '') === 'Male' && ($s['fields']['gender']['source'] ?? '') === 'history');
    check('  the date is NEVER taken from history', ($s['prefill']['date'] ?? 'x') === '' && ($s['fields']['date']['source'] ?? '') === 'auto');
    check('  a live plan comes back, on the remembered pickup', is_array($s['plan']) && ($s['plan']['matchedDesk'] ?? false) === true && count($s['plan']['seats']) === 1, (string) ($s['plan']['boarding'] ?? $s['planError']));
    $q = Fare::quote((float) $dir['toNepal'], 1, 0, 0, 0, '', '', $rid);
    check('  the fare is the engine\'s own quote — untouched', abs((float) ($s['plan']['fare']['total'] ?? 0) - (float) $q['total']) < 0.01 && abs((float) ($s['plan']['fare']['perSeat'] ?? 0) - (float) $dir['toNepal']) < 0.01, (string) ($s['plan']['fare']['total'] ?? ''));
    check('  confidence + reasons are reported', (float) $s['confidence'] > 0.5 && count($s['reasons']) >= 1, (string) $s['confidence'] . ' · ' . implode(' | ', $s['reasons']));
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001', 'boarding' => $firstTown, 'name' => 'Typed Name', 'gender' => 'Female'], $staffRow);
    check('explicit input beats history (name, pickup, gender)',
        ($s['prefill']['name'] ?? '') === 'Typed Name' && ($s['fields']['boarding']['source'] ?? '') === 'input' && ($s['prefill']['gender'] ?? '') === 'Female' && ($s['fields']['name']['source'] ?? '') === 'input');
    $s = TicketBot::suggest(['text' => 'Kiran Thapa ' . TB_PHONE . '001 2 seats'], $staffRow);
    check('the typed line beats history (name, seats) but history still fills the pickup',
        ($s['prefill']['name'] ?? '') === 'Kiran Thapa' && (int) ($s['prefill']['seats'] ?? 0) === 2 && ($s['fields']['seats']['source'] ?? '') === 'text' && ($s['fields']['boarding']['source'] ?? '') === 'history',
        json_encode($s['prefill'], JSON_UNESCAPED_UNICODE));
    $s = TicketBot::suggest(['phone' => TB_PHONE . '003'], $staffRow);
    check('an unknown number: no name, gender NOT guessed', ($s['prefill']['name'] ?? 'x') === '' && array_key_exists('gender', $s['prefill']) && $s['prefill']['gender'] === null && $s['profile'] === null);
    $s = TicketBot::suggest(['text' => 'Sita Devi 9000000009'], $staffRow);
    check('a feminine-looking name never sets a gender', array_key_exists('gender', $s['prefill']) && $s['prefill']['gender'] === null && ($s['prefill']['name'] ?? '') === 'Sita Devi');
    $cap = max(1, Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING));
    $s = TicketBot::suggest(['text' => '9 seats 9000000010'], null);
    check('a customer suggestion keeps the public seat cap', (int) ($s['prefill']['seats'] ?? 0) === $cap, (string) ($s['prefill']['seats'] ?? ''));
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001', 'boarding' => 'Timbuktu'], $staffRow);
    check('an unknown pickup still yields a sellable plan (falls back, flagged)', is_array($s['plan']) && ($s['fields']['boarding']['check'] ?? false) === true, implode(' | ', $s['reasons']));
    check('no booking was written by any suggestion', (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '" . TB_PHONE . "%'") === 4);

    /* ================================================================
     *  4. patterns() — aggregated, cached
     * ================================================================ */
    echo "\n== patterns(): aggregated + cached ==\n";
    $pt = TicketBot::patterns(true);
    check('the window holds the sales just made', (int) $pt['sample'] >= 3 && (int) $pt['repeat'] >= 1, 'sample ' . $pt['sample'] . ' · repeat ' . $pt['repeat']);
    $codes = array_map(static fn(array $x): string => (string) $x['code'], $pt['stops']);
    check('  the learned pickups include the one sold', in_array($lastCode, $codes, true), implode(',', $codes));
    check('  a desk pattern exists for the selling agent', isset($pt['stopBySeller'][(string) $agentId]), json_encode(array_keys($pt['stopBySeller'])));
    $pt2 = TicketBot::patterns();
    check('  the second read is served from the kv_store cache', $pt2['computedAt'] === $pt['computedAt'] && Database::exists("SELECT 1 FROM kv_store WHERE kscope = 'global' AND kkey = 'ticketbot.patterns.v1'"));
    $sum = TicketBot::summary();
    check('  summary() for the desk header', (int) $sum['sample'] >= 3 && $sum['enabled'] === true && is_array($sum['accuracy']));

    /* ================================================================
     *  5. feedback() / accuracy() — the outcome loop
     * ================================================================ */
    echo "\n== feedback(): hits, misses, trust ==\n";
    $dropKv();
    $acc = TicketBot::accuracy();
    check('fresh: no outcomes, every field trusted', $acc['boarding']['rate'] === null && $acc['boarding']['trusted'] === true);
    TicketBot::feedback(['boarding' => $lastTown, 'seats' => 1, 'name' => 'Bot Test Ram'], ['boarding' => (string) $last['name'], 'seats' => 2, 'name' => 'bot test ram']);
    $acc = TicketBot::accuracy();
    check('a kept pickup is a hit (label vs town compare by town key)', $acc['boarding']['hit'] === 1 && $acc['boarding']['miss'] === 0);
    check('  a changed seat count is a miss', $acc['seats']['miss'] === 1);
    check('  the name compares case-insensitively', $acc['name']['hit'] === 1);
    for ($i = 0; $i < 10; $i++) {
        TicketBot::feedback(['boarding' => $lastTown], ['boarding' => (string) $first['name']]);
    }
    $acc = TicketBot::accuracy();
    check('after ten changed pickups the field is no longer trusted', $acc['boarding']['trusted'] === false && (float) $acc['boarding']['rate'] < 0.4, 'rate ' . $acc['boarding']['rate']);
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001'], $staffRow);
    check('  …so history no longer auto-fills the pickup, while the name still does',
        ($s['fields']['boarding']['source'] ?? '') !== 'history' && ($s['fields']['name']['source'] ?? '') === 'history',
        ($s['fields']['boarding']['source'] ?? '') . ' / ' . ($s['fields']['name']['source'] ?? ''));
    $dropKv();
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001'], $staffRow);
    check('  trust returns once the outcomes are cleared', ($s['fields']['boarding']['source'] ?? '') === 'history');
    Settings::set('ai_bot_on', false, 'bool', 'booking', false); Settings::flush();
    check('the bot can be switched off in Settings', TicketBot::enabled() === false);
    Settings::set('ai_bot_on', true, 'bool', 'booking', false); Settings::flush();

    /* ================================================================
     *  6. static mirrors — the surfaces around the bot
     * ================================================================ */
    echo "\n== static mirrors ==\n";
    $api = $src('api/quick-ticket.php');
    check('api/quick-ticket.php has the bot action (staff) and scores outcomes on sell', str_contains($api, "action === 'bot'") && str_contains($api, 'TicketBot::feedback') && str_contains($api, 'isSellingStaff'));
    check('  …and the customer door (plan + sell) with its own rate bucket', str_contains($api, "'customer_sell'") && str_contains($api, 'sellCustomer') && str_contains($api, 'quick_ticket_public'));
    $desk = $src('admin/quick-ticket.php');
    check('the desk is ONE QuickBot automation: the smart line drives the plan (no separate bot panel)', str_contains($desk, 'id="qtLine"') && str_contains($desk, "action: 'bot'") && str_contains($desk, 'TicketBot::summary') && !str_contains($desk, 'id="qtBot"') && !str_contains($desk, 'renderBot('));
    check('  …with PNG and PDF downloads on the result card', str_contains($desk, 'Download PNG') && str_contains($desk, 'Download PDF'));
    check('the app card has the same smart line + Confirm & Issue Ticket', str_contains($src('app.template.html'), 'id="qtLine"') && str_contains($src('assets/js/05-router.js'), "text: qt.text") && str_contains($src('assets/js/05-router.js'), 'agentCode: qt.agentCode'));

    /* ================================================================
     *  7. QuickBot merge (7 Sep 2026) — one engine, two doors, privacy
     * ================================================================ */
    echo "\n== QuickBot: agent code · customer privacy · learning switch ==\n";
    $p = TicketBot::parse('Sita Devi 9812345678 shg-27 female');
    check('an agent code in the line is read (SHG-0027) and kept out of the name', $p['agentCode'] === 'SHG-0027' && $p['name'] === 'Sita Devi' && $p['phone'] === '9812345678');
    $p = TicketBot::parse('agent 3 9000000001 dui jana');
    check('  "agent 3" reads as SHG-0003; the party size survives', $p['agentCode'] === 'SHG-0003' && $p['seats'] === 2 && $p['phone'] === '9000000001');
    $s = TicketBot::suggest(['text' => 'Ram 9876543210, 2 seats, ' . $lastTown . ' tomorrow shg-27'], null);
    check('a customer line → name, seats, pickup, date, agent code in the prefill', ($s['prefill']['name'] ?? '') === 'Ram' && (int) ($s['prefill']['seats'] ?? 0) === 2 && ($s['prefill']['date'] ?? '') === addDaysISO($today, 1) && ($s['prefill']['agentCode'] ?? '') === 'SHG-0027' && Boarding::stopDisplay((string) $s['prefill']['boarding'])['code'] === $lastCode, json_encode($s['prefill'], JSON_UNESCAPED_UNICODE));
    $s = TicketBot::suggest(['phone' => TB_PHONE . '001'], null);
    check('a customer typing ANOTHER number that has history gets no memory at all', $s['profile'] === null && ($s['fields']['boarding']['source'] ?? '') === 'auto' && ($s['fields']['name']['source'] ?? '') === 'none');
    $s = TicketBot::suggest(['sessionPhone' => TB_PHONE . '001', 'phone' => TB_PHONE . '999'], null);
    check('  the SESSION number brings its memory; the typed number is ignored for the sale', $s['profile'] !== null && ($s['prefill']['phone'] ?? '') === TB_PHONE . '001' && ($s['fields']['name']['source'] ?? '') === 'history');
    check('  …but never the gender (shared family phones)', ($s['fields']['gender']['source'] ?? '') === 'none' && $s['prefill']['gender'] === null);
    check('  a customer\'s blank pickup means the passengers\' yard, not an hour pattern', ($s['fields']['boarding']['source'] ?? '') === 'history' || ($s['fields']['boarding']['source'] ?? '') === 'auto');
    Settings::set('ai_bot_on', false, 'bool', 'booking', false); Settings::flush();
    $s = TicketBot::suggest(['sessionPhone' => TB_PHONE . '001', 'text' => '2 seats kal'], null);
    check('learning OFF: the line is still read and a plan still comes, with no memory', $s['enabled'] === false && $s['profile'] === null && (int) $s['prefill']['seats'] === 2 && is_array($s['plan']));
    Settings::set('ai_bot_on', true, 'bool', 'booking', false); Settings::flush();
    check('the app banner plans + confirms in place (customer_plan / customer_sell)', str_contains($src('app.template.html'), 'id="qtPlanCard"') && str_contains($src('assets/js/05-router.js'), "action: 'customer_sell'"));
    check('the agent dashboard links the desk', str_contains($src('admin/agent.php'), '/admin/quick-ticket.php'));
    $i18n = $src('assets/js/04-i18n.js');
    foreach (['qtChecking', 'qtIssuing', 'qtPlanT', 'qtDepLbl', 'qtBoardLbl', 'qtSeatLbl', 'qtFareLbl', 'qtSeatsLbl', 'qtPaxLbl', 'qtDirGo', 'qtDirBack', 'qtGAny', 'qtGF', 'qtGM', 'qtLeft', 'qtPayCod', 'qtPayOnline', 'qtConfirm', 'qtTnc', 'qtBack', 'qtDone'] as $k) {
        check("i18n key $k in en / hi / ne", substr_count($i18n, ' ' . $k . ': ') === 3, substr_count($i18n, ' ' . $k . ': ') . ' found');
    }
    check('the settings migration seeds the bot switches', str_contains($src('database/upgrade-2026-09-quick-ticket-bot.php'), 'ai_bot_on') && str_contains($src('database/upgrade-2026-09-quick-ticket-bot.php'), 'quick_ticket_customer_on'));
    check('run-all lists this suite', str_contains($src('tests/run-all.php'), 'ticket-bot-test.php'));
    check('role-gates walks the customer door + the bot over HTTP', str_contains($src('tests/role-gates-test.php'), "'customer_plan'") && str_contains($src('tests/role-gates-test.php'), "'action' => 'bot'"));
    check('no external AI call: the bot is rules + statistics', !str_contains($src('includes/ticketbot.php'), 'curl_') && !str_contains($src('includes/ticketbot.php'), 'anthropic'));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    $restore();
    unset($_SESSION[ADMIN_SESSION_KEY]);
}

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
