<?php
/**
 * =====================================================================
 *  wa-keepalive-test.php — 14 Nov 2026.
 *
 *  1. WaKeepAlive: the office numbers get ONE free reminder while their
 *     24 h WhatsApp window is open and about to close; never to a closed
 *     window, never twice for one window, never in quiet hours.
 *  2. WaSend::freshMedia: a queued office-report chart link is signed
 *     again when it is sent, so Meta can fetch it (no more 403 / 131053).
 *
 *  Local test database. Nothing is sent: the sender is a stub. Settings
 *  and kv rows it touches are put back.
 *
 *    php tests/wa-keepalive-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
chdir(dirname(__DIR__));
require 'includes/bootstrap.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/wakeepalive.php';
require_once INCLUDE_PATH . '/wasend.php';
require_once INCLUDE_PATH . '/reportchart.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$keys = ['admin_whatsapp', 'ceo_whatsapp', 'office_report_numbers', 'wa_keepalive_on', 'wa_keepalive_before_min', 'wa_keepalive_quiet'];
$old = [];
foreach ($keys as $k) { $old[$k] = Settings::getString($k, "\0none"); }
$A = '919811100011'; $C = '919811100012';
$kv = static function (string $scope, string $intl, ?int $v): void {
    Database::run('DELETE FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => $scope, 'k' => $intl]);
    if ($v !== null) {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, 'test')", ['s' => $scope, 'k' => $intl, 'v' => (string) $v]);
    }
};
$sent = [];
WaKeepAlive::$send = static function (string $intl, string $text, array $ui) use (&$sent): bool { $sent[] = [$intl, $text, $ui]; return true; };
$at = static fn(string $hm): int => (int) strtotime(date('Y-m-d') . ' ' . $hm . ':00');

try {
    Settings::set('admin_whatsapp', $A, 'string', 'whatsapp');
    Settings::set('ceo_whatsapp', $C, 'string', 'whatsapp');
    Settings::set('office_report_numbers', '', 'string', 'whatsapp');
    Settings::set('wa_keepalive_on', '1', 'bool', 'whatsapp');
    Settings::set('wa_keepalive_before_min', '120', 'string', 'whatsapp');
    Settings::set('wa_keepalive_quiet', '22-7', 'string', 'whatsapp');
    $W = WaRoute::WINDOW_SEC;

    echo "\n1. When a reminder is due\n";
    $now = $at('15:00');
    $ok(WaKeepAlive::due(0, $now, 0) !== '', 'a number that never wrote: nothing (no free window)');
    $ok(WaKeepAlive::due($now - $W - 60, $now, 0) !== '', 'a closed window: nothing (a free message cannot reach it)');
    $ok(WaKeepAlive::due($now - $W + 3600, $now, 0) === '', 'one hour left, daytime: due');
    $ok(WaKeepAlive::due($now - $W + 5 * 3600, $now, 0) === 'not yet', 'five hours left, daytime: not yet');
    $last = $now - $W + 3600;
    $ok(WaKeepAlive::due($last, $now, $last) !== '', 'already reminded for this window: not again');
    $ok(WaKeepAlive::due($at('23:30') - $W + 3600, $at('23:30'), 0) === 'quiet hours', '23:30 is quiet: no message at night');
    // closes at 01:00 -> at 21:10 the window still has ~3h50, but every later hour is quiet
    $closesAt1 = $at('21:10') + 3 * 3600 + 50 * 60;
    $ok(WaKeepAlive::due($closesAt1 - $W, $at('21:10'), 0) === '', 'a window closing at night is reminded before 22:00');
    // closes at 09:00 tomorrow -> at 21:10 the window outlives the night, remind in the morning
    $ok(WaKeepAlive::due($at('21:10') + 12 * 3600 - $W, $at('21:10'), 0) === 'not yet', 'a window that lasts past the night waits for the morning');

    echo "\n2. One pass\n";
    WaKeepAlive::$now = $now;
    $kv('wa_inbound', $A, $now - $W + 3600);      // owner: 1 h left
    $kv('wa_inbound', $C, $now - $W - 600);       // CEO: closed
    $kv('wa_keepalive', $A, null);
    $kv('wa_keepalive', $C, null);
    $r = WaKeepAlive::run();
    $ok($r['checked'] === 2 && $r['sent'] === 1 && count($sent) === 1 && $sent[0][0] === $A, 'only the owner (open, closing soon) gets it; the closed CEO window gets nothing');
    $ok(str_contains($sent[0][1], '24 घण्टे') && str_contains($sent[0][1], 'Report') && ($sent[0][2]['buttons'][0]['id'] ?? '') === 'shg:report', 'the message explains it in Nepali and carries a Report button');
    $r2 = WaKeepAlive::run();
    $ok($r2['sent'] === 0 && count($sent) === 1, 'the next five-minute run does not send it again');
    $kv('wa_inbound', $A, $now - $W + 3700);       // the owner replied: a new window
    WaKeepAlive::$now = $now + 100 + 2 * 3600 - 7200 + 100;
    $r3 = WaKeepAlive::run();
    $ok($r3['sent'] === 1 && count($sent) === 2, 'after a reply (new window) the next reminder can go once again');
    Settings::set('wa_keepalive_on', '0', 'bool', 'whatsapp');
    $kv('wa_keepalive', $A, null);
    $r4 = WaKeepAlive::run();
    $ok($r4['sent'] === 0 && count($sent) === 2, 'switch off: nothing is sent');
    Settings::set('wa_keepalive_on', '1', 'bool', 'whatsapp');
    $r5 = WaKeepAlive::run(true);
    $ok($r5['sent'] === 0 && count($sent) === 2, 'dry run: nothing is sent');

    echo "\n3. The report chart link is signed again when sent\n";
    $oldLink = appUrl('report-chart.php?' . http_build_query(['d' => '2026-11-13', 'e' => time() - 3600, 'k' => 'deadbeef']));
    $new = WaSend::freshMedia($oldLink);
    parse_str((string) parse_url($new, PHP_URL_QUERY), $q);
    $ok(($q['d'] ?? '') === '2026-11-13', 'same report date');
    $ok((int) ($q['e'] ?? 0) > time() + 5 * 3600, 'a new expiry about 6 h ahead');
    $ok(ReportChart::verify((string) $q['d'], (int) $q['e'], (string) $q['k']), 'and a signature the chart page accepts');
    $ok(!ReportChart::verify('2026-11-13', time() - 3600, 'deadbeef'), 'the old link itself is still refused (it really had expired)');
    $other = 'https://example.com/a.png';
    $ok(WaSend::freshMedia($other) === $other && WaSend::freshMedia('') === '', 'any other link is left exactly as it was');
    $ok(WaSend::freshMedia('https://x.test/report-chart.php?d=bad') === 'https://x.test/report-chart.php?d=bad', 'a chart link without a valid date is left alone');
} finally {
    WaKeepAlive::$send = null;
    WaKeepAlive::$now = null;
    foreach ([$A, $C] as $n) { $kv('wa_inbound', $n, null); $kv('wa_keepalive', $n, null); }
    foreach ($old as $k => $v) {
        if ($v === "\0none") {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, $v, $k === 'wa_keepalive_on' ? 'bool' : 'string', 'whatsapp');
        }
    }
}

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
