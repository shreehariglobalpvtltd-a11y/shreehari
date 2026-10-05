<?php
/**
 * tests/agent-finance-test.php — 5 Oct 2026 agent & financial add-on:
 * booking channel tags, Nepal office contacts, the settlement record
 * (draft -> approved -> paid -> confirmed / disputed), the commission grid,
 * the admin alerts and the two new roles.
 *
 *   php -c .claude/php-dev.ini tests/agent-finance-test.php
 *
 * Creates one throw-away agent (username zz_fin_test_*) with ledger rows and
 * removes everything it wrote.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentsettlement.php';
require_once INCLUDE_PATH . '/adminalerts.php';

$db = (string) (Database::fetch('SELECT DATABASE() AS d')['d'] ?? '');
if (!preg_match('/test/i', $db)) {
    fwrite(STDERR, "refusing to run against '$db' (not a test database)\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) {
        $pass++;
        echo "  ok   $what\n";
    } else {
        $fail++;
        echo "  FAIL $what\n";
    }
};
$throws = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage() !== '' ? $e->getMessage() : 'threw';
    }
    return '';
};

/* ------------------------------------------------------------ 1. channel tags */
echo "Booking channel\n";
$ok(BookingChannel::hasTagColumn(), 'bookings.channel_tag exists (migration applied)');
$expr = BookingChannel::sqlExpr('b');
$ok(str_contains($expr, "'WHATSAPP'") && str_contains($expr, "'GOOGLE_ADS'") && str_contains($expr, "'ONLINE'"), 'sqlExpr covers WhatsApp, Google Ads and Online');
$ok($throws(static fn() => BookingChannel::sqlExpr('b; DROP')) !== '', 'sqlExpr refuses a bad alias');
$ok(BookingChannel::filterTag('ads') === 'GOOGLE_ADS' && BookingChannel::filterTag('nope') === null, 'filterTag maps known values only');
$w = [];
$ok(BookingChannel::applyFilter($w, 'whatsapp') === 'whatsapp' && count($w) === 1, 'applyFilter adds one clause for a known value');
$w2 = [];
$ok(BookingChannel::applyFilter($w2, "x' OR 1=1") === '' && $w2 === [], 'applyFilter ignores an unknown value');

$all   = BookingChannel::counts('2000-01-01', '2099-12-31');
$total = (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0);
$ok(array_sum(array_column($all, 'n')) === $total, 'every booking carries exactly one tag (' . $total . ')');
foreach (BookingChannel::FILTERS as $src => $tag) {
    $wf = [];
    BookingChannel::applyFilter($wf, $src);
    $n = (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE ' . $wf[0], [], 0);
    $ok($n === $all[$tag]['n'], "register filter '$src' = chart count ($n)");
}
$agentWeb = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings b JOIN admins a ON a.id = b.sold_by_admin_id AND a.role = 'agent' WHERE b.source IN ('web','app')", [], 0
);
$rawAgent = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE source = 'agent'", [], 0);
$ok($all['AGENT']['n'] >= $rawAgent + $agentWeb - (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE channel_tag = 'WHATSAPP'", [], 0),
    'an agent-code sale through the public page counts as Agent (' . $agentWeb . ' such)');

$probe = Database::fetch("SELECT id, pnr FROM bookings WHERE source = 'web' AND sold_by_admin_id IS NULL AND (channel_tag IS NULL) ORDER BY id DESC LIMIT 1");
if ($probe !== null) {
    $before = BookingChannel::of((int) $probe['id']);
    $ok(in_array($before, ['ONLINE', 'GOOGLE_ADS'], true), 'a self-booked web sale is Online / Google Ads');
    $ok(BookingChannel::markWhatsappPnr((string) $probe['pnr'], 'test'), 'markWhatsappPnr tags a booking');
    $ok(BookingChannel::of((int) $probe['id']) === 'WHATSAPP', 'tagged booking reads WhatsApp');
    $ok(BookingChannel::markWhatsapp((int) $probe['id'], false, 'test'), 'the mark can be removed');
    $ok(BookingChannel::of((int) $probe['id']) === $before, 'and the booking is back on its derived tag');
    Database::run("DELETE FROM audit_logs WHERE action = 'booking.channel' AND entity_id = :p", ['p' => (string) $probe['pnr']]);
} else {
    echo "  skip (no plain web booking in this database)\n";
}
$ok(BookingChannel::markWhatsapp(0, true) === false, 'marking booking 0 is refused quietly');

/* ------------------------------------------------------------ 2. Nepal contacts */
echo "Nepal office contacts\n";
$keys  = ['np_ceo_name', 'np_ceo_phone', 'np_ops_name', 'np_ops_phone', 'np_parking_name', 'np_parking_phone'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
}
Settings::flush();
$team = Settings::nepalTeam();
$ok(count($team) === 1 && $team[0]['key'] === 'ceo' && $team[0]['tel'] === '919726401507', 'nothing typed: only the CEO, on the Director line');
Settings::set('np_ops_phone', '9800000011', 'string', 'company', true);
Settings::set('np_parking_phone', '12345', 'string', 'company', true);
Settings::set('np_parking_name', 'Test Person', 'string', 'company', true);
Settings::flush();
$team = Settings::nepalTeam();
$ok(count($team) === 2 && $team[1]['tel'] === '9779800000011' && $team[1]['name'] === 'Lokesh Mama', 'a bare 10-digit number is read as +977; the default name is kept');
$ok(!in_array('parking', array_column($team, 'key'), true), 'a number too short to dial keeps that person off the page');
foreach ($keys as $k) {
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
    if ($saved[$k] !== null) {
        Settings::set($k, (string) $saved[$k]['svalue'], 'string', 'company', true);
    }
}
Settings::flush();

/* ------------------------------------------------------------ 3. settlements */
echo "Settlements\n";
$ok(AgentSettlement::hasTable(), 'agent_settlements exists (migration applied)');
$uname = 'zz_fin_test_' . bin2hex(random_bytes(3));
$aid = Database::insert('admins', [
    'username' => $uname, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
    'full_name' => 'ZZ Finance Test', 'role' => 'agent', 'is_active' => 1,
]);
$today = todayISO();
try {
    AgentWallet::record($aid, 'commission', 1000, ['note' => 'test credit', 'by' => 0]);
    AgentWallet::record($aid, 'cash_due', 400, ['note' => 'test cash', 'by' => 0]);

    $calc = AgentSettlement::compute($aid, $today, $today);
    $ok($calc['gross'] === 1000.0 && $calc['due'] === 1000.0 && $calc['cashHeld'] === 400.0 && $calc['opening'] === 0.0, 'compute reads the ledger (earned 1000, cash 400)');
    $ok($throws(static fn() => AgentSettlement::compute($aid, $today, addDaysISO($today, -1))) !== '', 'a period that ends before it starts is refused');

    $ded = AgentSettlement::cleanDeductions(['Ticket book', '', 'x'], ['100', '0', '-5']);
    $ok(count($ded) === 1 && $ded[0]['amount'] === 100.0, 'cleanDeductions keeps only positive lines');
    $ok($throws(static fn() => AgentSettlement::cleanDeductions([''], ['50'])) !== '', 'a deduction without a reason is refused');
    $ok($throws(static fn() => AgentSettlement::totals($calc, [['label' => 'x', 'amount' => 5000.0]], 0, 0, 0)) !== '', 'deductions above the commission due are refused');
    $ok($throws(static fn() => AgentSettlement::totals($calc, [], 0, 500, 0)) !== '', 'a net-off above the cash held is refused');

    $sid = AgentSettlement::createDraft($aid, $today, $today,
        ['deductions' => $ded, 'bonus' => 50, 'bonus_note' => 'target', 'cash_offset' => 300, 'notes' => 'test'], 0);
    $row = AgentSettlement::get($sid);
    $ok($row !== null && $row['status'] === 'draft' && (float) $row['net_amount'] === 650.0, 'draft: 1000 - 100 + 50 - 300 = 650 net');
    $ok(AgentWallet::balances($aid)['commission'] === 1000.0, 'a draft moves no money');
    $ok(preg_match('/^ST-\d{4}-\d{4,}$/', AgentSettlement::number($row)) === 1, 'settlement number looks like ST-yymm-0001');
    $ok($throws(static fn() => AgentSettlement::createDraft($aid, $today, $today, [], 0)) !== '', 'a second open settlement for the same agent is refused');
    $ok($throws(static fn() => AgentSettlement::markPaid($sid, 'cash', '', 0)) !== '', 'a draft cannot be paid');

    AgentSettlement::approve($sid, 0);
    $ok(AgentSettlement::get($sid)['status'] === 'approved' && AgentWallet::balances($aid)['commission'] === 1000.0, 'approved - still no money moved');
    $ok($throws(static fn() => AgentSettlement::updateDraft($sid, [], 0)) !== '', 'an approved settlement cannot be edited');
    $ok($throws(static fn() => AgentSettlement::markPaid($sid, 'adjust', '', 0)) !== '', '"no payment" is refused while there is a net to pay');
    $ok($throws(static fn() => AgentSettlement::markPaid($sid, 'bitcoin', '', 0)) !== '', 'an unknown payment mode is refused');

    AgentSettlement::markPaid($sid, 'upi', 'UTR123', 0);
    $row = AgentSettlement::get($sid);
    $bal = AgentWallet::balances($aid);
    $ok($row['status'] === 'paid' && $row['payment_ref'] === 'UTR123', 'paid, with mode and reference');
    $ok($bal['commission'] === 0.0, 'ledger: commission balance is settled to 0 (was 1000)');
    $ok($bal['cash'] === 100.0, 'ledger: cash held 400 - 300 net-off = 100');
    $ok(count(array_filter(explode(',', (string) $row['ledger_ids']))) === 5, 'five ledger rows: deduction, bonus, net-off pair, payout');
    $ok($throws(static fn() => AgentSettlement::markPaid($sid, 'upi', 'UTR123', 0)) !== '', 'a second "Mark paid" is refused (no double payout)');
    $ok(AgentWallet::balances($aid)['commission'] === 0.0, 'and the balance did not move again');
    $ok($throws(static fn() => AgentSettlement::cancel($sid, 0, 'x')) !== '', 'a paid settlement cannot be cancelled');

    $ok($throws(static fn() => AgentSettlement::agentRespond($sid, $aid + 99999, true, '')) !== '', 'another agent cannot confirm it');
    $ok($throws(static fn() => AgentSettlement::agentRespond($sid, $aid, false, '')) !== '', 'a dispute needs a reason');
    AgentSettlement::agentRespond($sid, $aid, true, '');
    $ok(AgentSettlement::get($sid)['status'] === 'confirmed', 'the agent confirms');
    AgentSettlement::agentRespond($sid, $aid, false, 'two tickets missing');
    $ok(AgentSettlement::get($sid)['status'] === 'disputed', 'the agent can still raise a dispute');
    AgentSettlement::resolveDispute($sid, 0, 'checked - tickets were cancelled');
    $ok(AgentSettlement::get($sid)['status'] === 'paid', 'the office closes the dispute');

    $mine = AgentSettlement::listing([], $aid);
    $ok(count($mine) === 1, "the agent's own list shows the paid settlement");
    [$nf, $nt] = AgentSettlement::defaultPeriod($aid);
    $ok($nf === $today && $nt === $today, 'next default period starts after the last settled one (capped at today)');

    /* a draft the ledger no longer backs must not be paid */
    AgentWallet::record($aid, 'commission', 500, ['note' => 'test credit 2', 'by' => 0]);
    $sid2 = AgentSettlement::createDraft($aid, $today, $today, [], 0);
    $ok((float) AgentSettlement::get($sid2)['net_amount'] === 500.0, 'second settlement: 500 due');
    $ok(count(AgentSettlement::listing([], $aid)) === 1, 'the agent does not see an office draft');
    AgentSettlement::approve($sid2, 0);
    AgentWallet::record($aid, 'payout', 200, ['note' => 'typed on Agent 360', 'by' => 0]);
    $msg = $throws(static fn() => AgentSettlement::markPaid($sid2, 'cash', '', 0));
    $ok($msg !== '' && AgentWallet::balances($aid)['commission'] === 300.0, 'ledger changed after approval -> Mark paid is refused, nothing written');
    AgentSettlement::cancel($sid2, 0, 'ledger changed');
    $ok(AgentSettlement::get($sid2)['status'] === 'cancelled', 'that settlement can be cancelled');

    $pend = array_values(array_filter(AgentSettlement::pending(), static fn(array $p): bool => $p['id'] === $aid));
    $ok(count($pend) === 1 && $pend[0]['commission'] === 300.0 && $pend[0]['cash'] === 100.0 && $pend[0]['net'] === 200.0, 'pending list: 300 due, 100 cash, net 200');
    $grid = AgentSettlement::commissionGrid(3);
    $mineG = array_values(array_filter($grid['rows'], static fn(array $r): bool => $r['id'] === $aid));
    $ok(count($grid['months']) === 3 && count($mineG) === 1 && $mineG[0]['earned'] === 1500.0 && $mineG[0]['paid'] === 1150.0 && $mineG[0]['due'] === 300.0,
        'commission grid: earned 1500, paid 1150 (300 net-off + 650 + 200), due 300');

    /* ------------------------------------------------------------ 4. alerts */
    echo "Admin alerts\n";
    $n0 = AdminAlerts::unreadCount();
    $id1 = AdminAlerts::push('test', 'ZZ test alert', 'body', '/admin/index.php', '🧪', 'zz-fin-test-' . $uname);
    $id2 = AdminAlerts::push('test', 'ZZ test alert', 'body', '/admin/index.php', '🧪', 'zz-fin-test-' . $uname);
    $ok($id1 > 0 && $id2 === 0, 'the same alert key is stored once');
    $ok(AdminAlerts::unreadCount() === $n0 + 1, 'the bell count goes up by one');
    AdminAlerts::markRead($id1);
    $ok(AdminAlerts::unreadCount() === $n0, 'mark read brings it back');
    $ok(AdminAlerts::push('test', 'x', 'y', 'https://evil.example/x', '🧪', 'zz-fin-test2-' . $uname) > 0
        && (string) Database::scalar("SELECT COALESCE(link,'') FROM notifications WHERE title = 'x' ORDER BY id DESC LIMIT 1", [], '') === '', 'an off-site link is dropped');
    $found = AdminAlerts::evaluate(['advance_limit' => ['on' => true, 'limit' => 1], 'settlement_due' => ['on' => true, 'days' => -1],
                                    'cash_holding' => ['on' => true, 'amount' => 50, 'days' => -1]], false);
    $mineA = array_values(array_filter($found, static fn(array $a): bool => str_contains($a['key'], ':' . $aid . ':')));
    $types = array_column($mineA, 'type');
    $ok(in_array('settlement_due', $types, true), 'commission unpaid past the limit raises an alert');
    $ok(in_array('cash_holding', $types, true), 'cash held past the limit raises an alert');
    $ok(!in_array('advance_limit', $types, true), 'no advance, no advance alert');
    $quiet = AdminAlerts::evaluate(['advance_limit' => ['on' => false], 'settlement_due' => ['on' => false], 'cash_holding' => ['on' => false],
                                    'low_bookings' => ['on' => false], 'cancel_spike' => ['on' => false], 'agent_inactive' => ['on' => false]], false);
    $ok($quiet === [], 'every rule switched off = no alerts');
    $ok(count(AdminAlerts::RULES) >= 6 && isset(AdminAlerts::prefs()['cash_holding']['amount']), 'preferences come with defaults');
} finally {
    Database::run("DELETE FROM notifications WHERE audience = 'admin' AND (title = 'ZZ test alert' OR title = 'x')");
    Database::run("DELETE FROM automation_log WHERE dedupe_key LIKE :k", ['k' => '%zz-fin-test%']);
    Database::run('DELETE FROM agent_settlements WHERE agent_admin_id = :a', ['a' => $aid]);
    Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $aid]);
    Database::run("DELETE FROM audit_logs WHERE (entity_type = 'admin' AND entity_id = :a) OR (entity_type = 'settlement' AND action LIKE 'settlement.%' AND created_at >= :t)",
        ['a' => (string) $aid, 't' => $today . ' 00:00:00']);
    Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $aid]);
    Database::run('DELETE FROM admins WHERE id = :a', ['a' => $aid]);
}

/* ------------------------------------------------------------ 5. roles */
echo "Roles\n";
$src = (string) file_get_contents(INCLUDE_PATH . '/auth.php');
$ok(str_contains($src, "'operations' => [") && str_contains($src, "'viewer' => ["), 'operations and viewer roles are defined');
$opsBlock = substr($src, (int) strpos($src, "'operations' => ["), 900);
$ok(!str_contains($opsBlock, "'commissions.pay'") && !str_contains($opsBlock, "'reports.view'") && !str_contains($opsBlock, "'settings.manage'"),
    'operations cannot pay commission, read money reports or change settings');
$ok(in_array('index.php', Auth::VIEWER_PAGES, true) && !in_array('settings.php', Auth::VIEWER_PAGES, true) && !in_array('messages-log.php', Auth::VIEWER_PAGES, true),
    'a viewer may open the dashboard, never settings or the message log');
$roleCol = (string) Database::scalar("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role'", [], '');
$ok(str_contains($roleCol, "'operations'") && str_contains($roleCol, "'viewer'"), 'admins.role accepts the two new roles');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
