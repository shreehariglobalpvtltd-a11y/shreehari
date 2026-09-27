<?php
/**
 * =====================================================================
 *  office-report-test.php — the office's WhatsApp report (27 Sep 2026).
 *
 *      php tests/office-report-test.php
 *
 *    1. numbers(): admin + CEO + extras, one per person however typed
 *    2. dueSlot(): due from its minute for an hour, once; bad times ignored
 *    3. build(): the figures equal the register (ReportChart / payments),
 *       Nepali first, and no passenger name or 10-digit number leaks
 *    4. send(): one journal row per office number with purpose
 *       office_report; --dry sends nothing
 *    5. the cron: off = nothing; on + due slot = sent once, then "no slot"
 *
 *  No WhatsApp leaves the machine: the driver is forced to click_to_chat.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/officereport.php';

$pass = 0; $fail = 0;
function orp_check(string $label, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $fail++; echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$KEYS = ['admin_whatsapp', 'ceo_whatsapp', 'office_report_numbers', 'office_report_on', 'office_report_times',
         'office_report_last', 'whatsapp_driver', 'wa_report_chart_on'];
$saved = [];
foreach ($KEYS as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
$logFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);

try {
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify');
    Settings::set('admin_whatsapp', '919104801507', 'string', 'reports');
    Settings::set('ceo_whatsapp', '919726401507', 'string', 'company');

    echo "\n-- 1. numbers --\n";
    Settings::set('office_report_numbers', '+91 97264 01507, 9104801507; 9779800000001', 'string', 'reports');
    $n = OfficeReport::numbers();
    orp_check('admin, CEO and one new extra — duplicates in other forms dropped', count($n) === 3, implode(',', $n));
    orp_check('the admin number comes first', ($n[0] ?? '') === '919104801507');
    Settings::set('office_report_numbers', '', 'string', 'reports');
    orp_check('blank extras = the two office numbers', count(OfficeReport::numbers()) === 2);

    echo "\n-- 2. dueSlot --\n";
    $T = '07:00,13:00,20:00';
    orp_check('07:05 is the 07:00 slot', OfficeReport::dueSlot($T, '2026-09-27 07:05', '') === '2026-09-27 07:00');
    orp_check('…once: sent already = null', OfficeReport::dueSlot($T, '2026-09-27 07:40', '2026-09-27 07:00') === null);
    orp_check('06:59 = nothing due', OfficeReport::dueSlot($T, '2026-09-27 06:59', '2026-09-26 20:00') === null);
    orp_check('09:30 = 07:00 is too late (grace 60 min)', OfficeReport::dueSlot($T, '2026-09-27 09:30', '') === null);
    orp_check('13:00 sharp is due', OfficeReport::dueSlot($T, '2026-09-27 13:00', '2026-09-27 07:00') === '2026-09-27 13:00');
    orp_check('junk times are ignored', OfficeReport::dueSlot('7am, 25:00, x', '2026-09-27 07:05', '') === null);
    orp_check('single-digit hour works', OfficeReport::dueSlot('7:30', '2026-09-27 07:31', '') === '2026-09-27 07:30');

    echo "\n-- 3. build --\n";
    $day = todayISO();
    $schedBefore = (int) Database::scalar('SELECT COUNT(*) FROM schedules', [], 0);
    $rep = OfficeReport::build($day, $day . ' 13:00');
    $rc  = ReportChart::data($day, 7);
    $f   = $rep['figures'];
    orp_check('tickets / seats / revenue equal ReportChart', $f['tickets'] === (int) $rc['today']['tickets']
        && $f['seats'] === (int) $rc['today']['seats'] && abs($f['revenue'] - (float) $rc['today']['revenue']) < 0.01);
    $ver = (float) Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'verified'
            AND COALESCE(verified_at, created_at) >= :s AND COALESCE(verified_at, created_at) < :e",
        ['s' => $day . ' 00:00:00', 'e' => addDaysISO($day, 1) . ' 00:00:00'], 0);
    orp_check('verified money equals the payments register', abs($f['verified']['inr'] - $ver) < 0.01, (string) $ver);
    orp_check('Nepali first, English last', str_starts_with($rep['text'], '🚌 S Hari Global — अफिस रिपोर्ट')
        && str_contains($rep['text'], 'आजका बस') && str_contains($rep['text'], 'भोलिका बस')
        && str_starts_with((string) substr($rep['text'], (int) strrpos($rep['text'], "\n") + 1), 'EN: today'));
    orp_check('no 10-digit number in the text', preg_match('/(?<!\d)\d{10,}(?!\d)/', $rep['text']) !== 1);
    $names = array_column(Database::fetchAll("SELECT full_name FROM booking_passengers WHERE full_name <> '' ORDER BY id DESC LIMIT 20"), 'full_name');
    $leak = array_filter($names, static fn($nm) => mb_strlen((string) $nm) >= 4 && str_contains($rep['text'], (string) $nm));
    orp_check('no passenger name in the text', $leak === [], implode(',', $leak));
    orp_check('every departure line has sold/cap and free', array_reduce($f['today'], static fn($ok, $b) => $ok
        && $b['free'] === max(0, $b['cap'] - $b['sold']), true));
    orp_check('reading departures created no schedule row', (int) Database::scalar('SELECT COUNT(*) FROM schedules', [], 0) === $schedBefore);
    Settings::set('wa_report_chart_on', '1', 'bool', 'reports');
    orp_check('chart link attached when wa_report_chart_on', str_contains((string) OfficeReport::build($day)['media'], 'report-chart.php'));
    Settings::set('wa_report_chart_on', '0', 'bool', 'reports');
    orp_check('no chart when it is off', OfficeReport::build($day)['media'] === null);

    echo "\n-- 4. send --\n";
    $dry = OfficeReport::send($day . ' 13:00', true);
    $rows = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom], 0);
    orp_check('dry run sends nothing and returns the text', $rows === 0 && str_contains((string) ($dry['text'] ?? ''), 'अफिस रिपोर्ट'));
    $res = OfficeReport::send($day . ' 13:00');
    $rows = Database::fetchAll("SELECT to_number FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom]);
    orp_check('one journal row per office number', count($rows) === 2 && $res['numbers'] === 2, json_encode($res));
    orp_check('…to the admin and the CEO', count(array_unique(array_map(static fn($r) => normalisePhone((string) $r['to_number']), $rows))) === 2);

    echo "\n-- 5. the cron --\n";
    $php  = escapeshellarg(PHP_BINARY);
    $cron = escapeshellarg(dirname(__DIR__) . '/cron/office-report.php');
    Settings::set('office_report_on', '0', 'bool', 'reports');
    $out = (string) shell_exec("$php $cron 2>&1");
    orp_check('switch off = skipped', str_contains($out, 'office_report_on is off'), trim($out));
    Settings::set('office_report_on', '1', 'bool', 'reports');
    Settings::set('office_report_times', date('H:i'), 'string', 'reports');
    Settings::set('office_report_last', '', 'string', 'reports');
    $before = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom], 0);
    shell_exec("$php $cron 2>&1");
    $mid = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom], 0);
    $out2 = (string) shell_exec("$php $cron 2>&1");
    $after = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom], 0);
    orp_check('due slot = sent to both numbers', $mid - $before === 2, ($mid - $before) . ' rows');
    orp_check('…and not again in the same slot', $after === $mid && str_contains($out2, 'no slot due'), trim($out2));
    orp_check('the slot is recorded', Settings::getString('office_report_last', '') === date('Y-m-d') . ' ' . date('H:i')
        || str_starts_with(Settings::getString('office_report_last', ''), date('Y-m-d')));
} catch (Throwable $e) {
    orp_check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, (string) $row['svalue'], (string) $row['stype'], (string) $row['sgroup']);
        }
    }
    Database::run("DELETE FROM message_logs WHERE id > :i AND purpose = 'office_report'", ['i' => $logFrom]);
}

echo "\n  office-report: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
