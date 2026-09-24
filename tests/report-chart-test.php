<?php
/**
 * =====================================================================
 *  report-chart-test.php — the office's WhatsApp report chart
 *  (includes/reportchart.php, /report-chart.php, 23 Sep 2026).
 *
 *  Pins: today's figures equal a direct SQL count of the register; the
 *  signed link opens only for its date and time; the image is a real PNG;
 *  and ONLY an office number gets the chart — a customer asking "report"
 *  gets nothing from this path.
 *
 *      php tests/report-chart-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/reportchart.php';
require_once INCLUDE_PATH . '/wafaq.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 240) : '') . "\n"; }
}

echo "\n=== Office report chart ===\n\n";

const RC_BOSS = '9100006604';
const RC_CUST = '9100006605';
$PINNED = ['wa_faq_on', 'wa_report_chart_on'];
$prior  = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
register_shutdown_function(static function () use ($PINNED, $prior): void {
    foreach (["DELETE FROM admins WHERE username = 'zz-report-boss'", "DELETE FROM kv_store WHERE kkey LIKE '%910000660%'",
              "DELETE FROM ai_agent_calls WHERE phone LIKE '%910000660%'"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    foreach ($PINNED as $k) {
        try {
            if ($prior[$k] === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => (string) $prior[$k]['svalue']], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
});
Database::run("DELETE FROM admins WHERE username = 'zz-report-boss'");
Database::insert('admins', ['username' => 'zz-report-boss', 'password_hash' => password_hash('Zz@123456', PASSWORD_BCRYPT),
    'full_name' => 'ZZ Report Boss', 'role' => 'superadmin', 'phone' => RC_BOSS, 'is_active' => 1, 'must_change_pw' => 0]);

/* ---- figures = the register ------------------------------------------ */
$today = todayISO();
$d = ReportChart::data($today);
$sql = Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) a FROM bookings
                         WHERE DATE(created_at) = :d AND status IN ('confirmed','completed')", ['d' => $today]);
check('7 days, ending today', count($d['days']) === 7 && $d['days'][6]['date'] === $today);
check("today's tickets = the register", $d['today']['tickets'] === (int) $sql['n'], $d['today']['tickets'] . ' vs ' . $sql['n']);
check("today's money = the register", abs($d['today']['revenue'] - (float) $sql['a']) < 0.01);
check('the week adds up its days', $d['week']['tickets'] === array_sum(array_column($d['days'], 'tickets')));

/* ---- link + picture --------------------------------------------------- */
$url = ReportChart::url($today);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check('the minted link verifies', ReportChart::verify((string) $q['d'], (int) $q['e'], (string) $q['k']));
check('another date is refused', !ReportChart::verify(date('Y-m-d', strtotime('-1 day')), (int) $q['e'], (string) $q['k']));
check('an expired link is refused', !ReportChart::verify($today, time() - 1, (string) $q['k']));
$png = ReportChart::png($today);
$sz  = getimagesizefromstring($png);
check('a real 900 x 560 PNG', str_starts_with($png, "\x89PNG") && is_array($sz) && $sz[0] === 900 && $sz[1] === 560, json_encode($sz));

/* ---- office only ------------------------------------------------------ */
Settings::set('wa_faq_on', true, 'bool', 'ai');
Settings::set('wa_report_chart_on', true, 'bool', 'ai');
Settings::flush();
$r = WaFaq::answer('+91' . RC_BOSS, 'aaja ko report dinus');
check('an office number gets the report + chart', $r !== null && $r['intent'] === 'report'
    && str_contains((string) $r['media'], 'report-chart.php') && str_contains($r['text'], (string) $d['today']['tickets']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
$c = WaFaq::answer('+91' . RC_CUST, 'aaja ko report dinus');
check('a customer asking for "report" never gets the chart', $c === null || ($c['intent'] ?? '') !== 'report', json_encode($c, JSON_UNESCAPED_UNICODE));
Settings::set('wa_report_chart_on', false, 'bool', 'ai');
Settings::flush();
$off = WaFaq::answer('+91' . RC_BOSS, 'aaja ko report dinus');
check('switched off → no chart', $off === null || ($off['intent'] ?? '') !== 'report');

$runAll = @file_get_contents(dirname(__DIR__) . '/tests/run-all.php') ?: '';
check('this suite is registered in the battery', str_contains($runAll, 'report-chart-test.php'));

echo "\nreport-chart: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
