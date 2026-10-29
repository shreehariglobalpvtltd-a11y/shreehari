<?php
/**
 * Re-plan existing future trips under the saved bus rotation (29 Oct 2026
 * audit). CLI only. Dry run unless --go.
 *
 *   php tools/apply-rotation.php                    # from tomorrow, 60 days, lists only
 *   php tools/apply-rotation.php --go               # apply
 *   php tools/apply-rotation.php --from=2026-11-01 --days=90
 *
 * Schedule rows keep the bus they were given when first created, so turning
 * the rotation on (Admin -> Trips -> Bus rotation) only changes trips created
 * after that. This walks the daily-bus rows (slot 1, status scheduled) of the
 * active routes and, for a row with NOTHING on it (no booking leg, seat or
 * lock):
 *   - the direction runs that day  -> bus_id becomes the rotation's bus
 *   - the direction does not run   -> the row is switched OFF (is_blocked = 1)
 * A row that already has bookings is never changed — it is listed as
 * CONFLICT (no bus that day) or KEEP (different bus) for the office to decide.
 * Nothing is deleted. Every change is audited.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/includes/bootstrap.php';

$opt = ['go' => false, 'from' => addDaysISO(todayISO(), 1), 'days' => 60];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--go') { $opt['go'] = true; }
    elseif (str_starts_with($a, '--from=') && Security::isValidDate(substr($a, 7))) { $opt['from'] = substr($a, 7); }
    elseif (str_starts_with($a, '--days=')) { $opt['days'] = max(1, min(400, (int) substr($a, 7))); }
}

$rot = Fleet::rotation();
if (!$rot['enabled']) {
    echo "Rotation is OFF (Admin -> Trips -> Bus rotation). Nothing to apply.\n";
    exit(0);
}
printf("%s · mode %s · buses %s · cycle starts %s · from %s for %d days\n",
    $opt['go'] ? 'APPLY' : 'DRY RUN', $rot['mode'], implode(',', $rot['buses']), $rot['startDate'], $opt['from'], $opt['days']);

$to   = addDaysISO($opt['from'], $opt['days'] - 1);
$rows = Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.bus_id, s.is_blocked, r.route_code, r.from_city, r.to_city,
            (SELECT COUNT(*) FROM booking_legs bl  WHERE bl.schedule_id = s.id)
          + (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL)
          + (SELECT COUNT(*) FROM seat_locks sl    WHERE sl.schedule_id = s.id) AS used
       FROM schedules s JOIN routes r ON r.id = s.route_id
      WHERE r.is_active = 1 AND s.slot = 1 AND s.status = 'scheduled'
        AND s.travel_date BETWEEN :f AND :t
      ORDER BY s.travel_date, r.sort_order, r.id",
    ['f' => $opt['from'], 't' => $to]
);

$count = ['bus' => 0, 'off' => 0, 'same' => 0, 'conflict' => 0, 'keep' => 0];
foreach ($rows as $r) {
    // planFor() matches the slot's routes by ROUTE id - $r['id'] is the schedule id here.
    $plan = Fleet::planFor(['id' => (int) $r['route_id'], 'from_city' => $r['from_city'], 'to_city' => $r['to_city']], (string) $r['travel_date']);
    $used = (int) $r['used'] > 0;
    $tag  = sprintf('#%-5d %s %-4s', $r['id'], $r['travel_date'], $r['route_code']);

    if (!$plan['runs']) {
        if ($used) {
            $count['conflict']++;
            echo "  CONFLICT $tag has bookings but the rotation has no bus this way today — office decides\n";
            continue;
        }
        if ((int) $r['is_blocked'] === 1) { $count['same']++; continue; }
        $count['off']++;
        echo "  OFF      $tag no bus this direction today -> switched off\n";
        if ($opt['go']) {
            Database::update('schedules', ['is_blocked' => 1], "id = :id AND status = 'scheduled'", ['id' => (int) $r['id']]);
            Logger::audit('fleet.rotation_applied', 'schedule', (string) $r['id'], ['is_blocked' => 0], ['is_blocked' => 1], 'round-trip rotation: no bus');
        }
        continue;
    }

    $want = $plan['bus'];
    if ($want === null || (int) $r['bus_id'] === $want) { $count['same']++; continue; }
    if ($used) {
        $count['keep']++;
        echo "  KEEP     $tag has bookings on bus #{$r['bus_id']}; rotation says #$want — office decides\n";
        continue;
    }
    $count['bus']++;
    echo "  BUS      $tag bus #{$r['bus_id']} -> #$want\n";
    if ($opt['go']) {
        Database::update('schedules', ['bus_id' => $want], "id = :id AND status = 'scheduled'", ['id' => (int) $r['id']]);
        Logger::audit('fleet.rotation_applied', 'schedule', (string) $r['id'], ['bus_id' => (int) $r['bus_id']], ['bus_id' => $want], 'rotation bus');
    }
}
printf("\nbus changed %d · switched off %d · already right %d · KEEP %d · CONFLICT %d%s\n",
    $count['bus'], $count['off'], $count['same'], $count['keep'], $count['conflict'], $opt['go'] ? '' : '  (dry run — nothing written)');
