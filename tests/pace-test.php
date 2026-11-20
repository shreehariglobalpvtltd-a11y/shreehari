<?php
/**
 * pace-test.php — WhereIs::pace (20 Nov 2026): timetable vs live, pure maths, no DB.
 *    php -c .claude/php-dev.ini tests/pace-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/whereis.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $m) use (&$pass, &$fail): void { $c ? $pass++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . PHP_EOL; };

$t0 = 1_800_000_000;
// 0 km at t0, 100 km at t0+2h, halt 30 min, 300 km at t0+6h30
$tl = [
    ['ts' => $t0, 'km' => 0, 'name' => 'A'],
    ['ts' => $t0 + 7200, 'km' => 100, 'name' => 'B'],
    ['ts' => $t0 + 9000, 'km' => 100, 'name' => 'B halt'],
    ['ts' => $t0 + 23400, 'km' => 300, 'name' => 'C'],
    ['ts' => null, 'km' => 50, 'name' => 'no clock'],
];
$st = fn (?int $km, string $src = 'gps') => ['timeline' => $tl, 'totalKm' => 300, 'progress' => ['km' => $km, 'source' => $src]];

$p = WhereIs::pace($st(50), $t0 + 3600);
$ok($p['state'] === 'run' && $p['planKm'] === 50 && $p['lateMin'] === 0, 'on time at 1 h (50 km planned, 50 live)');
$p = WhereIs::pace($st(25), $t0 + 3600);
$ok($p['lateMin'] === 30, 'behind: 25 km at 1 h = 30 min late (got ' . $p['lateMin'] . ')');
$p = WhereIs::pace($st(75), $t0 + 3600);
$ok($p['lateMin'] === -30, 'ahead: 75 km at 1 h = 30 min early');
$p = WhereIs::pace($st(100), $t0 + 8000);
$ok($p['planKm'] === 100 && $p['planName'] === 'B', 'during the halt the plan stays at B');
$ok($p['lateMin'] === 0, 'at the halt inside its window = on time (got ' . $p['lateMin'] . ')');
$p = WhereIs::pace($st(100), $t0 + 9000 + 1200);
$ok($p['lateMin'] === 20, 'still at the halt 20 min after it should leave = 20 late (got ' . $p['lateMin'] . ')');
$p = WhereIs::pace($st(200), $t0 + 9000 + 7200);
$ok($p['planKm'] === 200 && $p['lateMin'] === 0, 'after the halt: 200 km at 4 h 30 is on time');
$p = WhereIs::pace($st(null, 'timetable'), $t0 + 3600);
$ok($p['livePct'] === null && $p['lateMin'] === null && $p['planKm'] === 50, 'no GPS: plan only, no late guess');
$p = WhereIs::pace($st(0), $t0 - 600);
$ok($p['state'] === 'before', 'before the first row = before');
$ok(WhereIs::pace(['timeline' => [$tl[0]], 'totalKm' => 300], $t0) === null, 'one timed row = null');
echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
