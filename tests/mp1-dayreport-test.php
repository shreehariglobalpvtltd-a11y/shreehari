<?php
/**
 * =====================================================================
 *  mp1-dayreport-test.php — the smart WhatsApp day report (25 Nov 2026, release dy).
 *
 *   A a seeded day for an agent with no desk = CounterBook's 'AG:<id>' line
 *     (tickets, INR, cash + cod = नगद, online, ड्यू बाँकी, commission);
 *     a Nepal desk clerk = that desk's line, the drawer in NPR (cashLocal)
 *   B AiTools agent_day carries the same figures (cash counts cod)
 *   C the message: "नमस्ते <name> जी, आज <BS / AD>: टिकट N, नगद ₹X, अनलाइन Y,
 *     ड्यू बाँकी Z, तपाईंको कमिसन W"; रू at an NPR desk
 *   D the bot: "hisab", "हिसाब", "mero hisab", "aaja" answer the day;
 *     "hisab 7" stays the statement; an unknown number gets nothing new
 *   E the evening run: window open -> one free WhatsApp, closed + push ->
 *     a push, the second run sends 0 (claim), closed + no push -> no WA send
 *     and no claim, before the hour / switch off -> nothing; owner total once
 *
 *    php -c .claude/php-dev.ini tests/mp1-dayreport-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/sellerday.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 400) : '') . "\n"; }
}

const DY_PH  = '70000888';          // every test phone starts with this
const DY_A   = '7000088801';        // agent, no desk
const DY_C   = '7000088802';        // clerk at the NPR test desk
const DY_B   = '7000088803';        // agent with no sale today
const DY_U   = '7000088877';        // a stranger
const DY_ADM = '7000088809';        // the owner's WhatsApp for the run
const DY_DESK = 'ZDY1';
$TODAY = todayISO();
$NOW   = date('Y-m-d H:i:s');

$keep = [];
function setKeep(string $k, string $v, string $t = 'string'): void {
    global $keep;
    if (!array_key_exists($k, $keep)) { $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
    Settings::set($k, $v, $t, 'reports', false);
    Settings::flush();
}
function cleanup(): void {
    global $keep, $TODAY;
    $ids = array_map(static fn(array $r): int => (int) $r['id'], Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'mp1dy\\_%'"));
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . DY_PH . "%'") as $r) {
        foreach (['payments', 'agent_ledger', 'booking_passengers', 'notifications'] as $t) {
            try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    foreach ($ids as $id) {
        foreach (['agent_ledger' => 'agent_admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ap_wa_state' => 'admin_id'] as $t => $c) {
            try { Database::run("DELETE FROM $t WHERE $c = :a", ['a' => $id]); } catch (Throwable $e) {}
        }
        try { Database::run('DELETE FROM automation_log WHERE dedupe_key = :k', ['k' => SellerDay::claimKey((string) $id, $TODAY)]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM ai_tool_log WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    try { Database::run('DELETE FROM automation_log WHERE dedupe_key = :k', ['k' => SellerDay::claimKey('admin', $TODAY)]); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE :k", ['k' => '%' . DY_PH . '%']); } catch (Throwable $e) {}
    try { Database::run('DELETE FROM message_logs WHERE to_number LIKE :k', ['k' => '%' . DY_PH . '%']); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM ap_wa_state WHERE phone LIKE :k", ['k' => '%' . DY_PH . '%']); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM counter_locations WHERE code = :c", ['c' => DY_DESK]); } catch (Throwable $e) {}
    if (class_exists('CounterDesk')) { CounterDesk::flush(); }
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    $keep = [];
    Settings::flush();
    WaRoute::$transport = null;
    SellerDay::$pusher = null;
}
cleanup();
register_shutdown_function('cleanup');
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap\\_%' OR bucket LIKE 'wa_%'");

echo "\n=== mp1 dy: the smart WhatsApp day report ===\n\n";
foreach (['whatsapp_driver' => 'click_to_chat', 'wa_policy' => 'meta_first'] as $k => $v) { setKeep($k, $v); }
foreach (['ap_wa_on' => '1', 'ap_ai_on' => '0', 'ap_wa_sell' => '1', 'agent_kyc_required' => '0', 'whatsapp_notify_customer' => '0',
          'whatsapp_notify_admin' => '0', 'email_enabled' => '0', 'seller_day_numbers_on' => '1', 'seller_day_wa_on' => '1',
          'seller_day_report_on' => '1', 'seller_day_push_on' => '1', 'seller_day_admin_on' => '0'] as $k => $v) { setKeep($k, $v, 'bool'); }
setKeep('seller_day_report_hour', '0', 'int');
setKeep('wa_admin_number', '91' . DY_ADM);

/* ---- fixtures ---- */
Database::insert('counter_locations', ['code' => DY_DESK, 'name' => 'ZZ DY Test Desk', 'country' => 'NP', 'currency' => 'NPR',
    'fx_rate' => 1.6, 'entity' => 'NP', 'is_active' => 0]);
CounterDesk::flush();
$mkAdmin = static function (string $user, string $name, string $phone, string $role): int {
    return (int) Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
};
$A = $mkAdmin('mp1dy_agent', 'MP1 DY Agent', DY_A, 'agent');
$C = $mkAdmin('mp1dy_clerk', 'MP1 DY Clerk', DY_C, 'counter');
$B = $mkAdmin('mp1dy_idle', 'MP1 DY Idle', DY_B, 'agent');
Database::insert('admin_profiles', ['admin_id' => $C, 'counter_code' => DY_DESK]);
CounterDesk::flush();

$n = 0;
$book = static function (int $seller, ?string $desk, float $total, array $pays) use (&$n, $NOW): int {
    $n++;
    $id = (int) Database::insert('bookings', ['pnr' => 'ZZDY-' . date('His') . '-' . $n . random_int(100, 999), 'contact_phone' => DY_PH . sprintf('%02d', 40 + $n),
        'status' => 'confirmed', 'total_amount' => $total, 'sold_by_admin_id' => $seller, 'booked_by_admin_id' => $seller,
        'counter_code' => $desk, 'confirmed_at' => $NOW, 'created_at' => $NOW]);
    foreach ($pays as $i => $p) {
        Database::insert('payments', $p + ['booking_id' => $id, 'payment_ref' => 'ZZDY' . $id . 'P' . $i, 'amount' => $total, 'currency' => 'INR']);
    }
    return $id;
};
$ver = static fn(string $method, ?int $by): array => ['method' => $method, 'status' => 'verified', 'verified_by' => $by, 'verified_at' => $NOW];
// the agent: cash 1000 + cod collected 700 = नगद 1,700; UPI 1500 online; cod 900 still to collect
$a1 = $book($A, null, 1000, [$ver('cash', $A)]);
$a2 = $book($A, null, 1500, [$ver('upi', null)]);
$a3 = $book($A, null, 900, [['method' => 'cod', 'status' => 'cod_pending']]);
$a4 = $book($A, null, 700, [['method' => 'cod', 'status' => 'cod_pending', 'amount' => 700]]);
Database::insert('payments', ['booking_id' => $a4, 'payment_ref' => 'ZZDY' . $a4 . 'V', 'amount' => 700, 'currency' => 'INR'] + $ver('cod', $A));
foreach ([[$a1, 'commission', 100], [$a2, 'commission', 150], [$a3, 'commission', 90], [$a3, 'commission_void', -40]] as [$bid, $type, $amt]) {
    Database::insert('agent_ledger', ['agent_admin_id' => $A, 'booking_id' => $bid, 'account' => 'commission', 'entry_type' => $type, 'amount' => $amt, 'note' => 'mp1 dy test']);
}
Database::insert('agent_ledger', ['agent_admin_id' => $A, 'booking_id' => $a1, 'account' => 'cash', 'entry_type' => 'cash_due', 'amount' => 1000, 'note' => 'mp1 dy test']);
// the clerk at the NPR desk: NPR 3,200 stamped + 1,000 x 1.6 = रू 4,800 in the drawer; UPI 500 online
$book($C, DY_DESK, 2000, [$ver('cash', $C) + ['local_currency' => 'NPR', 'local_amount' => 3200]]);
$book($C, DY_DESK, 1000, [$ver('cash', $C) + ['fx_rate' => 1.6]]);
$book($C, DY_DESK, 500, [$ver('upi', $C)]);

/* =========================== A: CounterBook =========================== */
$fa = SellerDay::figures($A, $TODAY);
$la = CounterBook::line($TODAY, 'AG:' . $A);
foreach (['tickets', 'inr', 'cash', 'online', 'pending', 'pendingN', 'commission'] as $f) {
    check("A agent '$f' = CounterBook AG line (" . $fa[$f] . ')', abs((float) $fa[$f] - (float) $la[$f]) < 0.005, json_encode([$fa[$f], $la[$f]]));
}
check('A agent: 4 tickets, नगद 1700 (cash + cod), online 1500, due 900, commission 300',
    $fa['tickets'] === 4 && $fa['cash'] == 1700.0 && $fa['online'] == 1500.0 && $fa['pending'] == 900.0 && $fa['commission'] == 300.0, json_encode($fa));
$fc = SellerDay::figures($C, $TODAY);
$lc = CounterBook::line($TODAY, DY_DESK);
foreach (['tickets', 'inr', 'cash', 'cashLocal', 'online', 'pending', 'commission'] as $f) {
    check("A desk clerk '$f' = CounterBook desk line (" . $fc[$f] . ')', abs((float) $fc[$f] - (float) $lc[$f]) < 0.005, json_encode([$fc[$f], $lc[$f]]));
}
check('A desk clerk: NPR desk, drawer रू 4800', $fc['currency'] === 'NPR' && $fc['cashLocal'] == 4800.0, json_encode($fc));
check('A an idle agent reads zeros', SellerDay::figures($B, $TODAY)['tickets'] === 0);

/* =========================== B: agent_day =========================== */
$ctxA = ['role' => 'staff', 'adminId' => $A, 'scopeAdminId' => $A, 'name' => 'MP1 DY Agent', 'phone' => DY_A, 'channel' => 'whatsapp', 'admin' => ['id' => $A, 'role' => 'agent']];
$day = AiTools::run('agent_day', [], $ctxA);
$dd = $day['data'] ?? [];
check('B agent_day ok with the same figures (cash counts cod)', ($day['ok'] ?? false) === true && (float) ($dd['cashTakenToday'] ?? -1) == 1700.0
    && (float) ($dd['onlineToday'] ?? -1) == 1500.0 && (float) ($dd['dueToday'] ?? -1) == 900.0 && (float) ($dd['commissionToday'] ?? -1) == 300.0, json_encode($dd));
check('B commission owed and cash held stay two separate figures', isset($dd['commissionDue'], $dd['cashDue']));

/* =========================== C: the words =========================== */
$bs = SellerDay::dateLabel($TODAY);
$ta = SellerDay::text($fa, 'MP1 DY Agent');
check('C the line, exactly', $ta === 'नमस्ते MP1 DY Agent जी, आज ' . $bs . ': टिकट 4, नगद ₹1,700, अनलाइन ₹1,500, ड्यू बाँकी ₹900, तपाईंको कमिसन ₹300', $ta);
check('C the date is BS (Devanagari) and AD', preg_match('/^[०-९]{4} \S+ [०-९]{1,2} \/ \d{1,2} [A-Z][a-z]{2} \d{4}$/u', $bs) === 1, $bs);
$tc = SellerDay::text($fc, 'MP1 DY Clerk');
check('C an NPR desk speaks रू: drawer रू 4,800, online रू 800', str_contains($tc, 'नगद रू 4,800') && str_contains($tc, 'अनलाइन रू 800'), $tc);
check('C the owner total names no passenger', !str_contains(SellerDay::adminText($TODAY), 'ZZDY') && str_contains(SellerDay::adminText($TODAY), 'सबै काउन्टर'));

/* =========================== D: the bot =========================== */
$wa = static fn(string $from, string $text): string => (string) (ApWa::reply('+91' . $from, $text, 'text')['text'] ?? '');
if (!AgentPro::available()) {
    check('D AgentPro tables present for the bot checks', false);
} else {
    foreach (['hisab', 'हिसाब', 'mero hisab', 'aaja', 'aajako hisab dekhau'] as $q) {
        $r = $wa(DY_A, $q);
        check('D "' . $q . '" -> the day in Nepali', str_contains($r, 'नमस्ते MP1 DY Agent जी, आज') && str_contains($r, 'टिकट 4') && str_contains($r, 'नगद ₹1,700')
            && str_contains($r, 'ड्यू बाँकी ₹900') && str_contains($r, 'तपाईंको कमिसन ₹300'), $r);
    }
    $r = $wa(DY_C, 'hisab');
    check('D the NPR clerk is answered in रू', str_contains($r, 'नमस्ते MP1 DY Clerk जी') && str_contains($r, 'नगद रू 4,800'), $r);
    $r = $wa(DY_A, 'hisab 7');
    check('D "hisab 7" stays the ledger statement', !str_contains($r, 'ड्यू बाँकी') && $r !== '', $r);
    $r = $wa(DY_U, 'hisab');
    check('D an unknown number gets nothing new', !str_contains($r, 'ड्यू बाँकी') && !str_contains($r, 'तपाईंको कमिसन'), $r);
    setKeep('seller_day_wa_on', '0', 'bool');
    $r = $wa(DY_A, 'aaja');
    check('D seller_day_wa_on = 0 -> the old card', str_contains($r, '📊') && !str_contains($r, 'नमस्ते MP1 DY Agent जी, आज'), $r);
    setKeep('seller_day_wa_on', '1', 'bool');
}

/* =========================== E: the evening =========================== */
$sent = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$sent): array {
    $sent[] = ['to' => $to, 'text' => $text];
    return ['success' => true, 'message_id' => 'wamid.mp1dy' . count($sent), 'error' => ''];
};
$pushed = [];
SellerDay::$pusher = static function (int $id, array $payload) use (&$pushed): int { $pushed[] = ['id' => $id, 'p' => $payload]; return 1; };
$open = static function (string $intl): void {
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => $intl]);
    Database::insert('kv_store', ['kscope' => 'wa_inbound', 'kkey' => $intl, 'kvalue' => (string) time()]);
};
$open('91' . DY_A);
$only = [$A, $C, $B];

$r1 = SellerDay::evening(time(), $only);
check('E run 1: the agent (window open) gets one free WhatsApp, the clerk (closed) a push, the idle agent nothing',
    $r1['sent'] === 1 && $r1['pushed'] === 1 && $r1['sellers'] === 2, json_encode($r1));
check('E run 1: the WhatsApp went to the agent\'s number with the day line', count($sent) === 1 && $sent[0]['to'] === '91' . DY_A
    && str_contains($sent[0]['text'], 'नमस्ते MP1 DY Agent जी, आज'), json_encode($sent, JSON_UNESCAPED_UNICODE));
check('E run 1: the push is the clerk\'s, in रू', count($pushed) === 1 && $pushed[0]['id'] === $C && str_contains((string) $pushed[0]['p']['body'], 'रू 4,800'));
check('E one claim per person per day', Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => SellerDay::claimKey((string) $A, $TODAY)]));
$r2 = SellerDay::evening(time(), $only);
check('E run 2 sends 0 (idempotent claim)', $r2['sent'] === 0 && $r2['pushed'] === 0 && $r2['already'] === 2 && count($sent) === 1 && count($pushed) === 1, json_encode($r2));

// outside the window, no push handset: no WhatsApp, no claim (a later run may still reach them)
Database::run('DELETE FROM automation_log WHERE dedupe_key IN (:a, :c)', ['a' => SellerDay::claimKey((string) $A, $TODAY), 'c' => SellerDay::claimKey((string) $C, $TODAY)]);
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => '91' . DY_A]);
setKeep('seller_day_push_on', '0', 'bool');
$sent = []; $pushed = [];
$r3 = SellerDay::evening(time(), $only);
check('E window closed -> no WhatsApp send at all (never a paid template)', $sent === [] && $pushed === [] && $r3['sent'] === 0 && $r3['skipped'] === 2, json_encode($r3));
check('E   and no claim was spent', !Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => SellerDay::claimKey((string) $A, $TODAY)]));
setKeep('seller_day_push_on', '1', 'bool');

// before the hour, and switched off
$open('91' . DY_A);
setKeep('seller_day_report_hour', '20', 'int');
$r4 = SellerDay::evening((int) strtotime($TODAY . ' 10:00:00'), $only);
check('E before seller_day_report_hour -> nothing', $r4['state'] === 'early' && $sent === [], json_encode($r4));
setKeep('seller_day_report_hour', '0', 'int');
setKeep('seller_day_report_on', '0', 'bool');
$r5 = SellerDay::evening(time(), $only);
check('E seller_day_report_on = 0 -> nothing', $r5['state'] === 'off' && $sent === [], json_encode($r5));
setKeep('seller_day_report_on', '1', 'bool');

// the owner: one total of all desks, once
setKeep('seller_day_admin_on', '1', 'bool');
$open('91' . DY_ADM);
Database::run('DELETE FROM automation_log WHERE dedupe_key = :k', ['k' => SellerDay::claimKey('admin', $TODAY)]);
$r6 = SellerDay::evening(time(), []);
$adm = array_values(array_filter($sent, static fn(array $s): bool => $s['to'] === '91' . DY_ADM));
check('E the owner gets one free all-desk total', $r6['admin'] === 'sent' && count($adm) === 1 && str_contains($adm[0]['text'], 'सबै काउन्टर'), json_encode($r6));
$r7 = SellerDay::evening(time(), []);
check('E   and only once a day', $r7['admin'] === 'already' && count(array_filter($sent, static fn(array $s): bool => $s['to'] === '91' . DY_ADM)) === 1, json_encode($r7));

/* ---- the cron file follows the pattern ---- */
$cron = (string) file_get_contents(dirname(__DIR__) . '/cron/seller-day.php');
check('E cron/seller-day.php uses cron/_cron.php and cron_done', str_contains($cron, "require __DIR__ . '/_cron.php'") && str_contains($cron, 'cron_done('));
check('E the crontab line is in deploy/VPS-SETUP.md', str_contains((string) file_get_contents(dirname(__DIR__) . '/deploy/VPS-SETUP.md'), 'cron/seller-day.php'));

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
