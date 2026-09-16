<?php
/**
 * admin/api/live-status.php — the 15-second heartbeat for the Control
 * Center dashboard.
 *
 * Returns today's departures with a computed state pulled from
 * TripStatus, plus the four KPIs the dashboard hero row wants:
 *   - Today's Trips
 *   - Departing Soon (Boarding + Departing)
 *   - Departed (any post-departure state that hasn't reached On Route)
 *   - On Route (Departed → Arrived, inclusive of border)
 *
 * Same auth gate as admin/index.php — dashboard.view. Agents redirect
 * to their own panel from the dashboard, so they don't reach this
 * endpoint under normal navigation; the guard still refuses them.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/tripnotify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$today = todayISO();
// T8-DATE-PARAM begin — optional ?date=YYYY-MM-DD lets the dashboard load a
// past/future snapshot through the same endpoint. Additive: when missing or
// invalid we fall through to today, which is the pre-T8 behavior. The SELECT
// list, TripStatus annotation, KPI aggregation and response shape are all
// unchanged; only the WHERE binding changes.
$requestedDate = isset($_GET['date']) ? (string) $_GET['date'] : '';
if ($requestedDate !== '' && Security::isValidDate($requestedDate)) {
    $today = $requestedDate;
}
// T8-DATE-PARAM end

// Same shape as the dashboard's server render — everything the "Live
// Bus Status" card wants, in one query. LEFT JOINs so a trip without
// a bus (or driver) still shows up rather than vanishing from the list.
/* dep_time here is the effective SCHEDULED departure: a per-schedule
   dep_time_override wins over the route timetable so the pill,
   detail text, and row order all follow the delay-workflow reschedule.
   delay_minutes stays a separate DELTA — TripStatus::compute() layers
   it on top of the resulting timestamp. is_blocked is selected so the
   dashboard row can carry T5's "blocked" pill; blocked schedules are
   still returned to admins (only customer-facing views hide them). */
$rows = Database::fetchAll(
    "SELECT s.id, s.travel_date, s.status, s.delay_minutes, s.delay_note, s.total_seats,
            s.is_blocked,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            r.arr_time, r.day_offset, r.from_city, r.to_city, r.route_code,
            bu.bus_number, bu.bus_name,
            d.full_name AS driver_name, d.phone AS driver_phone,
            (SELECT COUNT(*) FROM booking_seats bs
              WHERE bs.schedule_id=s.id AND bs.released_at IS NULL) AS sold
       FROM schedules s
       JOIN routes r ON r.id=s.route_id
       LEFT JOIN buses   bu ON bu.id = s.bus_id
       LEFT JOIN drivers d  ON d.id  = s.driver_id
      WHERE s.travel_date=:t AND r.is_active=1
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC",
    ['t' => $today]
);

// TripStatus::maybeSoldOut() will flip a fully-booked trip to
// STATE_SOLD_OUT during annotation; the `sold` alias from the SELECT
// already carries the live seat count so annotate() has what it needs.
/* `sold` counts booking_seats ROWS, and a private cabin is one row over two
   berths — so a coach carrying private sales reported as emptier than it is,
   and TripStatus::maybeSoldOut() below would not flip a genuinely full bus to
   SOLD OUT. Restated in physical berths through the seat engine. */
$_beds = Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['id'], $rows));
foreach ($rows as &$_r) {
    $_r['sold']         = $_beds[(int) $_r['id']] ?? (int) ($_r['sold'] ?? 0);
    $_r['seats_booked'] = (int) $_r['sold'];
}
unset($_r);

$rows = TripStatus::annotate($rows);

$kpi = ['todayTrips' => count($rows), 'departingSoon' => 0, 'departed' => 0, 'onRoute' => 0];
$trips = [];
foreach ($rows as $r) {
    $st = $r['_status'];
    $state = $st['state'];
    if ($state === TripStatus::STATE_BOARDING || $state === TripStatus::STATE_DEPARTING) {
        $kpi['departingSoon']++;
    }
    // Departed is a very brief pre-run window (the grace period between
    // dep_time and either a departed mark or DELAY_GRACE), so count it
    // toward the "departed today" tally along with everything that
    // followed. Cancelled trips do NOT count as departed.
    if (in_array($state, [
        TripStatus::STATE_DEPARTED, TripStatus::STATE_DELAYED,
        TripStatus::STATE_ON_ROUTE, TripStatus::STATE_ARRIVED, TripStatus::STATE_COMPLETED,
    ], true)) {
        $kpi['departed']++;
    }
    if (in_array($state, [TripStatus::STATE_ON_ROUTE, TripStatus::STATE_ARRIVED], true)) {
        $kpi['onRoute']++;
    }

    $trips[] = [
        'id'           => (int) $r['id'],
        'state'        => $state,
        'state_label'  => $st['label'],
        'state_color'  => $st['color'],
        // camelCase aliases so the shared trips.php paint() / any new
        // dashboard widget can read the same keys used everywhere else
        // (trips-data.php, api/search.php). Additive — the snake_case
        // pair above stays for existing consumers.
        'stateLabel'   => $st['label'],
        'stateColor'   => $st['color'],
        'minutes_to'   => (int) $st['minutesTo'],
        'detail'       => $st['detail'],
        'sold'         => (int) $r['sold'],
        'total'        => (int) $r['total_seats'],
        'bus_number'   => (string) ($r['bus_number'] ?? ''),
        'bus_name'     => (string) ($r['bus_name']   ?? ''),
        'driver_name'  => (string) ($r['driver_name'] ?? ''),
        'driver_phone' => (string) ($r['driver_phone'] ?? ''),
        'dep_time'     => substr((string) ($r['dep_time'] ?? ''), 0, 5),
        'from_city'    => (string) ($r['from_city'] ?? ''),
        'to_city'      => (string) ($r['to_city'] ?? ''),
        'route_code'   => (string) ($r['route_code'] ?? ''),
    ];
}

echo json_encode([
    'generated_at' => date('Y-m-d H:i:s'),
    'kpi'          => $kpi,
    'trips'        => $trips,
], JSON_UNESCAPED_UNICODE);
