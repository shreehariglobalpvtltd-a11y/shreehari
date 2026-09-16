<?php
/**
 * admin/trips-data.php — JSON poll target for the live trip board
 * (admin/trips.php). Returns each upcoming trip's live sold count and hours
 * until departure so the board can refresh counts and urgency flags without
 * a full page reload.
 *
 * T2 (Round 1) — the same TripStatus that renders the server-side pill is
 * now attached to every row (state / stateLabel / stateColor) so the JS
 * paint() can keep the pill in sync using the unified state instead of
 * the old hour-band heuristic. Fields are ADDITIVE; the pre-existing
 * id / sold / total / hours shape is unchanged.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('schedules.view');
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/tripnotify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$today = todayISO();

$schedules = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.id, s.travel_date, s.total_seats, s.status,
            s.delay_minutes, s.delay_note,
            r.dep_time, r.arr_time, r.day_offset
       FROM schedules s JOIN routes r ON r.id = s.route_id
      WHERE s.travel_date >= :today AND s.status = 'scheduled'",
    ['today' => $today]
);

$sold = [];
if ($schedules !== []) {
    $ids = array_map(static fn($s) => (int) $s['schedule_id'], $schedules);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::fetchAll(
        "SELECT schedule_id, COUNT(*) AS n FROM booking_seats
          WHERE released_at IS NULL AND schedule_id IN ($ph) GROUP BY schedule_id",
        $ids
    ) as $r) {
        $sold[(int) $r['schedule_id']] = (int) $r['n'];
    }
}

// Enrich rows with the sold count so TripStatus::maybeSoldOut() can flip
// a fully-booked trip to STATE_SOLD_OUT during annotation. Rows without
// the key just fall through the sold-out check unchanged.
foreach ($schedules as &$_sRow) {
    $_sRow['seats_booked'] = $sold[(int) $_sRow['schedule_id']] ?? 0;
}
unset($_sRow);

$schedules = TripStatus::annotate($schedules);

$out = [];
foreach ($schedules as $s) {
    $sid = (int) $s['schedule_id'];
    $st  = $s['_status'] ?? null;
    $out[] = [
        'id'         => $sid,
        'sold'       => $sold[$sid] ?? 0,
        'total'      => (int) $s['total_seats'],
        'hours'      => round(hoursUntil((string) $s['travel_date'], (string) $s['dep_time']), 2),
        'state'      => $st['state'] ?? null,
        'stateLabel' => $st['label'] ?? null,
        'stateColor' => $st['color'] ?? null,
    ];
}

echo json_encode(['trips' => $out, 'ts' => time()]);
