<?php
/**
 * upgrade-2026-10-31-bus01-plate.php — owner, 31 Oct 2026:
 * "BR06RA6555 yei number sahi ho, 1 tarik ko jane".
 *
 *   1. BUS 01's real plate is BR06RA6555. The rotation's slot-1 coach was
 *      stored as "BR06RA65559" (typo) while a second "BR06RA6555" record
 *      (seater, one old trip) existed beside it. The old trip and the GPS
 *      tracker move onto the main record, the duplicate is switched off
 *      (plate suffixed -DUP, history kept) and the main record gets the
 *      right plate. The unused test bus "gj2344" is switched off.
 *   2. Day 1 of the round-trip rotation = 1 Nov 2026 (BUS 01 leaves Day 1,
 *      as on the poster).
 *   3. Upcoming trips of the rotation's routes are re-planned: a trip with
 *      no booking gets the rotation's coach and is switched ON / OFF to
 *      match; the booked 1 Nov trip goes onto BUS 01; any other booked trip
 *      that no longer matches is only listed.
 *   4. Tickets of the re-coached booked trips are re-rendered (no message
 *      is sent).
 *
 *   php database/upgrade-2026-10-31-bus01-plate.php          # dry run
 *   php database/upgrade-2026-10-31-bus01-plate.php --go
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';

$go    = in_array('--go', $argv, true);
$plate = 'BR06RA6555';
$day1  = '2026-11-01';
echo $go ? "APPLY\n" : "DRY RUN (add --go to apply)\n";

$rot = Fleet::rotation();
if (!$rot['enabled'] || $rot['mode'] !== 'round_trip' || $rot['buses'] === []) {
    fwrite(STDERR, "Rotation is not a round trip — nothing changed.\n");
    exit(1);
}
$mainId = (int) $rot['buses'][0];
$main   = Database::fetch('SELECT * FROM buses WHERE id = :i', ['i' => $mainId]);
$dup    = Database::fetch('SELECT * FROM buses WHERE bus_number = :p AND id <> :i', ['p' => $plate, 'i' => $mainId]);
echo "BUS 01 record #$mainId '{$main['bus_number']}'" . ($dup ? " · duplicate #{$dup['id']} '{$dup['bus_number']}'" : '') . "\n";

/* 1. Plate */
if ($go) {
    Database::transaction(static function () use ($dup, $mainId, $main, $plate): void {
        if ($dup !== null) {
            $dupId = (int) $dup['id'];
            Database::query('UPDATE schedules SET bus_id = :m WHERE bus_id = :d', ['m' => $mainId, 'd' => $dupId]);
            Database::query('UPDATE gps_trackers SET bus_id = :m WHERE bus_id = :d', ['m' => $mainId, 'd' => $dupId]);
            Database::query('UPDATE routes SET bus_id = :m WHERE bus_id = :d', ['m' => $mainId, 'd' => $dupId]);
            Database::query('UPDATE buses SET bus_number = :n, is_active = 0 WHERE id = :d', ['n' => substr($plate . '-DUP' . $dupId, 0, 30), 'd' => $dupId]);
            Logger::audit('bus.merged', 'bus', (string) $dupId, ['bus_number' => $dup['bus_number']], ['merged_into' => $mainId], 'owner: real plate ' . $plate);
        }
        Database::query('UPDATE buses SET bus_number = :n WHERE id = :m', ['n' => $plate, 'm' => $mainId]);
        Logger::audit('bus.update', 'bus', (string) $mainId, ['bus_number' => $main['bus_number']], ['bus_number' => $plate], 'owner: real plate');
    });
    $test = Database::fetch("SELECT id FROM buses WHERE bus_number = 'gj2344' AND is_active = 1
        AND NOT EXISTS (SELECT 1 FROM schedules s WHERE s.bus_id = buses.id)", []);
    if ($test !== null) {
        Database::query('UPDATE buses SET is_active = 0 WHERE id = :i', ['i' => (int) $test['id']]);
        Logger::audit('bus.deactivate', 'bus', (string) $test['id'], ['is_active' => 1], ['is_active' => 0], 'unused test bus');
        echo "test bus gj2344 switched off\n";
    }
    echo "plate fixed: #$mainId = $plate" . ($dup ? ", duplicate #{$dup['id']} merged + switched off" : '') . "\n";
}

/* 2. Day 1 */
if ($rot['startDate'] !== $day1) {
    echo "rotation Day 1: {$rot['startDate']} → $day1\n";
    if ($go) {
        Fleet::saveRotation(true, $rot['buses'], $rot['cycleDays'], $day1, 0, 'round_trip',
            ['stagger' => $rot['stagger'], 'backAfter' => $rot['backAfter'], 'routes' => $rot['routes']]);
    }
}

/* 3. Re-plan (under the NEW Day 1, also in a dry run) */
$planFor = static function (array $r, string $date) use ($day1, $go): array {
    if ($go) {
        return Fleet::planFor($r, $date);
    }
    // Dry run: same arithmetic as Fleet::planFor with the new epoch.
    $rot = Fleet::rotation();
    $outbound = Fare::routeDir($r) === 'toNepal';
    $leg = $outbound ? 'out' : 'back';
    $cycle = count($rot['buses']) * $rot['stagger'];
    $i = Fleet::dayIndex($date, $day1) % $cycle;
    if ($i < 0) { $i += $cycle; }
    foreach ($rot['buses'] as $k => $busId) {
        $day = $outbound ? $k * $rot['stagger'] : ($k * $rot['stagger'] + $rot['backAfter']) % $cycle;
        if ($day !== $i) { continue; }
        $want = (int) ($rot['routes'][$busId][$leg] ?? 0);
        if ($want === 0 || $want === (int) $r['id']) { return ['runs' => true, 'bus' => $busId]; }
    }
    return ['runs' => false, 'bus' => null];
};
require_once INCLUDE_PATH . '/fare.php';
$routeIds = [];
foreach ($rot['routes'] as $pair) { foreach (['out', 'back'] as $l) { if ((int) $pair[$l] > 0) { $routeIds[] = (int) $pair[$l]; } } }
$routeIds = array_values(array_unique($routeIds));
$reissue = [];
$n = ['on' => 0, 'off' => 0, 'bus' => 0, 'same' => 0, 'listed' => 0];
foreach (Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.bus_id, s.is_blocked, r.route_code, r.from_city, r.to_city,
            (SELECT COUNT(*) FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
              WHERE l.schedule_id = s.id AND b.status IN ('pending','confirmed')) AS booked
       FROM schedules s JOIN routes r ON r.id = s.route_id
      WHERE s.route_id IN (" . implode(',', $routeIds) . ") AND s.slot = 1 AND s.status = 'scheduled'
        AND s.travel_date >= CURDATE()
      ORDER BY s.travel_date, r.id"
) as $s) {
    $plan  = $planFor(['id' => (int) $s['route_id'], 'from_city' => $s['from_city'], 'to_city' => $s['to_city']], (string) $s['travel_date']);
    $block = $plan['runs'] ? 0 : 1;
    $bus   = $plan['runs'] ? (int) $plan['bus'] : (int) $s['bus_id'];
    $tag   = sprintf('#%-5d %s %-6s', $s['id'], $s['travel_date'], $s['route_code']);
    if ((int) $s['booked'] > 0) {
        if ($plan['runs'] && ((int) $s['bus_id'] !== $bus || (int) $s['is_blocked'] !== 0)) {
            echo "  BOOKED $tag {$s['booked']} booking(s): bus #{$s['bus_id']} → #$bus, ON\n";
            if ($go) { Database::update('schedules', ['bus_id' => $bus, 'is_blocked' => 0], 'id = :i', ['i' => (int) $s['id']]); }
            $reissue[] = (int) $s['id'];
        } elseif (!$plan['runs']) {
            echo "  LISTED $tag {$s['booked']} booking(s) but no bus this way that day — move them (Change date)\n";
            $n['listed']++;
        } else {
            $n['same']++;
        }
        continue;
    }
    if ((int) $s['is_blocked'] === $block && (int) $s['bus_id'] === $bus) { $n['same']++; continue; }
    $n[$block ? 'off' : ($s['is_blocked'] ? 'on' : 'bus')]++;
    if ($go) {
        Database::update('schedules', ['bus_id' => $bus, 'is_blocked' => $block], 'id = :i', ['i' => (int) $s['id']]);
    }
}
if ($go && $n['on'] + $n['off'] + $n['bus'] > 0) {
    Logger::audit('fleet.rotation_applied', 'schedules', 'rotation', [], $n, 'Day 1 = ' . $day1);
}
printf("trips: %d switched ON · %d switched OFF · %d coach changed · %d already right · %d listed\n", $n['on'], $n['off'], $n['bus'], $n['same'], $n['listed']);

/* 4. Tickets */
foreach ($reissue as $sid) {
    foreach (Database::fetchAll("SELECT DISTINCT b.id, b.pnr FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
                                  WHERE l.schedule_id = :s AND b.status = 'confirmed'", ['s' => $sid]) as $b) {
        echo "  ticket {$b['pnr']} " . ($go ? 're-rendered' : 'would be re-rendered') . "\n";
        if ($go) { Ticket::reissue((int) $b['id']); }
    }
}

echo "\nNext 8 days:\n";
foreach (Fleet::roundTripPreview(8) as $p) {
    echo '  ' . $p['date'] . '  out: ' . ($p['out'] ? 'BUS 0' . $p['outSlot'] : '-') . '  back: ' . ($p['back'] ? 'BUS 0' . $p['backSlot'] : '-') . "\n";
}
exit(0);
