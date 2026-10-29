<?php
/**
 * Round-trip bus rotation + corridor distance (29 Oct 2026 audit).
 *
 *   php -c .claude/php-dev.ini tests/fleet-roundtrip-test.php
 *
 * Pins: with 3 buses in round-trip mode, outbound runs on cycle days 1-3 and
 * the same buses come back on days 4-6; a direction never has a bus on the
 * other direction's days; the daily mode still gives one bus per date; no
 * rotation leaves the route's own bus. Works on the in-memory settings cache
 * only — nothing is written. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/sitefacts.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

/** Put a rotation into the per-request settings cache (no DB write). */
function useRotation(?array $rot): void {
    Settings::get('__warm__');
    $c = new ReflectionProperty(Settings::class, 'cache');
    $cache = $c->getValue() ?? [];
    if ($rot === null) {
        unset($cache['bus_rotation']);
    } else {
        $cache['bus_rotation'] = ['value' => json_encode($rot), 'type' => 'json', 'public' => false];
    }
    $c->setValue(null, $cache);
    (new ReflectionProperty(Settings::class, 'decoded'))->setValue(null, []);
}

$out  = ['to_city' => 'Rupaidiha', 'from_city' => 'Surat'];
$back = ['to_city' => 'Surat', 'from_city' => 'Rupaidiha'];

useRotation(['enabled' => true, 'buses' => [2, 7, 9], 'cycleDays' => 3, 'startDate' => '2026-10-30', 'mode' => 'round_trip']);
$expect = [
    '2026-10-30' => [2, null], '2026-10-31' => [7, null], '2026-11-01' => [9, null],
    '2026-11-02' => [null, 2], '2026-11-03' => [null, 7], '2026-11-04' => [null, 9],
    '2026-11-05' => [2, null], '2026-10-29' => [null, 9],
];
foreach ($expect as $d => [$o, $b]) {
    $po = Fleet::planFor($out, $d);
    $pb = Fleet::planFor($back, $d);
    check("$d outbound " . ($o ?? 'off') . ' / return ' . ($b ?? 'off'),
        ($po['runs'] ? $po['bus'] : null) === $o && ($pb['runs'] ? $pb['bus'] : null) === $b);
}
$sameDay = false;
for ($i = 0; $i < 30; $i++) {
    $d = addDaysISO('2026-10-30', $i);
    $po = Fleet::planFor($out, $d);
    $pb = Fleet::planFor($back, $d);
    if ($po['runs'] && $pb['runs']) { $sameDay = true; }
}
check('never both directions on one day (one bus cannot be at both ends)', !$sameDay);
check('a bus that leaves on day 1 is the one that returns on day 4',
    Fleet::planFor($out, '2026-10-30')['bus'] === Fleet::planFor($back, '2026-11-02')['bus']);

useRotation(['enabled' => true, 'buses' => [2, 7, 9], 'cycleDays' => 3, 'startDate' => '2026-10-30', 'mode' => 'daily']);
$a = Fleet::planFor($out, '2026-10-31');
check('daily mode is unchanged: runs every day with the date\'s bus', $a['runs'] === true && $a['bus'] === 7);

useRotation(null);
$n = Fleet::planFor($out, '2026-10-31');
check('no rotation: runs, route keeps its own bus', $n['runs'] === true && $n['bus'] === null);

$km = SiteFacts::routeKm();
check("corridor distance is a real figure ($km km)", $km >= 100 && $km < 5000);

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
