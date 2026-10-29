<?php
/**
 * Round-trip bus rotation (the owner's "3 Bus Round Trip Routine" chart,
 * 29 Oct 2026) + corridor distance.
 *
 *   php -c .claude/php-dev.ini tests/fleet-roundtrip-test.php
 *
 * The chart: Bus 01 leaves Gujarat on day 1 and the Nepal side on day 4,
 * Bus 02 on days 3 and 6, Bus 03 on days 5 and 8 (= day 2) — a 6-day
 * turnaround, a departure every 2 days. Bus 01 and 02 run the Surat route,
 * Bus 03 its own (Rajkot) route. Pins: every chart day, one bus never at both
 * ends, a route runs only on its own bus's days, "not set yet" is not sold,
 * daily mode unchanged. Works on the in-memory settings cache only —
 * nothing is written. CLI only.
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
function runs(array $route, string $date): ?int {
    $p = Fleet::planFor($route, $date);
    return $p['runs'] ? $p['bus'] : null;
}

$surOut = ['id' => 2,  'from_city' => 'Surat',     'to_city' => 'Rupaidiha'];
$surBack = ['id' => 7, 'from_city' => 'Rupaidiha', 'to_city' => 'Surat'];
$rjtOut = ['id' => 91, 'from_city' => 'Rajkot',    'to_city' => 'Rupaidiha'];
$rjtBack = ['id' => 92, 'from_city' => 'Rupaidiha', 'to_city' => 'Rajkot'];

$chart = [
    'enabled' => true, 'buses' => [2, 7, 9], 'cycleDays' => 3, 'startDate' => '2026-10-30', 'mode' => 'round_trip',
    'stagger' => 2, 'backAfter' => 3,
    'routes'  => [2 => ['out' => 2, 'back' => 7], 7 => ['out' => 2, 'back' => 7], 9 => ['out' => 91, 'back' => 92]],
];
useRotation($chart);

// date => [Surat out, Surat back, Rajkot out, Rajkot back]  (chart day in the label)
$expect = [
    '2026-10-30' => ['day 1  Bus 01 leaves Surat',          [2, null, null, null]],
    '2026-10-31' => ['day 2  Bus 03 leaves Nepal (Rajkot)', [null, null, null, 9]],
    '2026-11-01' => ['day 3  Bus 02 leaves Surat',          [7, null, null, null]],
    '2026-11-02' => ['day 4  Bus 01 leaves Nepal (Surat)',  [null, 2, null, null]],
    '2026-11-03' => ['day 5  Bus 03 leaves Rajkot',         [null, null, 9, null]],
    '2026-11-04' => ['day 6  Bus 02 leaves Nepal (Surat)',  [null, 7, null, null]],
    '2026-11-05' => ['day 7  Bus 01 leaves Surat again',    [2, null, null, null]],
    '2026-10-29' => ['day 0  Bus 02 leaves Nepal (Surat)',  [null, 7, null, null]],
];
foreach ($expect as $d => [$label, $want]) {
    $got = [runs($surOut, $d), runs($surBack, $d), runs($rjtOut, $d), runs($rjtBack, $d)];
    check("$d $label", $got === $want);
}

$clash = false;
for ($i = 0; $i < 60; $i++) {
    $d = addDaysISO('2026-10-30', $i);
    $buses = array_filter([runs($surOut, $d), runs($surBack, $d), runs($rjtOut, $d), runs($rjtBack, $d)]);
    if (count($buses) !== count(array_unique($buses))) { $clash = true; }
}
check('60 days: no bus is ever at both ends on one day', !$clash);
check('Bus 01 leaves Nepal 3 days after it leaves Gujarat',
    runs($surOut, '2026-10-30') === 2 && runs($surBack, '2026-11-02') === 2);
check('a departure every 2 days from Gujarat (any route)', (static function () use ($surOut, $rjtOut): bool {
    for ($i = 0; $i < 12; $i++) {
        $d = addDaysISO('2026-10-30', $i);
        $any = runs($surOut, $d) !== null || runs($rjtOut, $d) !== null;
        if ($any !== ($i % 2 === 0)) { return false; }
    }
    return true;
})());

$notSet = $chart;
$notSet['routes'][9] = ['out' => -1, 'back' => -1];
useRotation($notSet);
check('Bus 03 route "not set yet": its day sells nothing on the Surat route',
    runs($surOut, '2026-11-03') === null && runs($surBack, '2026-10-31') === null);

useRotation(['enabled' => true, 'buses' => [2, 7, 9], 'cycleDays' => 3, 'startDate' => '2026-10-30', 'mode' => 'daily']);
$a = Fleet::planFor($surOut, '2026-10-31');
check('daily mode is unchanged: runs every day with the date\'s bus', $a['runs'] === true && $a['bus'] === 7);

useRotation(null);
$n = Fleet::planFor($surOut, '2026-10-31');
check('no rotation: runs, route keeps its own bus', $n['runs'] === true && $n['bus'] === null);

$km = SiteFacts::routeKm();
check("corridor distance is a real figure ($km km)", $km >= 100 && $km < 5000);

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
