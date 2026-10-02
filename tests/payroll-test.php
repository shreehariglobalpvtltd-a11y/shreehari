<?php
/**
 * Staff Payroll register (2 Oct 2026) — includes/payroll.php + admin/payroll.php.
 *
 * Proves:
 *   • database/upgrade-2026-10-02-staff-payroll.sql applies cleanly through
 *     tests/apply-sql.php, twice (idempotent);
 *   • staff validation (name, 10-digit mobile, job, salary) and the +91 / +977
 *     choice the WhatsApp slip uses; a person can be linked to Drivers & Crew;
 *   • an advance of 5,000 at 2,000 a month is taken back 2,000 / 2,000 / 1,000
 *     over three salaries and closes itself — each payment and its recovery in
 *     one transaction;
 *   • the same month cannot be paid twice (friendly refusal + the UNIQUE key);
 *   • a correction is an UPDATE (audited) that re-balances the advances;
 *   • a mistaken advance can be cancelled only while nothing was recovered;
 *   • the month sheet, the slip text, the CSV rows, and the page renders
 *     through tests/render-admin.php with no warnings;
 *   • over HTTP: manager / accountant get 200, a full add → advance → pay →
 *     correct → deactivate round trip works, the CSV has a BOM + header +
 *     formula guard, and counter / official / agent are refused with 403.
 *
 *   php tests/payroll-test.php
 *   SHG_TEST_BASE=http://localhost:8961 php tests/payroll-test.php
 *
 * The HTTP part needs the dev server (SHG_TEST_BASE, default :8899); when it
 * is not reachable those checks are reported as SKIP, not as passes.
 * Everything the test creates — staff, advances, payments, their audit rows,
 * one throwaway driver and five throwaway staff logins — is deleted at the
 * end. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/payroll.php';

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  \033[33mSKIP\033[0m  $l\n"; }
function expectThrow(string $l, callable $fn, string $needle = ''): void
{
    try {
        $fn();
        check($l . ' (expected rejection)', false);
    } catch (PDOException $e) {
        check($l . ' — refused by a raw SQL error instead of a message: ' . $e->getMessage(), false);
    } catch (RuntimeException $e) {
        $ok = $needle === '' || stripos($e->getMessage(), $needle) !== false;
        check($l . ' → "' . $e->getMessage() . '"', $ok);
    }
}
function money_is(float $a, float $b): bool { return abs($a - $b) < 0.005; }

const NAME   = 'ZZ Payroll Test';
const P1     = '2025-01';
const P2     = '2025-02';
const P3     = '2025-03';
const P4     = '2025-04';
const P5     = '2025-05';
const DPHONE = '9000000771';
const PW     = 'PayrollTest@12345';

$ROOT = dirname(__DIR__);
$MIG  = $ROOT . '/' . Payroll::MIGRATION;
$BASE = rtrim((string) (getenv('SHG_TEST_BASE') ?: getenv('SHG_BASE') ?: 'http://localhost:8899'), '/');
$ACCOUNTS = ['pr-test-manager' => 'manager', 'pr-test-accountant' => 'accountant', 'pr-test-counter' => 'counter',
             'pr-test-official' => 'official', 'pr-test-agent' => 'agent'];
$JARS = [];

/** Run a PHP script in a child process (same php.ini via PHPRC). @return array{0:int,1:string} */
function runPhp(array $args): array
{
    $cmd = escapeshellarg(PHP_BINARY);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

/** One HTTP request with a cookie jar. @return array{code:int,body:string,head:string,location:string} */
function req(string $method, string $url, string $jar, array $form = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($form !== []) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    return ['code' => $code, 'body' => substr($raw, $hsz), 'head' => $head,
            'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : ''];
}
function csrfOf(string $html): string
{
    return preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}
function jarFor(string $name): string
{
    global $JARS;
    $p = sys_get_temp_dir() . '/shg_payroll_' . $name . '_' . getmypid() . '.cookies';
    @unlink($p);
    $JARS[] = $p;
    return $p;
}
function login(string $user, string $jar): bool
{
    // MAX_LOGIN_ATTEMPTS counts every attempt per IP — clear it before each sign-in.
    try { Database::delete('rate_limits', "bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
    $lp = req('GET', '/admin/login.php', $jar);
    $li = req('POST', '/admin/login.php', $jar, ['shg_csrf' => csrfOf($lp['body']), 'username' => $user, 'password' => PW]);
    return $li['code'] === 302;
}

/** A pay form. */
function payIn(float $gross, float $adv, float $bonus = 0.0, float $other = 0.0, ?float $net = null, array $extra = []): array
{
    return $extra + [
        'gross' => $gross, 'advance_deduction' => $adv, 'bonus' => $bonus, 'other_deduction' => $other,
        'net' => $net === null ? '' : (string) $net, 'paid_on' => todayISO(), 'method' => 'cash',
    ];
}

function auditCount(string $action, int $staffId): int
{
    return (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = :a AND entity_type = 'staff_member' AND entity_id = :e",
        ['a' => $action, 'e' => (string) $staffId], 0);
}

function cleanup(array $staffIds): void
{
    global $ACCOUNTS, $JARS;
    try {
        $ids = array_map('intval', $staffIds);
        foreach (Database::fetchAll("SELECT id FROM staff_members WHERE full_name LIKE 'ZZ Payroll%' OR full_name LIKE '=ZZ Payroll%'") as $r) {
            $ids[] = (int) $r['id'];
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            Database::delete('staff_salary_payments', 'staff_id = :s', ['s' => $id]);
            Database::delete('staff_advances', 'staff_id = :s', ['s' => $id]);
            Database::delete('audit_logs', "entity_type = 'staff_member' AND entity_id = :e", ['e' => (string) $id]);
            Database::delete('staff_members', 'id = :s', ['s' => $id]);
        }
    } catch (Throwable $e) {
        echo "  cleanup (staff): " . $e->getMessage() . "\n";
    }
    try { Database::delete('drivers', 'phone = :p', ['p' => DPHONE]); } catch (Throwable $e) {}
    foreach (array_keys($ACCOUNTS) as $u) {
        try {
            $aid = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
            if ($aid > 0) {
                try { Database::delete('admin_devices', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
                try { Database::delete('admin_login_events', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
                Database::delete('admins', 'id = :a', ['a' => $aid]);
            }
            try { Database::delete('admin_login_events', 'username = :u', ['u' => $u]); } catch (Throwable $e) {}
        } catch (Throwable $e) {
            echo "  cleanup ($u): " . $e->getMessage() . "\n";
        }
    }
    foreach ($JARS as $j) { @unlink($j); }
}

echo "\n=== Staff Payroll (includes/payroll.php · admin/payroll.php) ===\n\n";

$staffIds = [];
try {
    /* ---------------------------------------------------------------- 0 */
    echo "--- migration\n";
    $sql = (string) file_get_contents($MIG);
    check('migration has no block comment and no semicolon inside a COMMENT string',
        !str_contains($sql, '/*') && !str_contains($sql, '*/') && preg_match("/COMMENT\\s+'[^']*;[^']*'/", $sql) !== 1);
    foreach ([1, 2] as $run) {
        [$code, $out] = runPhp([$ROOT . '/tests/apply-sql.php', $MIG]);
        check("migration applies cleanly through tests/apply-sql.php (run $run)", $code === 0 && !str_contains($out, 'ERR ') && str_contains($out, 'done'),
            'exit ' . $code . ' ' . substr($out, -200));
    }
    check('Payroll::ready() sees the three tables', Payroll::ready());
    check('UNIQUE (staff_id, period) is in place', Database::exists(
        "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'staff_salary_payments' AND INDEX_NAME = 'uq_staff_pay_period' AND NON_UNIQUE = 0"));

    cleanup([]);   // leftovers of an aborted earlier run

    /* ---------------------------------------------------------------- 1 */
    echo "--- staff\n";
    $a = Payroll::addStaff(['full_name' => NAME . ' Helper', 'phone' => '98123 45678', 'phone_country' => 'NP', 'role' => 'helper',
        'base_location' => 'Nepalgunj', 'monthly_salary' => '12,000', 'currency' => 'INR', 'joined_on' => '2024-12-01',
        'notes' => 'bus 1'], 1);
    $staffIds[] = $a;
    $sa = Payroll::staff($a) ?? [];
    check('addStaff stores name, job, salary, location, joined date',
        ($sa['full_name'] ?? '') === NAME . ' Helper' && ($sa['role'] ?? '') === 'helper' && money_is((float) ($sa['monthly_salary'] ?? 0), 12000.0)
        && ($sa['base_location'] ?? '') === 'Nepalgunj' && ($sa['joined_on'] ?? '') === '2024-12-01' && (int) ($sa['is_active'] ?? 0) === 1);
    check('mobile kept as 10 digits; country NP from the picker', ($sa['phone'] ?? '') === '9812345678' && ($sa['phone_country'] ?? '') === 'NP');
    check('WhatsApp number for a Nepali mobile is 977…', Payroll::waNumber($sa) === '9779812345678', Payroll::waNumber($sa));
    check('adding is audited (payroll.staff_add)', auditCount('payroll.staff_add', $a) === 1);

    $b = Payroll::addStaff(['full_name' => '=ZZ Payroll Formula', 'phone' => '+91 98765 43210', 'phone_country' => 'NP',
        'role' => 'office', 'monthly_salary' => 8000], 1);
    $staffIds[] = $b;
    $sb = Payroll::staff($b) ?? [];
    check('a typed +91 wins over the picker → India, 91…', ($sb['phone'] ?? '') === '9876543210' && ($sb['phone_country'] ?? '') === 'IN'
        && Payroll::waNumber($sb) === '919876543210', Payroll::waNumber($sb));

    $before = (int) Database::scalar("SELECT COUNT(*) FROM staff_members WHERE full_name LIKE '%ZZ Payroll%'", [], 0);
    expectThrow('an empty name is refused', fn() => Payroll::addStaff(['full_name' => '  ', 'role' => 'helper', 'monthly_salary' => 1], 1), 'name');
    expectThrow('a 9-digit mobile is refused', fn() => Payroll::addStaff(['full_name' => NAME . ' X', 'phone' => '981234567', 'role' => 'helper', 'monthly_salary' => 1], 1), '10 digits');
    expectThrow('an unknown job is refused', fn() => Payroll::addStaff(['full_name' => NAME . ' X', 'role' => 'pilot', 'monthly_salary' => 1], 1), 'job');
    expectThrow('a negative salary is refused', fn() => Payroll::addStaff(['full_name' => NAME . ' X', 'role' => 'helper', 'monthly_salary' => -5], 1), 'negative');
    expectThrow('a salary that is not a number is refused', fn() => Payroll::addStaff(['full_name' => NAME . ' X', 'role' => 'helper', 'monthly_salary' => 'abc'], 1), 'number');
    expectThrow('an array posted as the salary is refused, not a PHP warning',
        fn() => Payroll::addStaff(['full_name' => NAME . ' X', 'role' => 'helper', 'monthly_salary' => ['1']], 1), '');
    check('no refused form wrote a row', (int) Database::scalar("SELECT COUNT(*) FROM staff_members WHERE full_name LIKE '%ZZ Payroll%'", [], 0) === $before);

    // Drivers & Crew link: the drivers table has no pay fields, so a driver is paid here.
    $driverId = Database::insert('drivers', ['full_name' => NAME . ' Driver', 'phone' => DPHONE, 'role' => 'driver', 'is_active' => 1]);
    $c = Payroll::addStaff(['driver_id' => $driverId, 'full_name' => '', 'phone' => '', 'role' => '', 'monthly_salary' => 15000], 1);
    $staffIds[] = $c;
    $sc = Payroll::staff($c) ?? [];
    check('linking a driver fills name, mobile and job from Drivers & Crew',
        ($sc['full_name'] ?? '') === NAME . ' Driver' && ($sc['phone'] ?? '') === DPHONE && ($sc['role'] ?? '') === 'driver' && (int) ($sc['driver_id'] ?? 0) === $driverId);
    expectThrow('the same driver cannot be on the payroll twice', fn() => Payroll::addStaff(['driver_id' => $driverId, 'monthly_salary' => 1], 1), 'already on the payroll');

    Payroll::updateStaff($a, ['full_name' => NAME . ' Helper', 'phone' => '9812345678', 'phone_country' => 'NP', 'role' => 'helper',
        'base_location' => 'Rupaidiha', 'monthly_salary' => '12000', 'currency' => 'INR', 'joined_on' => '2024-12-01', 'notes' => 'bus 1'], 1);
    $edit = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE action = 'payroll.staff_edit' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => (string) $a]);
    check('edit saves and audits only what changed (location Nepalgunj → Rupaidiha)',
        (Payroll::staff($a)['base_location'] ?? '') === 'Rupaidiha' && $edit !== null
        && jsonColumn($edit['old_value']) === ['base_location' => 'Nepalgunj'] && jsonColumn($edit['new_value']) === ['base_location' => 'Rupaidiha'],
        json_encode($edit));
    $edits = auditCount('payroll.staff_edit', $a);
    Payroll::updateStaff($a, ['full_name' => NAME . ' Helper', 'phone' => '9812345678', 'phone_country' => 'NP', 'role' => 'helper',
        'base_location' => 'Rupaidiha', 'monthly_salary' => '12000', 'currency' => 'INR', 'joined_on' => '2024-12-01', 'notes' => 'bus 1'], 1);
    check('saving the same details again writes no audit row', auditCount('payroll.staff_edit', $a) === $edits);

    /* ---------------------------------------------------------------- 2 */
    echo "--- advance 5,000 at 2,000 a month\n";
    expectThrow('an advance of 0 is refused', fn() => Payroll::giveAdvance($a, ['amount' => 0], 1), 'more than 0');
    expectThrow('an advance dated tomorrow is refused', fn() => Payroll::giveAdvance($a, ['amount' => 100, 'given_on' => addDaysISO(todayISO(), 1)], 1), 'future');
    expectThrow('a monthly deduction bigger than the advance is refused', fn() => Payroll::giveAdvance($a, ['amount' => 100, 'monthly_deduction' => 200], 1), 'monthly deduction');
    $adv1 = Payroll::giveAdvance($a, ['amount' => 5000, 'monthly_deduction' => 2000, 'given_on' => todayISO(), 'reason' => 'Dashain'], 1);
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('advance saved open, nothing recovered', ($av['status'] ?? '') === 'open' && money_is((float) ($av['recovered'] ?? -1), 0.0) && money_is((float) ($av['amount'] ?? 0), 5000.0));
    check('balance owed 5,000', money_is(Payroll::openBalance($a), 5000.0));
    check('the Pay form starts with a 2,000 deduction', money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 12000.0), 2000.0));
    check('giving it is audited (payroll.advance)', auditCount('payroll.advance', $a) === 1);

    /* ---------------------------------------------------------------- 3 */
    echo "--- month 1\n";
    expectThrow('a posted net that does not match the figures is refused', fn() => Payroll::pay($a, P1, payIn(12000, 2000, 0, 0, 9999), 1), 'does not match');
    expectThrow('taking back more than the 5,000 owed is refused', fn() => Payroll::pay($a, P1, payIn(12000, 5000.01), 1), 'still owed');
    expectThrow('a net below zero is refused', fn() => Payroll::pay($a, P1, payIn(1000, 2000), 1), 'below zero');
    expectThrow('a payment dated tomorrow is refused', fn() => Payroll::pay($a, P1, payIn(12000, 2000, 0, 0, null, ['paid_on' => addDaysISO(todayISO(), 1)]), 1), 'future');
    expectThrow('an unknown method is refused', fn() => Payroll::pay($a, P1, payIn(12000, 2000, 0, 0, null, ['method' => 'cheque']), 1), 'cash, UPI or bank');
    expectThrow('a month like 2025-13 is refused', fn() => Payroll::pay($a, '2025-13', payIn(12000, 2000), 1), 'month');
    expectThrow('a salary of 0 is refused', fn() => Payroll::pay($a, P1, payIn(0, 0), 1), 'salary amount');
    check('no refused attempt wrote a payment or moved the advance',
        (int) Database::scalar('SELECT COUNT(*) FROM staff_salary_payments WHERE staff_id = :s', ['s' => $a], 0) === 0
        && money_is(Payroll::openBalance($a), 5000.0));

    $p1 = Payroll::pay($a, P1, payIn(12000, 2000, 0, 0, 10000), 1);
    $pay1 = Payroll::payment($p1) ?? [];
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('month 1: deduction 2,000, net = 12,000 − 2,000 = 10,000', money_is((float) ($pay1['advance_deduction'] ?? 0), 2000.0) && money_is((float) ($pay1['net_paid'] ?? 0), 10000.0)
        && ($pay1['period'] ?? '') === P1 && ($pay1['currency'] ?? '') === 'INR');
    check('month 1: advance recovered 2,000, still open', money_is((float) ($av['recovered'] ?? 0), 2000.0) && ($av['status'] ?? '') === 'open');
    check('month 1: 3,000 still owed', money_is(Payroll::openBalance($a), 3000.0));
    check('paying is audited (payroll.pay)', auditCount('payroll.pay', $a) === 1);

    /* ---------------------------------------------------------------- 4 */
    echo "--- month 2\n";
    check('month 2 starts at 2,000 again', money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 12000.0), 2000.0));
    $p2 = Payroll::pay($a, P2, payIn(12000, 2000), 1);   // no posted net (no JS) → worked out on the server
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('month 2: net 10,000 (server-side), recovered 4,000, still open',
        money_is((float) (Payroll::payment($p2)['net_paid'] ?? 0), 10000.0) && money_is((float) ($av['recovered'] ?? 0), 4000.0) && ($av['status'] ?? '') === 'open');

    /* ---------------------------------------------------------------- 5 */
    echo "--- month 3\n";
    check('month 3: deduction is capped at the remaining 1,000', money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 12000.0), 1000.0));
    $p3 = Payroll::pay($a, P3, payIn(12000, 1000, 0, 0, 11000), 1);
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('month 3: net 11,000', money_is((float) (Payroll::payment($p3)['net_paid'] ?? 0), 11000.0));
    check('month 3: advance fully recovered and CLEARED (cleared_at set)', money_is((float) ($av['recovered'] ?? 0), 5000.0) && ($av['status'] ?? '') === 'cleared' && !empty($av['cleared_at']));
    check('nothing owed, nothing suggested any more', money_is(Payroll::openBalance($a), 0.0) && money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 12000.0), 0.0));

    /* ---------------------------------------------------------------- 6 */
    echo "--- the same month twice\n";
    expectThrow('paying Mar 2025 a second time is refused', fn() => Payroll::pay($a, P3, payIn(12000, 0), 1), 'already paid');
    check('still exactly one payment for Mar 2025', (int) Database::scalar('SELECT COUNT(*) FROM staff_salary_payments WHERE staff_id = :s AND period = :p', ['s' => $a, 'p' => P3], 0) === 1);
    $dupBlocked = false;
    try {
        Database::insert('staff_salary_payments', ['staff_id' => $a, 'period' => P3, 'gross' => 1, 'net_paid' => 1, 'paid_on' => todayISO()]);
    } catch (PDOException $e) {
        $dupBlocked = (string) $e->getCode() === '23000';
    }
    check('the UNIQUE key refuses a raw duplicate too (last line of defence)', $dupBlocked);

    /* ---------------------------------------------------------------- 7 */
    echo "--- corrections (UPDATE, never delete + insert)\n";
    $auditBefore = auditCount('payroll.pay_correct', $a);
    Payroll::correctPayment($p3, payIn(12000, 1000, 500, 0, 11500, ['method' => 'upi', 'reference' => 'UTR77']), 1);
    $pay3 = Payroll::payment($p3) ?? [];
    check('bonus 500 added: net 11,500, same row id, method UPI, correction stamped',
        money_is((float) ($pay3['net_paid'] ?? 0), 11500.0) && money_is((float) ($pay3['bonus'] ?? 0), 500.0) && ($pay3['method'] ?? '') === 'upi'
        && !empty($pay3['updated_at']) && (int) ($pay3['updated_by'] ?? 0) === 1);
    check('the correction is audited (payroll.pay_correct)', auditCount('payroll.pay_correct', $a) === $auditBefore + 1);

    Payroll::correctPayment($p2, payIn(12000, 0), 1);
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('month 2 deduction corrected to 0 → advance re-derived: recovered 3,000, OPEN again, 2,000 owed',
        money_is((float) ($av['recovered'] ?? 0), 3000.0) && ($av['status'] ?? '') === 'open' && empty($av['cleared_at']) && money_is(Payroll::openBalance($a), 2000.0));
    expectThrow('a correction cannot take back more than is owed (2,000 + its own 0)', fn() => Payroll::correctPayment($p2, payIn(12000, 2500), 1), 'still owed');
    Payroll::correctPayment($p2, payIn(12000, 2000, 0, 0, 10000), 1);
    $av = Database::fetch('SELECT * FROM staff_advances WHERE id = :i', ['i' => $adv1]) ?? [];
    check('corrected back to 2,000 → cleared again', ($av['status'] ?? '') === 'cleared' && money_is((float) ($av['recovered'] ?? 0), 5000.0));
    $n = auditCount('payroll.pay_correct', $a);
    Payroll::correctPayment($p2, payIn(12000, 2000, 0, 0, 10000), 1);
    check('a correction that changes nothing writes no audit noise', auditCount('payroll.pay_correct', $a) === $n);
    check('still three payments for the helper (nothing deleted / re-inserted)',
        (int) Database::scalar('SELECT COUNT(*) FROM staff_salary_payments WHERE staff_id = :s', ['s' => $a], 0) === 3
        && (int) Database::scalar('SELECT MIN(id) FROM staff_salary_payments WHERE staff_id = :s', ['s' => $a], 0) === $p1);

    /* ---------------------------------------------------------------- 8 */
    echo "--- cancelling a mistaken advance\n";
    $adv2 = Payroll::giveAdvance($a, ['amount' => 1500, 'monthly_deduction' => 0, 'given_on' => todayISO()], 1);
    check('a monthly deduction of 0 means the whole 1,500 at the next salary', money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 12000.0), 1500.0));
    check('…but never more than the salary itself', money_is(Payroll::suggestedDeduction(Payroll::openAdvances($a), 900.0), 900.0));
    Payroll::cancelAdvance($adv2, 1);
    check('the untouched advance is cancelled, nothing owed', (string) Database::scalar('SELECT status FROM staff_advances WHERE id = :i', ['i' => $adv2], '') === 'cancelled'
        && money_is(Payroll::openBalance($a), 0.0) && auditCount('payroll.advance_cancel', $a) === 1);
    expectThrow('cancelling it twice is refused', fn() => Payroll::cancelAdvance($adv2, 1), 'open advance');
    expectThrow('a cleared advance cannot be cancelled', fn() => Payroll::cancelAdvance($adv1, 1), 'open advance');
    $adv3 = Payroll::giveAdvance($c, ['amount' => 3000, 'monthly_deduction' => 1000], 1);
    Payroll::pay($c, P3, payIn(15000, 1000), 1);
    expectThrow('a partly recovered advance cannot be cancelled', fn() => Payroll::cancelAdvance($adv3, 1), 'already taken back');

    /* ---------------------------------------------------------------- 9 */
    echo "--- active / inactive, joined later\n";
    Payroll::setActive($b, false, 1);
    $rows3 = keyBy(Payroll::monthSheet(P3)['rows'], 'id');
    check('a deactivated person (unpaid that month) leaves the month sheet', !isset($rows3[$b]));
    check('…and is listed under inactive staff', in_array($b, array_map('intval', pluck(Payroll::inactiveStaff(), 'id')), true));
    expectThrow('an inactive person cannot be paid', fn() => Payroll::pay($b, P3, payIn(8000, 0), 1), 'inactive');
    expectThrow('…or given an advance', fn() => Payroll::giveAdvance($b, ['amount' => 100], 1), 'inactive');
    check('deactivating is audited', auditCount('payroll.staff_deactivate', $b) === 1);
    Payroll::setActive($b, true, 1);
    check('re-activated', (int) (Payroll::staff($b)['is_active'] ?? 0) === 1 && auditCount('payroll.staff_activate', $b) === 1);
    Payroll::updateStaff($b, ['full_name' => '=ZZ Payroll Formula', 'phone' => '9876543210', 'phone_country' => 'IN', 'role' => 'office',
        'monthly_salary' => 8000, 'joined_on' => '2025-06-01'], 1);

    /* --------------------------------------------------------------- 10 */
    echo "--- month sheet\n";
    $sheet = Payroll::monthSheet(P3);
    $rows3 = keyBy($sheet['rows'], 'id');
    $ra = $rows3[$a] ?? null;
    check('the helper is on the Mar 2025 sheet as paid 11,500', $ra !== null && $ra['payment'] !== null && money_is((float) $ra['payment']['net_paid'], 11500.0) && $ra['due'] === true);
    check('someone who joined in June 2025 is "not due" for March', isset($rows3[$b]) && $rows3[$b]['due'] === false && $rows3[$b]['joined_later'] === true);
    $sheet4 = keyBy(Payroll::monthSheet(P4)['rows'], 'id');
    check('April: the helper is due 12,000 (no advance open)', isset($sheet4[$a]) && $sheet4[$a]['payment'] === null && $sheet4[$a]['due'] === true
        && money_is((float) $sheet4[$a]['expected_net'], 12000.0));
    check('April: the driver is due 15,000 − 1,000 advance = 14,000', isset($sheet4[$c]) && money_is((float) $sheet4[$c]['expected_net'], 14000.0)
        && money_is((float) $sheet4[$c]['balance'], 2000.0));
    $t = $sheet['totals'];
    check('Mar 2025 totals count our two payments (11,500 + 14,000 net, 1,000 + 1,000 taken back)',
        (float) $t['paid'] >= 25500.0 - 0.005 && (int) $t['paidCount'] >= 2 && (float) $t['recovered'] >= 2000.0 - 0.005 && (float) $t['advances'] >= 2000.0 - 0.005,
        json_encode($t));

    /* --------------------------------------------------------------- 11 */
    echo "--- WhatsApp slip, money format, CSV rows\n";
    $slip = Payroll::slipText(Payroll::staff($a) ?? [], Payroll::payment($p3) ?? [], 0.0);
    $company = Settings::getString('company_name', APP_NAME);
    check('slip reads month / name / salary / advance / bonus / net / date / company',
        str_contains($slip, 'Salary slip – Mar 2025') && str_contains($slip, 'Name: ' . NAME . ' Helper') && str_contains($slip, 'Salary: ₹12,000')
        && str_contains($slip, 'Advance deduction: −₹1,000') && str_contains($slip, 'Bonus: +₹500') && str_contains($slip, 'Net paid: ₹11,500')
        && str_contains($slip, 'Date: ' . formatDate(todayISO(), 'j M Y') . ' · UPI (UTR77)') && str_ends_with($slip, $company) && !str_contains($slip, 'still to repay'),
        $slip);
    $slipC = Payroll::slipText(Payroll::staff($c) ?? [], Payroll::paymentFor($c, P3) ?? [], Payroll::openBalance($c));
    check('a slip mentions the advance still to repay when there is one', str_contains($slipC, 'Advance still to repay: ₹2,000'), $slipC);
    $link = Payroll::waLink(Payroll::staff($a) ?? [], $slip);
    check('wa.me link goes to +977 for the Nepali mobile with the slip pre-typed', str_starts_with($link, 'https://wa.me/9779812345678?text=Salary%20slip%20%E2%80%93%20Mar%202025'), substr($link, 0, 90));
    check('no mobile → wa.me/?text= (WhatsApp asks who to send it to)', str_starts_with(Payroll::waLink(['phone' => null], 'x'), 'https://wa.me/?text=x'));
    check('money: ₹1,23,456 · NPR 1,23,456 · paise only when present',
        Payroll::money(123456) === '₹1,23,456' && Payroll::money(123456, 'NPR') === 'NPR 1,23,456' && Payroll::money(1000.5) === '₹1,000.50');
    [$csvHead, $csvRows] = Payroll::csv(P3);
    $mine = array_values(array_filter($csvRows, static fn(array $r): bool => $r[1] === NAME . ' Helper'));
    check('CSV rows: header + the helper as Paid, net 11500.00, owing 0.00',
        $csvHead[0] === 'Month' && $csvHead[1] === 'Name' && count($mine) === 1 && $mine[0][7] === 'Paid' && $mine[0][12] === '11500.00' && $mine[0][16] === '0.00',
        json_encode($mine));

    /* --------------------------------------------------------------- 12 */
    echo "--- page render (tests/render-admin.php)\n";
    [$code, $html] = runPhp([$ROOT . '/tests/render-admin.php', 'payroll.php', 'm=' . P3]);
    $noise = preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Stack trace)\b/', $html, $nm) === 1;
    check('payroll.php?m=2025-03 renders (exit 0, full page, no warnings)', $code === 0 && str_contains($html, '</html>') && !$noise,
        'exit ' . $code . ($noise ? ' found "' . $nm[0] . '"' : '') . ' ' . substr(strip_tags($html), 0, 200));
    check('the page shows the month, the helper, the paid pill and the WhatsApp slip',
        str_contains($html, '<title>Staff Payroll') && str_contains($html, 'Mar 2025') && str_contains($html, NAME . ' Helper')
        && str_contains($html, '✓ Paid ₹11,500') && str_contains($html, 'Send slip on WhatsApp') && str_contains($html, 'https://wa.me/9779812345678?text='));
    check('the page shows the KPI strip and the "not due" person', str_contains($html, '>Salary due<') && str_contains($html, 'Mar 2025 · ') && str_contains($html, '>Advances owed<')
        && str_contains($html, 'Not due — joined 1 Jun 2025'));
    check('the payroll is kept apart from Accounting expenses (said on the page)', str_contains($html, 'Accounting expenses'));
    [$code2, $html2] = runPhp([$ROOT . '/tests/render-admin.php', 'payroll.php', 'm=garbage']);
    check('a bad ?m= falls back to this month', $code2 === 0 && str_contains($html2, Payroll::periodLabel(Payroll::currentPeriod())));

    /* --------------------------------------------------------------- 13 */
    echo "--- HTTP ($BASE)\n";
    // Signed out, this checkout answers 302 → login; a server on that port that
    // serves another checkout (no payroll.php) answers 404 — skip, do not fail.
    $probe = function_exists('curl_init') ? req('GET', '/admin/payroll.php', jarFor('probe')) : ['code' => 0];
    if ($probe['code'] === 0 || $probe['code'] === 404) {
        skip("no dev server for this checkout at $BASE (HTTP {$probe['code']}) — set SHG_TEST_BASE to run the HTTP checks");
    } else {
        foreach ($ACCOUNTS as $u => $role) {
            $hash = password_hash(PW, PASSWORD_BCRYPT);
            $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
            if ($id > 0) {
                Database::update('admins', ['password_hash' => $hash, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0, 'failed_logins' => 0, 'locked_until' => null], 'id = :i', ['i' => $id]);
            } else {
                Database::insert('admins', ['username' => $u, 'password_hash' => $hash, 'full_name' => 'Payroll Test ' . ucfirst($role), 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
            }
        }
        $jar = [];
        foreach (array_keys($ACCOUNTS) as $u) {
            $jar[$u] = jarFor($u);
            check("$u signs in", login($u, $jar[$u]));
        }
        $mgrId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'pr-test-manager'], 0);

        $g = req('GET', '/admin/payroll.php?m=' . P3, $jar['pr-test-manager']);
        check('manager: GET /admin/payroll.php → 200 with the helper on it', $g['code'] === 200 && str_contains($g['body'], NAME . ' Helper'), 'HTTP ' . $g['code']);
        check('manager sees the write buttons (payments.verify)', str_contains($g['body'], 'name="action" value="add_staff"') && str_contains($g['body'], 'Correct payment'));
        $acc = req('GET', '/admin/payroll.php?m=' . P3, $jar['pr-test-accountant']);
        check('accountant: GET → 200', $acc['code'] === 200, 'HTTP ' . $acc['code']);
        foreach (['pr-test-counter', 'pr-test-official', 'pr-test-agent'] as $u) {
            $r = req('GET', '/admin/payroll.php?m=' . P3, $jar[$u]);
            check("$u: GET → 403, no salaries shown", $r['code'] === 403 && !str_contains($r['body'], NAME . ' Helper') && str_contains($r['body'], 'not for your role'), 'HTTP ' . $r['code']);
        }
        $cx = req('GET', '/admin/payroll.php?m=' . P3 . '&export=csv', $jar['pr-test-counter']);
        check('counter: the CSV export is refused too (403)', $cx['code'] === 403 && !str_starts_with($cx['body'], "\xEF\xBB\xBF"), 'HTTP ' . $cx['code']);
        $cp = req('POST', '/admin/payroll.php', $jar['pr-test-counter'], ['shg_csrf' => csrfOf($g['body']), 'action' => 'pay', 'staff_id' => $a, 'm' => P5] + payIn(12000, 0));
        check('counter: a POST pay is refused (403) and writes nothing', $cp['code'] === 403 && Payroll::paymentFor($a, P5) === null, 'HTTP ' . $cp['code']);
        $gx = req('GET', '/admin/payroll.php', jarFor('guest'));
        check('signed out: redirected to the login door', $gx['code'] === 302 && str_contains($gx['location'], 'login.php'), 'HTTP ' . $gx['code'] . ' ' . $gx['location']);

        $csv = req('GET', '/admin/payroll.php?m=' . P3 . '&export=csv', $jar['pr-test-manager']);
        $lines = preg_split('/\r?\n/', $csv['body']) ?: [];
        check('CSV: 200, text/csv, UTF-8 BOM, then the header row', $csv['code'] === 200 && stripos($csv['head'], 'Content-Type: text/csv') !== false
            && str_starts_with($csv['body'], "\xEF\xBB\xBF") && str_starts_with(substr($lines[0] ?? '', 3), 'Month,Name,Job,Location,Mobile'),
            'HTTP ' . $csv['code'] . ' ' . substr($csv['body'], 0, 60));
        check('CSV: attachment named payroll-2025-03.csv', str_contains($csv['head'], 'filename="payroll-2025-03.csv"'));
        check('CSV: the helper row is there', str_contains($csv['body'], '"' . NAME . ' Helper",helper,Rupaidiha'));
        check('CSV: a name starting with = is defused (formula-injection guard)', str_contains($csv['body'], "'=ZZ Payroll Formula") && !preg_match('/(^|,)=ZZ Payroll/m', $csv['body']));

        /* add → advance → pay → pay again → correct → deactivate, as the manager */
        $pg   = req('GET', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager']);
        $tok  = csrfOf($pg['body']);
        $add  = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], ['shg_csrf' => $tok, 'action' => 'add_staff', 'm' => P4,
            'full_name' => 'ZZ Payroll HTTP Person', 'phone' => '', 'phone_country' => 'IN', 'role' => 'conductor', 'base_location' => 'Surat',
            'monthly_salary' => '9000', 'currency' => 'INR']);
        $hid  = (int) Database::scalar("SELECT id FROM staff_members WHERE full_name = 'ZZ Payroll HTTP Person' ORDER BY id DESC LIMIT 1", [], 0);
        $staffIds[] = $hid;
        check('HTTP add staff → 302 back to the month, row created by the manager', $add['code'] === 302 && str_contains($add['location'], '/admin/payroll.php?m=' . P4 . '#s' . $hid)
            && $hid > 0 && (int) (Payroll::staff($hid)['created_by'] ?? 0) === $mgrId, 'HTTP ' . $add['code'] . ' ' . $add['location']);
        $after = req('GET', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager']);
        check('…and the next page shows the success message once', $after['code'] === 200 && str_contains($after['body'], 'ZZ Payroll HTTP Person is on the payroll now.'));
        $adv = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], ['shg_csrf' => $tok, 'action' => 'advance', 'staff_id' => $hid, 'm' => P4,
            'amount' => '1000', 'given_on' => todayISO(), 'monthly_deduction' => '0', 'reason' => 'http test']);
        check('HTTP advance → 302, 1,000 owed', $adv['code'] === 302 && money_is(Payroll::openBalance($hid), 1000.0), 'HTTP ' . $adv['code']);
        $view = req('GET', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager']);
        check('the row now says Due ₹8,000 after ₹1,000 advance, Pay form pre-filled with 1000',
            str_contains($view['body'], 'Due ₹8,000') && str_contains($view['body'], 'after ₹1,000 advance')
            && preg_match('/name="advance_deduction"[^>]*value="1000"/', $view['body']) === 1);
        $payForm = ['shg_csrf' => $tok, 'action' => 'pay', 'staff_id' => $hid, 'm' => P4, 'gross' => '9000', 'advance_deduction' => '1000',
            'bonus' => '0', 'other_deduction' => '0', 'net' => '8000.00', 'paid_on' => todayISO(), 'method' => 'upi', 'reference' => 'UTR123'];
        $pay = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], $payForm);
        $hp  = Payroll::paymentFor($hid, P4);
        check('HTTP pay → 302; payment net 8,000 by UPI; advance cleared — in one go', $pay['code'] === 302 && $hp !== null
            && money_is((float) $hp['net_paid'], 8000.0) && $hp['method'] === 'upi' && $hp['reference'] === 'UTR123' && money_is(Payroll::openBalance($hid), 0.0)
            && (string) Database::scalar('SELECT status FROM staff_advances WHERE staff_id = :s', ['s' => $hid], '') === 'cleared', 'HTTP ' . $pay['code']);
        $paid = req('GET', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager']);
        check('after paying: success message + "Send slip on WhatsApp" (no mobile → wa.me/?text=)', str_contains($paid['body'], 'Salary paid: ZZ Payroll HTTP Person')
            && str_contains($paid['body'], 'class="pr-slip"') && str_contains($paid['body'], 'https://wa.me/?text=Salary%20slip'));
        $again = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], $payForm);
        check('HTTP pay for the same month again → page says already paid, still one row', $again['code'] === 200 && str_contains($again['body'], 'already paid')
            && (int) Database::scalar('SELECT COUNT(*) FROM staff_salary_payments WHERE staff_id = :s', ['s' => $hid], 0) === 1, 'HTTP ' . $again['code']);
        $bad = req('POST', '/admin/payroll.php?m=' . P5, $jar['pr-test-manager'], ['shg_csrf' => 'deadbeef', 'm' => P5] + $payForm);
        check('a POST with a wrong CSRF token is refused and writes nothing', $bad['code'] === 200 && str_contains($bad['body'], 'Session expired') && Payroll::paymentFor($hid, P5) === null);
        $fix = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], ['action' => 'correct_pay', 'payment_id' => (int) ($hp['id'] ?? 0),
            'bonus' => '250', 'net' => '8250'] + $payForm);
        $hp2 = Payroll::paymentFor($hid, P4);
        check('HTTP correct payment → 302; net 8,250, same row, corrected by the manager', $fix['code'] === 302 && $hp2 !== null
            && (int) $hp2['id'] === (int) ($hp['id'] ?? -1) && money_is((float) $hp2['net_paid'], 8250.0) && (int) $hp2['updated_by'] === $mgrId, 'HTTP ' . $fix['code']);
        $off = req('POST', '/admin/payroll.php?m=' . P4, $jar['pr-test-manager'], ['shg_csrf' => $tok, 'action' => 'deactivate', 'staff_id' => $hid, 'm' => P4]);
        check('HTTP deactivate → 302, inactive, history kept', $off['code'] === 302 && (int) (Payroll::staff($hid)['is_active'] ?? 1) === 0 && Payroll::paymentFor($hid, P4) !== null);
        $audit = Database::fetch("SELECT actor_id, actor_role FROM audit_logs WHERE action = 'payroll.pay' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['e' => (string) $hid]);
        check('the HTTP payment is audited with the manager as actor', $audit !== null && (int) $audit['actor_id'] === $mgrId && $audit['actor_role'] === 'manager', json_encode($audit));
    }
} catch (Throwable $e) {
    check('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup($staffIds);
    $left = (int) Database::scalar("SELECT COUNT(*) FROM staff_members WHERE full_name LIKE '%ZZ Payroll%'", [], 0)
          + (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE 'pr-test-%'", [], 0)
          + (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE phone = :p', ['p' => DPHONE], 0);
    check('cleanup left no test rows behind', $left === 0, $left . ' left');
}

echo "\n  $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n";
exit($FAIL === 0 ? 0 : 1);
