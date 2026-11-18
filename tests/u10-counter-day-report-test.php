<?php
/**
 * 19 Nov 2026 (WA-6) — OfficeReport::counterDay() / sendCounterDay(), DRY
 * (no WhatsApp, no e-mail leaves: both go through test seams):
 *   the first line is the grand total (<= 600 chars), the whole text <= 4096,
 *   Nepali labels, NPR desk in रू, no passenger name / phone, day-close status
 *   (✅ बुझायो / ⏳ बाँकी), numbers() + counter_day_report_numbers, the 21:30
 *   slot, a wa.me link counted as FAILED (the old bug), the e-mail fallback
 *   only when every WhatsApp failed and only with the switch on, and the
 *   cron's --day=… --dry prints without sending.
 *
 *   php -c <ini> tests/u10-counter-day-report-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/u10-seed-lib.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/officereport.php';
require_once INCLUDE_PATH . '/countershift.php';

echo "\n=== u10 · Counter-wise day report to the office (dry) ===\n\n";
try {
    $s  = u10_seed();
    $st = $s['state'];
    foreach (['admin_whatsapp', 'ceo_whatsapp', 'office_report_numbers', 'counter_day_report_numbers', 'office_report_email_fallback', 'chalani_mail_to'] as $k) {
        u10_keep_setting($st, $k);
    }
    u10_save_state($st);
    /* live-like: the two office numbers */
    Settings::set('admin_whatsapp', '919104801507', 'string', 'notify', false);
    Settings::set('ceo_whatsapp', '919726401507', 'string', 'notify', false);
    Settings::set('office_report_numbers', '', 'string', 'reports', false);
    Settings::set('counter_day_report_numbers', '9104801507, 9000010299', 'string', 'reports', false);
    Settings::set('chalani_mail_to', 'u10-office@example.test', 'string', 'reports', false);
    Settings::flush();

    check('numbers() = the two office numbers', count(OfficeReport::numbers()) === 2);
    check('counter_day_report_numbers adds one (a duplicate of 9104801507 is not repeated)', count(OfficeReport::counterDayNumbers()) === 3, json_encode(OfficeReport::counterDayNumbers()));
    check("dueSlot('21:30') fires once inside the grace", OfficeReport::dueSlot('21:30', '2026-11-19 21:40', '') === '2026-11-19 21:30'
        && OfficeReport::dueSlot('21:30', '2026-11-19 21:40', '2026-11-19 21:30') === null && OfficeReport::dueSlot('21:30', '2026-11-19 21:20', '') === null);

    $rep  = OfficeReport::counterDay(U10_FD);
    $text = $rep['text'];
    $lines = explode("\n", $text);
    echo "  --- text ---\n  " . str_replace("\n", "\n  ", $text) . "\n  ------------\n";
    check('line 1 = the grand total (टिकट · यात्रु · नगद · अनलाइन · नेट · बाँकी) and <= 600 chars', str_contains($lines[0], '6 टिकट') && str_contains($lines[0], '7 यात्रु')
        && str_contains($lines[0], 'नगद') && str_contains($lines[0], 'अनलाइन') && str_contains($lines[0], 'नेट') && mb_strlen($lines[0]) <= 600, (string) mb_strlen($lines[0]));
    check('whole text <= 4096', mb_strlen($text) <= 4096, (string) mb_strlen($text));
    check('a line per desk / seller / online', str_contains($text, '(MSA)') && str_contains($text, '(NPJ)') && str_contains($text, 'ZZTEST u10 Agent') && str_contains($text, 'अनलाइन · Online'));
    check('NPR desk prints रू', str_contains($text, 'रू'));
    check('no passenger name, no 10-digit phone', !preg_match('/ZZTEST u10 (M1|M2|P1|N1|G1|O1)/', $text) && preg_match('/(?<!\d)\d{10}(?!\d)/', $text) !== 1);
    check('desks with cash and no close are listed as ⏳ बाँकी', str_contains($text, '⏳ हिसाब नबुझाएका') && str_contains($text, '⏳ बाँकी'));
    CounterShift::closeDay($s['boss'], 'MSA', U10_FD, (float) $s['b']['M1']['total_amount'], 0.0, null, '', 'ZZTEST u10 Boss', true);
    $text2 = OfficeReport::counterDay(U10_FD)['text'];
    check('after the day close MSA reads ✅ बुझायो', preg_match('/\(MSA\)[^\n]*✅ बुझायो/u', $text2) === 1);
    check('a link to the counter book of that day', str_contains($text2, 'admin/counters.php?from=' . U10_FD . '&to=' . U10_FD));

    /* dry send: nothing goes */
    $calls = 0;
    OfficeReport::$transport = static function () use (&$calls) { $calls++; return true; };
    $dry = OfficeReport::sendCounterDay(U10_FD . ' 21:30', true);
    check('dry: 3 numbers, nothing sent, the text returned', $dry['numbers'] === 3 && $calls === 0 && str_contains((string) ($dry['text'] ?? ''), 'टिकट'));

    /* the old bug: a wa.me link is NOT a delivery */
    $mails = [];
    OfficeReport::$transport = static fn() => 'https://wa.me/919104801507?text=x';
    OfficeReport::$mailer = static function (string $to, string $sub) use (&$mails): bool { $mails[] = $to; return true; };
    Settings::set('office_report_email_fallback', '1', 'bool', 'reports', false); Settings::flush();
    $res = OfficeReport::sendCounterDay(U10_FD . ' 21:30', false, U10_FD);
    check('wa.me links count as failed (3 failed, 0 sent)', $res['sent'] === 0 && $res['failed'] === 3, json_encode($res));
    check('every WhatsApp failed + switch on → exactly one e-mail per office address', ($res['email'] ?? 0) === 1 && $mails === ['u10-office@example.test'], json_encode($mails));
    $mails = [];
    $off = OfficeReport::send(date('Y-m-d') . ' 07:00');
    check('the office report too: wa.me = failed, e-mail fallback', $off['sent'] === 0 && $off['failed'] === 2 && ($off['email'] ?? 0) === 1, json_encode($off));
    Settings::set('office_report_email_fallback', '0', 'bool', 'reports', false); Settings::flush();
    $mails = [];
    $res = OfficeReport::sendCounterDay(U10_FD . ' 21:30', false, U10_FD);
    check('switch off → no e-mail', ($res['email'] ?? 0) === 0 && $mails === []);
    Settings::set('office_report_email_fallback', '1', 'bool', 'reports', false); Settings::flush();
    OfficeReport::$transport = static fn() => true;
    $mails = [];
    $res = OfficeReport::sendCounterDay(U10_FD . ' 21:30', false, U10_FD);
    check('a delivered WhatsApp → sent, no e-mail', $res['sent'] === 3 && ($res['email'] ?? 0) === 0 && $mails === []);

    /* spec gap WA-6: shg_daily_collection (purpose counter_day) rides along
       as meta_tpl - only when Meta has approved it; never invented */
    require_once INCLUDE_PATH . '/watplmeta.php';
    $tpl = OfficeReport::counterDayTemplate(U10_FD, OfficeReport::counterDay(U10_FD));
    $ready = WaTplMeta::ready('shg_daily_collection');
    check('counterDayTemplate: null unless shg_daily_collection is approved', $ready ? $tpl !== null : $tpl === null, $ready ? 'approved here' : 'not approved here');
    if ($tpl !== null) {
        check('template vars: 7, tickets 6, passengers 7', (string) $tpl['name'] === 'shg_daily_collection' && count($tpl['vars']) === 7
            && (string) $tpl['vars'][2] === '6' && (string) $tpl['vars'][3] === '7', json_encode($tpl['vars']));
    }
    $gotTpl = 'unset';
    OfficeReport::$transport = static function (string $to, string $text, ?string $media, string $purpose, ?array $metaTpl = null) use (&$gotTpl): bool {
        $gotTpl = $metaTpl === null ? null : (string) ($metaTpl['name'] ?? '');
        return $purpose === 'counter_day';
    };
    $res = OfficeReport::sendCounterDay(U10_FD . ' 21:30', false, U10_FD);
    check('sendCounterDay passes the template (or null) to every send, purpose counter_day', $res['sent'] === 3
        && $gotTpl === ($tpl !== null ? 'shg_daily_collection' : null) && ($res['template'] ?? 'x') === ($tpl !== null ? 'shg_daily_collection' : ''), json_encode([$gotTpl, $res['template'] ?? null]));
    OfficeReport::$transport = null; OfficeReport::$mailer = null;

    /* the cron prints with --day --dry and sends nothing */
    $php = PHP_BINARY;
    $ini = (string) (php_ini_loaded_file() ?: '');
    $cmd = escapeshellarg($php) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/office-report.php') . ' --day=' . U10_FD . ' --dry 2>&1';
    $before = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE channel = 'whatsapp'", [], 0);
    $outp = (string) shell_exec($cmd);
    $after  = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE channel = 'whatsapp'", [], 0);
    check('cron --day=… --dry prints the report', str_contains($outp, 'काउन्टर हिसाब') && str_contains($outp, '"dry":true'), substr($outp, -200));
    check('cron --dry wrote no WhatsApp log row', $after === $before);
} catch (Throwable $e) {
    $GLOBALS['FAIL']++;
    echo '  ERROR  ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
OfficeReport::$transport = null; OfficeReport::$mailer = null;
u10_cleanup();
echo "\n  {$GLOBALS['PASS']} passed, {$GLOBALS['FAIL']} failed\n";
exit($GLOBALS['FAIL'] === 0 ? 0 : 1);
