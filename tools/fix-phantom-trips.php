<?php
/**
 * Close phantom trips (29 Oct 2026 audit). CLI only. Dry run unless --go.
 *
 *   php tools/fix-phantom-trips.php          # lists, changes nothing
 *   php tools/fix-phantom-trips.php --go     # marks them cancelled
 *
 * A phantom trip is a schedules row, today or later, on a route that is
 * switched off (routes.is_active = 0), or dated absurdly far ahead (> 1 year),
 * that has NOTHING on it: no booking leg, no seat, no seat lock. admin/routes.php
 * used to create one per switched-off route on every visit (fixed in the same
 * release). Rows are never deleted — status becomes 'cancelled' with a reason,
 * so the Trips board and "today" counts stop showing buses that do not run.
 * Past rows are left exactly as they are (history). Every change is audited.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/includes/bootstrap.php';

$go = in_array('--go', $argv, true);
$rows = Database::fetchAll(
    "SELECT s.id, s.route_id, r.route_code, r.is_active, s.travel_date, s.status
       FROM schedules s JOIN routes r ON r.id = s.route_id
      WHERE s.travel_date >= CURDATE()
        AND s.status = 'scheduled'
        AND (r.is_active = 0 OR s.travel_date > CURDATE() + INTERVAL 1 YEAR)
        AND NOT EXISTS (SELECT 1 FROM booking_legs bl  WHERE bl.schedule_id = s.id)
        AND NOT EXISTS (SELECT 1 FROM booking_seats bs WHERE bs.schedule_id = s.id)
        AND NOT EXISTS (SELECT 1 FROM seat_locks sl    WHERE sl.schedule_id = s.id)
      ORDER BY s.travel_date, s.id"
);

echo ($go ? 'APPLY' : 'DRY RUN') . ': ' . count($rows) . " phantom trip(s)\n";
foreach ($rows as $r) {
    printf("  #%d %s %s %s\n", $r['id'], $r['route_code'], $r['travel_date'],
        (int) $r['is_active'] === 1 ? '(far-future test row)' : '(route switched off)');
}
if (!$go || $rows === []) {
    exit(0);
}

$reason = 'Audit 29 Oct 2026: phantom trip (route switched off / test date), nothing booked';
$done = 0;
foreach ($rows as $r) {
    $n = Database::update('schedules', [
        'status'        => 'cancelled',
        'cancel_reason' => $reason,
        'cancelled_at'  => date('Y-m-d H:i:s'),
    ], "id = :id AND status = 'scheduled'", ['id' => (int) $r['id']]);
    if ($n > 0) {
        $done++;
        Logger::audit('schedules.phantom_closed', 'schedule', (string) $r['id'],
            ['status' => 'scheduled'], ['status' => 'cancelled'], $r['route_code'] . ' ' . $r['travel_date']);
    }
}
echo "Closed $done.\n";
