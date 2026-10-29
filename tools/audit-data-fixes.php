<?php
/**
 * Data corrections from the 29 Oct 2026 audit. CLI only. Dry run unless --go.
 *
 *   php tools/audit-data-fixes.php          # lists what would change
 *   php tools/audit-data-fixes.php --go     # applies it
 *
 * Only facts that are wrong with no judgement involved — no money moves:
 *   1. distance  routes.distance_km of each active route = its timetable's
 *                furthest stop (route_stops), and the site-wide fallback
 *                setting route_distance_km = the first active route's figure.
 *   2. gps       a live_trips row still 'running' with no position for 24 h
 *                and started over 24 h ago is marked completed (the trip
 *                ended; the tracker just never said so).
 *   3. cod       a 'cod_pending' payment on a cancelled / rejected / expired
 *                booking — cash that was never collected and never will be —
 *                is closed as 'rejected' ("voided"), exactly what a cancel
 *                does from this release on. Verified payments, cash-held
 *                ledger rows and refunds are NOT touched (owner's manual work).
 * Nothing is deleted; every row changed is audited with its old value.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/includes/bootstrap.php';

$go = in_array('--go', $argv, true);
echo ($go ? 'APPLY' : 'DRY RUN') . "\n";

/* 1. distance --------------------------------------------------------- */
$routes = Database::fetchAll(
    "SELECT r.id, r.route_code, r.distance_km,
            (SELECT MAX(rs.km_from_origin) FROM route_stops rs WHERE rs.route_id = r.id AND rs.is_active = 1) AS stop_km
       FROM routes r WHERE r.is_active = 1 ORDER BY r.sort_order, r.id"
);
$first = null;
foreach ($routes as $r) {
    $km = (float) ($r['stop_km'] ?? 0);
    if ($km < 100) {
        continue;
    }
    $first ??= (int) round($km);
    if ((int) round((float) $r['distance_km']) === (int) round($km)) {
        continue;
    }
    printf("  distance  %s: %s -> %d km\n", $r['route_code'], $r['distance_km'] ?? 'NULL', (int) round($km));
    if ($go) {
        Database::update('routes', ['distance_km' => (int) round($km)], 'id = :id', ['id' => (int) $r['id']]);
        Logger::audit('route.distance_fixed', 'route', (string) $r['route_code'], ['distance_km' => $r['distance_km']],
            ['distance_km' => (int) round($km)], 'audit 29 Oct 2026: timetable distance');
    }
}
if ($first !== null && (int) round(Settings::getFloat('route_distance_km', 0)) !== $first) {
    printf("  distance  setting route_distance_km: %s -> %d\n", Settings::getString('route_distance_km', '(unset)'), $first);
    if ($go) {
        $old = Settings::getString('route_distance_km', '');
        Settings::set('route_distance_km', (string) $first, 'int', 'general');
        Logger::audit('settings.route_distance_km', 'settings', 'route_distance_km', ['value' => $old], ['value' => $first],
            'audit 29 Oct 2026: timetable distance');
    }
}

/* 2. gps -------------------------------------------------------------- */
try {
    $stale = Database::fetchAll(
        "SELECT id, schedule_id, bus_id, started_at, last_ping_at FROM live_trips
          WHERE status = 'running'
            AND COALESCE(last_ping_at, started_at) < NOW() - INTERVAL 24 HOUR"
    );
} catch (Throwable $e) {
    $stale = [];
}
foreach ($stale as $t) {
    printf("  gps       live trip #%d (schedule %d, bus %s) running since %s, last ping %s -> completed\n",
        $t['id'], $t['schedule_id'], $t['bus_id'] ?? '-', $t['started_at'], $t['last_ping_at'] ?? 'never');
    if ($go) {
        Database::update('live_trips', ['status' => 'completed', 'ended_at' => date('Y-m-d H:i:s')],
            "id = :id AND status = 'running'", ['id' => (int) $t['id']]);
        Logger::audit('livetrip.stale_closed', 'live_trip', (string) $t['id'], ['status' => 'running'], ['status' => 'completed'],
            'audit 29 Oct 2026: no position for 24 h');
    }
}

/* 3. cod -------------------------------------------------------------- */
$cod = Database::fetchAll(
    "SELECT p.id, p.amount, b.pnr, b.status
       FROM payments p JOIN bookings b ON b.id = p.booking_id
      WHERE p.status = 'cod_pending' AND b.status IN ('cancelled', 'rejected', 'expired')
      ORDER BY p.id"
);
$sum = 0.0;
foreach ($cod as $p) {
    $sum += (float) $p['amount'];
}
printf("  cod       %d open cash-on-boarding request(s) on tickets that will not travel, %s -> voided\n", count($cod), inr($sum));
if ($go) {
    foreach ($cod as $p) {
        Database::update('payments', [
            'status'        => 'rejected',
            'reject_reason' => 'Voided (audit 29 Oct 2026): ticket ' . $p['status'] . ' before the cash was collected',
        ], "id = :id AND status = 'cod_pending'", ['id' => (int) $p['id']]);
        Logger::audit('payment.cod_voided', 'booking', (string) $p['pnr'], ['payment' => (int) $p['id'], 'status' => 'cod_pending'],
            ['status' => 'rejected'], 'audit 29 Oct 2026: never collected, ticket ' . $p['status']);
    }
}
echo $go ? "Done.\n" : "Dry run — nothing written. Re-run with --go.\n";
