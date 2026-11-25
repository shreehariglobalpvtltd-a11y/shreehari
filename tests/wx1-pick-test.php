<?php
/* wx1 (25 Nov 2026): SmartBook Nepal -> India pickup chips - a toIndia bus with two real
   boarding rows (Nepalgunj office + Rupaidiha parking) offers both, the chosen one is the
   plan's boarding stop, one row = no chips, switch off = no chips.
   php -c .claude/php-dev.ini tests/wx1-pick-test.php */
define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/smartbook.php';
$ok = 0; $bad = 0;
$chk = function ($name, $cond) use (&$ok, &$bad) { if ($cond) { $ok++; echo "  PASS  $name\n"; } else { $bad++; echo "  FAIL  $name\n"; } };

$dirs = array_column(SmartBook::directions(), null, 'direction');
if (!isset($dirs['toIndia'])) { echo "  SKIP  no Nepal -> India route on this DB\n"; exit(0); }
$rid = 0;
$saved = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => 'sb_np_pickup_choice_on']);
$tmp = [];
try {
    $q1 = SmartBook::quote(['direction' => 'toIndia', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $rid = (int) ($q1['routeId'] ?? 0);
    $n0 = count(Boarding::stopsFor($rid));
    $chk('one boarding row = no chips', $n0 > 1 || ($q1['pickups'] ?? null) === []);

    foreach (Database::fetchAll('SELECT * FROM routes') as $r) {
        if (Boarding::routeDirection($r) !== 'toIndia') { continue; }
        $tmp[] = (int) Database::insert('route_stops', ['route_id' => (int) $r['id'], 'stop_name' => 'WXPICK Nepalgunj Office', 'landmark' => 'Puspalal Chowk',
            'stop_type' => 'boarding', 'stop_time' => '16:30:00', 'sort_order' => 0, 'latitude' => 28.0713177, 'longitude' => 81.6324768, 'is_active' => 1]);
    }
    Boarding::flush();

    $q2 = SmartBook::quote(['direction' => 'toIndia', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $names = array_column((array) ($q2['pickups'] ?? []), 'name');
    $chk('two rows = both offered', in_array('WXPICK Nepalgunj Office', $names, true) && count($names) >= 2);
    $chk('quote names the booked pickup', in_array((string) ($q2['pickup'] ?? ''), $names, true));

    $q3 = SmartBook::quote(['direction' => 'toIndia', 'date' => addDaysISO(todayISO(), 1), 'pickup' => 'WXPICK Nepalgunj Office', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $chk('chosen office = plan boarding', ($q3['pickup'] ?? '') === 'WXPICK Nepalgunj Office' && stripos((string) $q3['boarding'], 'WXPICK') !== false);

    /* 16:00 office pickup on an 18:00 bus is the SAME day (PRE_DEP_MIN 4 h), not the next */
    $tom = addDaysISO(todayISO(), 1);
    $at  = Boarding::stopAt((int) ($q3['routeId'] ?? $rid), $tom, 'WXPICK Nepalgunj Office');
    $dep = Database::scalar('SELECT dep_time FROM routes WHERE id = :r', ['r' => (int) ($q3['routeId'] ?? $rid)]);
    if ($dep !== null && substr((string) $dep, 0, 5) > '16:30' && substr((string) $dep, 0, 5) <= '20:00') {
        $chk('office pickup 1.5 h before the bus = same day', $at !== null && date('Y-m-d H:i', $at) === $tom . ' 16:30');
    } else {
        $chk('office pickup time resolves', $at !== null);
    }

    $q4 = SmartBook::quote(['direction' => 'toIndia', 'pickup' => 'Kathmandu fake stop', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $chk('unknown pickup text = a real row, never free text', in_array((string) ($q4['pickup'] ?? ''), $names, true));

    $q5 = SmartBook::quote(['direction' => 'toNepal', 'pickup' => 'WXPICK Nepalgunj Office', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $chk('India -> Nepal unchanged (no chips)', ($q5['pickups'] ?? null) === [] && stripos((string) $q5['boardingName'], 'WXPICK') === false);

    Settings::set('sb_np_pickup_choice_on', '0', 'bool', 'booking', false); Settings::flush();
    $q6 = SmartBook::quote(['direction' => 'toIndia', 'pickup' => 'WXPICK Nepalgunj Office', 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
    $chk('switch off = no chips', ($q6['pickups'] ?? null) === []);
} catch (Throwable $e) {
    $chk('no exception: ' . $e->getMessage(), false);
} finally {
    foreach ($tmp as $t) { Database::run('DELETE FROM route_stops WHERE id = :i', ['i' => $t]); }
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'sb_np_pickup_choice_on']);
    if ($saved) { Database::insert('settings', $saved); }
    Settings::flush();
}
echo "\n  wx1-pick: $ok passed, $bad failed\n";
exit($bad > 0 ? 1 : 0);
