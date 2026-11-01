<?php
/**
 * upgrade-2026-10-31-routine-live.php — the owner's 3-bus poster on the LIVE
 * round-trip rotation (Admin → Trips → Bus rotation, mode round_trip).
 *
 *   rotation slot 1  BUS 01  Surat – Vadodara – Ahmedabad – Mehsana – Palanpur … Rupaidiha   r2 / r7
 *   rotation slot 2  BUS 02  Surat – Vadodara – Ahmedabad – Himatnagar … Rupaidiha          b02-up / b02-dn (new)
 *   rotation slot 3  BUS 03  Rajkot – Ahmedabad – Godhra – Dahod (Indore) … Rupaidiha       b03-up / b03-dn (new)
 *
 * The rotation itself (Day 1, 2-day stagger, return 3 days later) is NOT
 * touched — passengers are already booked on it. This only gives each slot
 * its own routes and stops, so BUS 03's days stop being blocked.
 *
 *   1. r2 / r7: + Mehsana, + Palanpur; Rajkot moves to BUS 03.
 *   2. creates BUS 02 / BUS 03 routes when missing (copies of r2 / r7 stops).
 *   3. each new route gets its slot's coach; rotation routes → slot 2 / slot 3.
 *   4. a trip that ALREADY carries bookings on r2 / r7 for a slot-2/3 day keeps
 *      them there: the new route's row for that date is created switched OFF,
 *      so the same coach is never sold twice.
 *   5. new towns in the booking picker.
 * Idempotent. New stops have no clock time (set them in Admin → Routes).
 *
 *   php database/upgrade-2026-10-31-routine-live.php      then
 *   php tools/apply-rotation.php --from=<today> --go
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

$route = static fn(string $code): ?array =>
    Database::fetch('SELECT * FROM routes WHERE route_code = :c LIMIT 1', ['c' => $code]);
$stops = static fn(int $id, string $type): array => Database::fetchAll(
    'SELECT * FROM route_stops WHERE route_id = :r AND stop_type = :t ORDER BY sort_order, id', ['r' => $id, 't' => $type]);
$has = static fn(int $id, string $type, string $like): bool => Database::exists(
    'SELECT 1 FROM route_stops WHERE route_id = :r AND stop_type = :t AND stop_name LIKE :n', ['r' => $id, 't' => $type, 'n' => $like]);
$add = static function (int $id, string $type, string $name, ?string $lm, ?string $time, ?float $lat, ?float $lng, int $sort, int $border = 0): void {
    Database::insert('route_stops', [
        'route_id' => $id, 'stop_type' => $type, 'stop_name' => $name, 'landmark' => $lm, 'stop_time' => $time,
        'latitude' => $lat, 'longitude' => $lng, 'is_border' => $border, 'sort_order' => $sort,
    ]);
};
$copy = static function (array $s, int $to, int $sort) use ($add): void {
    $add($to, (string) $s['stop_type'], (string) $s['stop_name'], $s['landmark'], $s['stop_time'],
        $s['latitude'] !== null ? (float) $s['latitude'] : null, $s['longitude'] !== null ? (float) $s['longitude'] : null,
        $sort, (int) $s['is_border']);
};
$town = [
    'Mehsana'    => ['SHG Head Office, Silver Complex', 23.5880, 72.3693],
    'Palanpur'   => [null, 24.1725, 72.4381],
    'Himatnagar' => [null, 23.5980, 72.9630],
    'Rajkot'     => ['Rajkot office pickup (+91 94268 27507)', 22.3039, 70.8022],
    'Godhra'     => [null, 22.7788, 73.6143],
    'Dahod'      => [null, 22.8350, 74.2550],
];
$addTown = static function (int $id, string $type, string $t, int $sort) use ($add, $town): void {
    $add($id, $type, $t, $town[$t][0], null, $town[$t][1], $town[$t][2], $sort);
};

$rot = Fleet::rotation();
if (!$rot['enabled'] || $rot['mode'] !== 'round_trip' || count($rot['buses']) < 3) {
    fwrite(STDERR, "Rotation is not a 3-bus round trip — set it in Admin → Trips first. Nothing changed.\n");
    exit(1);
}
[$bus1, $bus2, $bus3] = array_slice($rot['buses'], 0, 3);

$r2 = $route('r2');
$r7 = $route('r7');
if ($r2 === null || $r7 === null) {
    fwrite(STDERR, "r2 / r7 not found. Nothing changed.\n");
    exit(1);
}
$r2Id = (int) $r2['id'];
$r7Id = (int) $r7['id'];

/* 1. BUS 01 */
$up = $stops($r2Id, 'boarding');
$last = (int) end($up)['sort_order'];
foreach (['Mehsana', 'Palanpur'] as $t) {
    if (!$has($r2Id, 'boarding', $t . '%')) { $addTown($r2Id, 'boarding', $t, ++$last); echo "BUS 01: + $t pickup\n"; }
}
if (!$has($r7Id, 'drop', 'Palanpur%')) { $addTown($r7Id, 'drop', 'Palanpur', -2); echo "BUS 01: + Palanpur drop\n"; }
if (!$has($r7Id, 'drop', 'Mehsana%'))  { $addTown($r7Id, 'drop', 'Mehsana', -1);  echo "BUS 01: + Mehsana drop\n"; }
$n = Database::run("DELETE FROM route_stops WHERE route_id IN (:a, :b) AND stop_name LIKE 'Rajkot%'", ['a' => $r2Id, 'b' => $r7Id]);
if ($n > 0) { echo "BUS 01: Rajkot moved to BUS 03 ($n rows)\n"; }

/* 2. BUS 02 / BUS 03 routes */
$make = static function (string $code, string $from, string $to, array $tpl, int $busId) use ($route): array {
    $have = $route($code);
    if ($have !== null) {
        return [$have, false];
    }
    Database::insert('routes', [
        'route_code' => $code, 'from_city' => $from, 'to_city' => $to, 'bus_id' => $busId ?: null,
        'coach_type' => $tpl['coach_type'], 'dep_time' => $tpl['dep_time'], 'arr_time' => $tpl['arr_time'],
        'day_offset' => (int) $tpl['day_offset'], 'duration_text' => $tpl['duration_text'], 'base_fare' => $tpl['base_fare'],
        'amenities' => $tpl['amenities'], 'crew_name' => $tpl['crew_name'], 'crew_phone' => $tpl['crew_phone'],
        'is_active' => 1, 'sort_order' => (int) $tpl['sort_order'] + 1,
    ]);
    echo "route $code created ($from → $to)\n";
    return [$route($code), true];
};
$bus1Only = static fn(string $n): bool => (bool) preg_match('/^(Mehsana|Palanpur|Rajkot)/i', $n);

[$b2u, $new] = $make('b02-up', (string) $r2['from_city'], (string) $r2['to_city'], $r2, $bus2);
if ($new) {
    $i = 0;
    foreach ($stops($r2Id, 'boarding') as $s) { if (!$bus1Only((string) $s['stop_name'])) { $copy($s, (int) $b2u['id'], ++$i); } }
    $addTown((int) $b2u['id'], 'boarding', 'Himatnagar', ++$i);
    foreach ($stops($r2Id, 'drop') as $j => $s) { $copy($s, (int) $b2u['id'], $j + 1); }
}
[$b2d, $new] = $make('b02-dn', (string) $r7['from_city'], (string) $r7['to_city'], $r7, $bus2);
if ($new) {
    foreach ($stops($r7Id, 'boarding') as $j => $s) { $copy($s, (int) $b2d['id'], $j + 1); }
    $i = 0;
    $addTown((int) $b2d['id'], 'drop', 'Himatnagar', ++$i);
    foreach ($stops($r7Id, 'drop') as $s) { if (!$bus1Only((string) $s['stop_name'])) { $copy($s, (int) $b2d['id'], ++$i); } }
}
$amd = null;
foreach ($stops($r2Id, 'boarding') as $s) { if (stripos((string) $s['stop_name'], 'Nana Chiloda') !== false) { $amd = $s; } }
[$b3u, $new] = $make('b03-up', 'Rajkot', (string) $r2['to_city'], $r2, $bus3);
if ($new) {
    $addTown((int) $b3u['id'], 'boarding', 'Rajkot', 1);
    if ($amd !== null) { $s = $amd; $s['stop_time'] = null; $copy($s, (int) $b3u['id'], 2); }
    $addTown((int) $b3u['id'], 'boarding', 'Godhra', 3);
    $addTown((int) $b3u['id'], 'boarding', 'Dahod', 4);
    foreach ($stops($r2Id, 'drop') as $j => $s) { $copy($s, (int) $b3u['id'], $j + 1); }
}
[$b3d, $new] = $make('b03-dn', (string) $r7['from_city'], 'Rajkot', $r7, $bus3);
if ($new) {
    foreach ($stops($r7Id, 'boarding') as $j => $s) { $copy($s, (int) $b3d['id'], $j + 1); }
    $addTown((int) $b3d['id'], 'drop', 'Dahod', 1);
    $addTown((int) $b3d['id'], 'drop', 'Godhra', 2);
    if ($amd !== null) { $s = $amd; $s['stop_type'] = 'drop'; $s['stop_time'] = null; $copy($s, (int) $b3d['id'], 3); }
    $addTown((int) $b3d['id'], 'drop', 'Rajkot', 4);
}

/* 3. Rotation: each slot on its own routes (everything else unchanged). */
$routes = $rot['routes'];
$routes[$bus1] = ['out' => $r2Id, 'back' => $r7Id];
$routes[$bus2] = ['out' => (int) $b2u['id'], 'back' => (int) $b2d['id']];
$routes[$bus3] = ['out' => (int) $b3u['id'], 'back' => (int) $b3d['id']];
if ($routes != $rot['routes']) {
    Fleet::saveRotation(true, $rot['buses'], $rot['cycleDays'], $rot['startDate'], 0, 'round_trip',
        ['stagger' => $rot['stagger'], 'backAfter' => $rot['backAfter'], 'routes' => $routes]);
    echo "rotation: slot routes set (BUS 01 r2/r7, BUS 02 b02, BUS 03 b03)\n";
}
Database::query('UPDATE routes SET bus_id = :b WHERE id IN (:u, :d)', ['b' => $bus2, 'u' => (int) $b2u['id'], 'd' => (int) $b2d['id']]);
Database::query('UPDATE routes SET bus_id = :b WHERE id IN (:u, :d)', ['b' => $bus3, 'u' => (int) $b3u['id'], 'd' => (int) $b3d['id']]);

/* 4. Booked trips already sold on r2 / r7 for a BUS 02 / BUS 03 day stay
      there; the new route's row for that date is created OFF. */
$newFor = [$bus2 => ['out' => (int) $b2u['id'], 'back' => (int) $b2d['id']], $bus3 => ['out' => (int) $b3u['id'], 'back' => (int) $b3d['id']]];
foreach (Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.bus_id FROM schedules s
      WHERE s.route_id IN (:a, :b) AND s.slot = 1 AND s.status = 'scheduled' AND s.travel_date >= CURDATE()
        AND EXISTS (SELECT 1 FROM booking_legs l JOIN bookings bk ON bk.id = l.booking_id
                     WHERE l.schedule_id = s.id AND bk.status IN ('pending','confirmed'))",
    ['a' => $r2Id, 'b' => $r7Id]
) as $s) {
    $leg  = (int) $s['route_id'] === $r2Id ? 'out' : 'back';
    $plan = Fleet::planFor(['id' => (int) $newFor[$bus2][$leg], 'from_city' => $leg === 'out' ? 'Surat' : 'Rupaidiha', 'to_city' => $leg === 'out' ? 'Rupaidiha' : 'Surat'], (string) $s['travel_date']);
    $p3   = Fleet::planFor(['id' => (int) $newFor[$bus3][$leg], 'from_city' => $leg === 'out' ? 'Rajkot' : 'Rupaidiha', 'to_city' => $leg === 'out' ? 'Rupaidiha' : 'Rajkot'], (string) $s['travel_date']);
    $twin = $plan['runs'] ? $newFor[$bus2][$leg] : ($p3['runs'] ? $newFor[$bus3][$leg] : 0);
    if ($twin === 0) {
        continue;   // a BUS 01 day — r2 / r7 is its own route
    }
    Database::insertIgnore('schedules', [
        'route_id' => $twin, 'travel_date' => $s['travel_date'], 'slot' => 1, 'bus_id' => $s['bus_id'],
        'total_seats' => (int) Database::scalar('SELECT total_seats FROM schedules WHERE id = :i', ['i' => (int) $s['id']]),
        'status' => 'scheduled', 'is_blocked' => 1,
    ]);
    Database::query('UPDATE schedules SET is_blocked = 1 WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $twin, 'd' => $s['travel_date']]);
    echo "  booked trip kept: schedule #{$s['id']} {$s['travel_date']} stays on " . ($leg === 'out' ? 'r2' : 'r7') . " (new route OFF that day)\n";
}

/* 5. Picker */
$mp = Fare::mainPoints();
$added = [];
foreach (['Mehsana', 'Palanpur', 'Himatnagar', 'Rajkot', 'Godhra', 'Dahod'] as $t) {
    if (!in_array($t, $mp['india'] ?? [], true)) { $mp['india'][] = $t; $added[] = $t; }
}
if ($added !== []) {
    Settings::set('main_points', $mp, 'json', 'booking', true);
    echo 'picker: + ' . implode(', ', $added) . "\n";
}

echo "\nNext 8 days (rotation):\n";
foreach (Fleet::roundTripPreview(8) as $p) {
    echo '  ' . $p['date'] . '  out: ' . ($p['out'] ? 'BUS 0' . $p['outSlot'] : '-') . '  back: ' . ($p['back'] ? 'BUS 0' . $p['backSlot'] : '-') . "\n";
}
echo "\nNow run: php tools/apply-rotation.php --from=" . todayISO() . " --go\n";
exit(0);
