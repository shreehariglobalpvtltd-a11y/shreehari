<?php
/**
 * admin/map.php — SHARI NAV Global Map & Live Bus Tracking Control Hub
 *
 * Integrates:
 * 1. 🛰️ Live GPS Radar: Real-time GPS devices (OwnTracks, GPSLogger, hardware),
 *    driver phones (driver app), live road lines (Valhalla routegeo), and
 *    passengers by pickup with live ETA minutes.
 * 2. 📍 Boarding & Drop Points: Gujarat-Nepal stops, timings, and fares manager.
 * 3. ⚡ Status Dispatcher: 1-click status broadcaster and WhatsApp radar link dispatcher.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/routegeo.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/gpsfleet.php';

$admin = admin_boot('schedules.view');
$base  = '';
$today = todayISO();
$csrf  = Security::e(Security::csrfToken());
$k     = CSRF_TOKEN_NAME;
$flash = null;
$geoFlash = null;

// POST Action Handlers
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');

        // 1. Build road line (Valhalla polyline6 through stops with coordinates)
        if ($act === 'build_road') {
            if (!Auth::isSuperadmin()) {
                $geoFlash = ['bad', 'Only the owner can build a road line.'];
            } else {
                $rid = (int) ($_POST['route_id'] ?? 0);
                $before = RouteGeo::info($rid);
                try {
                    $res = RouteGeo::build($rid);
                } catch (Throwable $e) {
                    $res = ['ok' => false, 'error' => $e->getMessage(), 'stops' => [], 'missing' => []];
                }
                Logger::audit('route.road_line', 'route', (string) $rid, $before, $res, 'built by admin #' . $admin['id']);
                $geoFlash = $res['ok']
                    ? ['ok', 'Road line v' . $res['version'] . ' saved: ' . $res['km'] . ' km through ' . implode(' → ', $res['stops']) . ' (' . $res['source'] . ').'
                        . ($res['missing'] !== [] ? ' Not on it (no coordinates yet): ' . implode(', ', $res['missing']) . '.' : '')]
                    : ['bad', 'Road line not saved: ' . $res['error'] . '.'];
            }

        // 2. Quick status update
        } elseif ($act === 'update_live_status') {
            $sid    = (int) ($_POST['schedule_id'] ?? 0);
            $state  = (string) ($_POST['live_state'] ?? 'on_time');
            $delay  = max(0, (int) ($_POST['delay_minutes'] ?? 0));
            $stop   = trim(Security::clean((string) ($_POST['current_stop'] ?? ''), 120));
            $note   = trim(Security::clean((string) ($_POST['status_note'] ?? ''), 255));
            if ($sid > 0) {
                Database::run("UPDATE schedules SET delay_minutes = :dm WHERE id = :id", ['dm' => $delay, 'id' => $sid]);
            }
            $lbData = [
                'at'    => time() * 1000,
                'stop'  => $stop ?: ($state === 'border_crossed' ? 'Crossed Nepal/India Border (Rupaidiha 🛃)' : ($state === 'at_station' ? 'Reached Boarding Station' : 'On Route')),
                'state' => $state,
                'delay' => $delay,
                'note'  => $note,
                'by'    => (string) ($admin['username'] ?? 'office'),
                'gps'   => false,
            ];
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', 'livebus', :val, :by)
                 ON DUPLICATE KEY UPDATE kvalue = :val2, updated_by = :by2, updated_at = NOW()",
                [
                    'val' => json_encode($lbData), 'by' => (string) ($admin['username'] ?? 'admin'),
                    'val2' => json_encode($lbData), 'by2' => (string) ($admin['username'] ?? 'admin'),
                ]
            );
            Logger::audit('map.live_update', 'schedule', (string) $sid, null, $lbData, 'Live bus status updated');
            $flash = ['ok', 'Live bus status updated to: ' . ($stop ?: $state) . ' (delay: ' . $delay . ' min).'];

        // 3. Edit boarding stop details
        } elseif ($act === 'update_stop') {
            /* 16 Nov 2026 (BUG1/BUG2): a pickup fare is money - the same routes.edit
               gate as Admin -> Routes (counter / agent logins hold schedules.view
               and could re-price any pickup here). "2,200" was (float) 2.0. */
            Auth::requireAdmin('routes.edit');
            try {
                $stopId = (int) ($_POST['stop_id'] ?? 0);
                $time   = trim((string) ($_POST['stop_time'] ?? ''));
                $fareIn = Boarding::parseRupees($_POST['fare'] ?? '');
                $land   = trim(Security::clean((string) ($_POST['landmark'] ?? ''), 191));
                if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
                    throw new InvalidArgumentException('समय HH:MM मा लेख्नुहोस् · Enter the time as HH:MM.');
                }
                if ($fareIn === false || ($fareIn !== null && ($fareIn < 0 || ($fareIn > 0 && $fareIn < 1) || $fareIn > 100000))) {
                    throw new InvalidArgumentException('भाडा रुपैयाँमा लेख्नुहोस् · Enter the fare in rupees (e.g. 2200), or 0 / blank for the default.');
                }
                $before = Database::fetch('SELECT stop_name, stop_time, fare, landmark FROM route_stops WHERE id = :id', ['id' => $stopId]);
                if ($before === null) {
                    throw new InvalidArgumentException('ठाउँ भेटिएन · Stop not found.');
                }
                $after = [
                    'stop_time' => $time !== '' ? (strlen($time) === 5 ? $time . ':00' : $time) : null,
                    'fare'      => $fareIn !== null && $fareIn > 0 ? round($fareIn, 2) : null,
                    'landmark'  => $land !== '' ? $land : null,
                ];
                Database::update('route_stops', $after, 'id = :id', ['id' => $stopId]);
                Boarding::flush();
                Logger::audit('map.stop_update', 'route_stop', (string) $stopId,
                    ['stop' => $before['stop_name'], 'time' => $before['stop_time'], 'fare' => $before['fare'] !== null ? (float) $before['fare'] : null, 'landmark' => $before['landmark']],
                    ['stop' => $before['stop_name'], 'time' => $after['stop_time'], 'fare' => $after['fare'], 'landmark' => $after['landmark']],
                    'Route stop updated');
                $flash = ['ok', 'Saved ' . $before['stop_name'] . ': '
                    . ($after['fare'] !== null ? inr($after['fare']) : 'default fare')
                    . ($after['stop_time'] !== null ? ' · ' . substr($after['stop_time'], 0, 5) : ' · no time set') . '.'];
            } catch (Throwable $e) {
                $flash = ['bad', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Not saved - ' . $e->getMessage()];
            }

        // 4. Add new boarding / drop stop
        } elseif ($act === 'add_stop') {
            /* 16 Nov 2026 (BUG2, owner: "location add nahi ho raha"): this ran
               outside any try, never asked for routes.edit, sorted every stop 99th
               and wrote the posted stop_type into enum('boarding','drop') - the
               form's "Transit / Meal Checkpoint" was SQLSTATE 1265 = HTTP 500.
               One writer now: Boarding::addStop (same as Admin -> Routes). */
            Auth::requireAdmin('routes.edit');
            try {
                $sName = (string) ($_POST['stop_name'] ?? '');
                $sTime = trim((string) ($_POST['stop_time'] ?? ''));
                Boarding::addStop([
                    'route_id'   => (int) ($_POST['route_id'] ?? 0),
                    'stop_type'  => (string) ($_POST['stop_type'] ?? ''),
                    'stop_name'  => Security::clean($sName, 191),
                    'stop_time'  => $sTime,
                    'fare'       => (string) ($_POST['fare'] ?? ''),
                    'landmark'   => Security::clean((string) ($_POST['landmark'] ?? ''), 191),
                    'is_border'  => !empty($_POST['is_border']),
                    'latitude'   => (string) ($_POST['latitude'] ?? ''),
                    'longitude'  => (string) ($_POST['longitude'] ?? ''),
                ], (int) ($admin['id'] ?? 0));
                $flash = ['ok', 'Added "' . trim($sName) . '".'
                    . ($sTime === '' && ($_POST['stop_type'] ?? '') === 'boarding'
                        ? ' No time set: on the travel day it stops selling ' . Boarding::cutoffMinutes() . ' min before the route leaves - set its time in Admin → Routes (✎).'
                        : '')];
            } catch (InvalidArgumentException $e) {
                $flash = ['bad', $e->getMessage()];
            } catch (Throwable $e) {
                Logger::error('map.php add_stop failed: ' . $e->getMessage());
                $flash = ['bad', 'Not added - the database refused it (' . mb_substr($e->getMessage(), 0, 120) . ').'];
            }

        // 5. Office closes a driver SOS / breakdown (15 Nov 2026)
        } elseif ($act === 'resolve_driver_event') {
            require_once INCLUDE_PATH . '/tripevents.php';
            $evId = (int) ($_POST['event_id'] ?? 0);
            $flash = ($evId > 0 && TripEvents::resolve($evId, (int) ($admin['id'] ?? 0)))
                ? ['ok', 'Marked handled. · ह्यान्डल भयो भनेर राखियो।']
                : ['bad', 'That alert is already closed or not found. · यो alert पहिले नै बन्द छ।'];
        }
    }
}

// Routes and Stops Query
$routes = Database::fetchAll(
    "SELECT r.id, r.route_code, r.from_city, r.to_city, r.dep_time, b.bus_number AS default_bus
       FROM routes r LEFT JOIN buses b ON b.id = r.bus_id
      WHERE r.is_active = 1 ORDER BY r.sort_order, r.id"
);
$stopsByRoute = [];
if ($routes !== []) {
    $ph = []; $pp = [];
    foreach ($routes as $i => $r) { $ph[] = ':r' . $i; $pp['r' . $i] = (int) $r['id']; }
    foreach (Database::fetchAll(
        'SELECT route_id, stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, sort_order
           FROM route_stops WHERE route_id IN (' . implode(',', $ph) . ') AND latitude IS NOT NULL AND longitude IS NOT NULL
          ORDER BY route_id, stop_type, sort_order', $pp
    ) as $s) {
        $stopsByRoute[(int) $s['route_id']][] = [
            'type' => (string) $s['stop_type'], 'name' => (string) $s['stop_name'], 'landmark' => (string) ($s['landmark'] ?? ''),
            'time' => $s['stop_time'] !== null ? substr((string) $s['stop_time'], 0, 5) : '',
            'lat' => (float) $s['latitude'], 'lng' => (float) $s['longitude'], 'border' => (int) $s['is_border'] === 1,
        ];
    }
}

// Today's trips
$trips = Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.slot, s.status, s.is_blocked, s.total_seats, s.delay_minutes, s.dep_time_override,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.route_code, r.from_city, r.to_city, r.arr_time, r.day_offset,
            b.bus_number, b.bus_name, d.full_name AS driver_name, d.phone AS driver_phone,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS seats_booked
       FROM schedules s JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses b ON b.id = s.bus_id LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date IN (:d1, :d2) AND r.is_active = 1
      ORDER BY s.travel_date, COALESCE(s.dep_time_override, r.dep_time), s.slot",
    ['d1' => date('Y-m-d', strtotime('-1 day')), 'd2' => $today]
);
$trips = TripStatus::annotate($trips);
$jsTrips = array_map(static function (array $t): array {
    $st = (array) ($t['_status'] ?? []);
    return [
        'id' => (int) $t['id'], 'date' => (string) $t['travel_date'], 'slot' => (int) $t['slot'], 'code' => (string) $t['route_code'],
        'label' => (string) $t['from_city'] . ' → ' . (string) $t['to_city'], 'time' => substr((string) $t['dep_time'], 0, 5),
        'bus' => trim((string) ($t['bus_name'] ?? '') . ' ' . (string) ($t['bus_number'] ?? '')), 'driver' => (string) ($t['driver_name'] ?? ''),
        'driverPhone' => (string) ($t['driver_phone'] ?? ''), 'sold' => (int) $t['seats_booked'], 'total' => (int) $t['total_seats'],
        'state' => (string) ($st['label'] ?? ''), 'stateKey' => (string) ($st['state'] ?? ''), 'color' => (string) ($st['color'] ?? '#6b7688'),
        'blocked' => (int) $t['is_blocked'] === 1, 'cancelled' => (string) $t['status'] === 'cancelled', 'delay' => (int) $t['delay_minutes'],
    ];
}, $trips);

$jsRoutes = array_map(static fn(array $r): array => [
    'id' => (int) $r['id'], 'code' => (string) $r['route_code'], 'label' => (string) $r['from_city'] . ' → ' . (string) $r['to_city'],
    'time' => substr((string) $r['dep_time'], 0, 5), 'stops' => $stopsByRoute[(int) $r['id']] ?? [],
], $routes);

// Stored road lines
$roadInfo = [];
foreach ($jsRoutes as $i => $r) {
    $rg = RouteGeo::load($r['id']);
    $roadInfo[$r['id']] = RouteGeo::info($r['id']);
    $jsRoutes[$i]['road'] = $rg !== null ? $rg['polyline6'] : null;
}

// Passengers by pickup with live stop ETAs
$paxScope = Auth::bookingScopeAdminId();
$paxNames = Auth::can('bookings.view');
$routeOf  = [];
foreach ($trips as $raw) { $routeOf[(int) $raw['id']] = (int) $raw['route_id']; }
$etaCache = [];
$paxTrips = [];
foreach ($jsTrips as $t) {
    if ($t['cancelled'] || !($t['date'] === $today || in_array($t['stateKey'], ['departed', 'on_route', 'boarding', 'delayed'], true))) {
        continue;
    }
    $rows = Database::fetchAll(
        "SELECT b.pnr, b.contact_phone, l.boarding_stop, l.seat_count,
                (SELECT p.full_name FROM booking_passengers p WHERE p.booking_id = b.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS name
           FROM booking_legs l JOIN bookings b ON b.id = l.booking_id
          WHERE l.schedule_id = :s AND b.status IN ('confirmed','pending')"
        . ($paxScope !== null ? ' AND b.sold_by_admin_id = :a' : '') . "
          ORDER BY l.boarding_time, l.boarding_stop, b.id",
        ['s' => $t['id']] + ($paxScope !== null ? ['a' => $paxScope] : [])
    );
    if ($rows === []) {
        continue;
    }
    $rid = $routeOf[$t['id']] ?? 0;
    if (!isset($etaCache[$t['id']])) {
        [$tFix] = EtaAlerts::fixForTrip((int) $t['id'], $rid);
        $etaCache[$t['id']] = $tFix !== null ? EtaAlerts::stopEtas($rid, $tFix) : [];
    }
    $groups = [];
    foreach ($rows as $r) {
        $label = trim((string) ($r['boarding_stop'] ?? ''));
        $key   = $label !== '' ? Boarding::townKey($label) : '?';
        $groups[$key] ??= [
            'stop'  => $label !== '' ? Boarding::stopDisplay($label)['name'] : 'Pickup not set',
            'eta'   => $etaCache[$t['id']][$key]['eta'] ?? null,
            'seats' => 0,
            'pax'   => [],
        ];
        $groups[$key]['seats'] += (int) $r['seat_count'];
        $groups[$key]['pax'][]  = $r;
    }
    $paxTrips[] = ['trip' => $t, 'groups' => $groups];
}

// All Stops for Boarding Manager - only routes that sell (16 Nov 2026: nine
// "Mehsana — SHG Head Office" rows of switched-OFF routes were listed first,
// and editing one re-priced a route that never sells).
$allStops = Database::fetchAll(
    "SELECT rs.id, rs.route_id, rs.stop_type, rs.stop_name, rs.landmark, rs.stop_time, rs.fare, rs.is_border, rs.sort_order,
            r.route_code, r.from_city, r.to_city
       FROM route_stops rs
       JOIN routes r ON r.id = rs.route_id
      WHERE rs.is_active = 1 AND r.is_active = 1
      ORDER BY r.sort_order, rs.route_id, rs.stop_type, rs.sort_order"
);
$canEditStops = Auth::can('routes.edit');
/* 19 Nov 2026 (u8 AI-4): real times learned from GPS at night (cron/stop-timing.php).
   Suggestions only - "Use this time" posts the normal stop update above (routes.edit,
   audited map.stop_update); nothing changes a timetable by itself. */
require_once INCLUDE_PATH . '/stoptiming.php';
$stopLearned = StopTiming::learned();
/* Fix of 19 Nov (review): halts are learned too - show them read-only (a halt's
   time is edited on Admin → Routes → Halts, not here). */
$haltLearned = [];
foreach ($stopLearned as $lk => $lr) {
    if (str_starts_with((string) $lk, 'halt:')) {
        $haltLearned[(int) $lr['stop_ref']] = $lr;
    }
}
$learnedHalts = [];
if ($haltLearned !== []) {
    try {
        $learnedHalts = Database::fetchAll(
            'SELECT h.id, h.name, h.kind, h.clock_time, h.halt_min, h.km_from_origin, h.is_approx, r.route_code, r.from_city, r.to_city
               FROM route_halts h JOIN routes r ON r.id = h.route_id
              WHERE h.id IN (' . implode(',', array_map('intval', array_keys($haltLearned))) . ')
              ORDER BY r.sort_order, h.route_id, h.km_from_origin');
    } catch (Throwable $e) {
        $learnedHalts = [];   // halts table not installed
    }
}

// Today's Booked Passengers for Quick Radar Link Dispatch
$todayPaxBookings = Database::fetchAll(
    "SELECT b.pnr, b.contact_phone, b.total_amount, b.status, bl.travel_date, bl.boarding_stop,
            r.from_city, r.to_city, s.id AS schedule_id, bu.bus_number,
            (SELECT full_name FROM booking_passengers bp WHERE bp.booking_id = b.id LIMIT 1) AS pax_name,
            (SELECT GROUP_CONCAT(bs.seat_no) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats
       FROM bookings b
       JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       JOIN schedules s ON s.id = bl.schedule_id
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses bu ON bu.id = s.bus_id
      WHERE bl.travel_date = :d AND b.status IN ('confirmed', 'completed')
      ORDER BY s.id, b.id DESC LIMIT 40",
    ['d' => $today]
);

/* 15 Nov 2026 (fleet map upgrade): the count bar above the map, a Replay
   link for every departure that has a GPS trip, and ?trip=<live trip id> =
   that trip's trail with a time slider (admin/api/trip-trail.php). Each part
   degrades to nothing when the live-tracking tables are missing. */
$fleetBar = GpsFleet::statusCounts([], [], [], [], time());
try {
    $fleetBar = GpsFleet::statusBar();
} catch (Throwable $e) {
    Logger::warning('map: fleet count bar unavailable', ['e' => $e->getMessage()], 'nav');
}
$replayOf = [];
try {
    $replayOf = GpsFleet::liveTripIds(array_column($jsTrips, 'id'));
} catch (Throwable $e) {
    Logger::warning('map: replay links unavailable', ['e' => $e->getMessage()], 'nav');
}
$replayId = max(0, (int) ($_GET['trip'] ?? 0));
$replay   = null;
if ($replayId > 0) {
    try {
        $replay = GpsFleet::tripMeta($replayId);
    } catch (Throwable $e) {
        $replay = null;
    }
    if ($replay === null) {
        $flash = ['bad', 'No GPS record for that trip. · यो यात्राको GPS रेकर्ड छैन।'];
    }
}

$company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
$phone   = Settings::officePhone();

$mapTab = (string) ($_GET['tab'] ?? 'radar');
if (!in_array($mapTab, ['radar', 'boarding', 'status_dispatch'], true) || $replay !== null) {
    $mapTab = 'radar';
}

admin_header('SHARI NAV — Global Map & Live Bus Tracking', 'map');
?>
<?php /* 15 Nov 2026: MapLibre from our own server (assets/vendor, like assets/js/track-map.js); unpkg only if that fails. */ ?>
<link rel="stylesheet" href="/assets/vendor/maplibre-gl-4.7.1/maplibre-gl.css" onerror="this.onerror=null;this.href='https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css'">
<style>
/* Category Navigation Tabs */
.map-tabs { display:flex; gap:8px; margin-bottom:16px; overflow-x:auto; padding-bottom:4px; border-bottom:2px solid var(--line,#e2e8f0); }
.map-tab { padding:10px 16px; border-radius:8px; font-weight:700; font-size:13.5px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border:1px solid transparent; white-space:nowrap; }
.map-tab.active { background:linear-gradient(135deg,#0d3b66,#0077b6); color:#fff; box-shadow:0 3px 10px rgba(13,59,102,.25); }
.map-tab:not(.active) { background:var(--card,#fff); color:var(--ink,#1e293b); border-color:var(--line,#e2e8f0); }
.map-tab:not(.active):hover { background:var(--hover,#f1f5f9); }

/* Radar Layout (from GPSLIVE-20261025) */
.lm-wrap{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start}
#lmMap{height:calc(100vh - 210px);min-height:440px;border-radius:14px;border:1px solid var(--line);overflow:hidden;background:#0f1a2e;position:relative}
#lmMap .maplibregl-canvas{outline:none}
.lm-side{display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 210px);overflow:auto}
.lm-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;flex-shrink:0}
.lm-pax-trip{padding:10px 14px 4px;font-size:13px}
.lm-pax{border-top:1px solid var(--line)}
.lm-pax summary{display:flex;gap:8px;align-items:center;padding:10px 14px;cursor:pointer;min-height:46px;list-style:none;font-size:13.5px}
.lm-pax summary::-webkit-details-marker{display:none}
.lm-pax-stop{font-weight:700;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lm-pax-n{font-size:12px;font-weight:800;padding:3px 9px;border-radius:999px;background:rgba(46,95,168,.12);color:#2E5FA8;white-space:nowrap}
.lm-pax-eta{font-size:12px;font-weight:800;padding:3px 9px;border-radius:999px;background:#d7f4e3;color:#0a6b3b;white-space:nowrap}
.lm-pax-row{display:flex;gap:8px;align-items:center;padding:6px 14px 6px 34px;font-size:13.5px}
.lm-pax-row span{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
.lm-pax-row a{width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:16px;flex:0 0 auto}
.lm-call{background:rgba(46,95,168,.1)} .lm-wa{background:#DCF8E7}
.lm-card h3{margin:0;padding:11px 14px;font-size:14px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px}
.lm-card h3 small{margin-left:auto;font-weight:500;color:var(--mut);font-size:11.5px}
.lm-bus{padding:12px 14px;display:flex;flex-direction:column;gap:6px;font-size:13px}
.lm-bus .big{font-size:18px;font-weight:800}
.lm-bus .muted{font-size:12px}
.lm-trip{padding:10px 14px;border-bottom:1px solid var(--line);display:flex;flex-direction:column;gap:4px;cursor:pointer}
.lm-trip:last-child{border-bottom:0}
.lm-trip:hover{background:var(--hover)}
.lm-trip .t1{display:flex;gap:8px;align-items:center;font-weight:700}
.lm-trip .t1 .time{font-size:16px;font-variant-numeric:tabular-nums}
.lm-trip .meta{font-size:12px;color:var(--mut);display:flex;gap:8px;flex-wrap:wrap}
.lm-trip .acts{display:flex;gap:5px;flex-wrap:wrap}
.lm-trip .acts .btn{padding:5px 8px;font-size:12px}
.lm-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--mut);padding:8px 2px}
.lm-legend i{display:inline-block;width:12px;height:4px;border-radius:2px;vertical-align:middle;margin-right:4px}
.lm-pin{width:12px;height:12px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4)}
.lm-office{width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:linear-gradient(135deg,#F07C1F,#c95f0c);border:2px solid #fff;box-shadow:0 6px 16px rgba(0,0,0,.35)}
.lm-busm{font-size:26px;filter:drop-shadow(0 2px 5px rgba(0,0,0,.5));position:relative}
.lm-busm::before{content:'';position:absolute;left:50%;top:50%;width:34px;height:34px;border-radius:50%;transform:translate(-50%,-50%);background:rgba(255,122,26,.3);animation:lmRing 2s ease-out infinite;z-index:-1}
@keyframes lmRing{0%{transform:translate(-50%,-50%) scale(.6);opacity:.9}100%{transform:translate(-50%,-50%) scale(2.2);opacity:0}}
.lm-pop{font:13px/1.35 system-ui,sans-serif}
.lm-pop b{display:block;font-size:13.5px}
.lm-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.lm-top .spacer{flex:1}
.lm-trip .acts .btn{min-height:44px;display:inline-flex;align-items:center}
/* 15 Nov 2026: count bar, real-coach markers with a heading arrow, trip replay */
.fm-bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.fm-c{flex:1 1 150px;min-width:0;min-height:44px;display:flex;align-items:center;gap:8px;padding:6px 12px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:inherit;font:600 12.5px/1.25 system-ui,sans-serif;cursor:pointer;text-align:left}
.fm-c b{font-size:20px;font-variant-numeric:tabular-nums;min-width:22px}
.fm-c span{min-width:0;overflow-wrap:anywhere}
.fm-c:hover{background:var(--hover)}
.fm-c[aria-pressed="true"]{outline:3px solid #2E5FA8;outline-offset:1px}
.fm-c.ok b{color:#16a34a}.fm-c.warn b{color:#d97706}.fm-c.stop b{color:#ea580c}.fm-c.bad b{color:#dc2626}
.lm-trip.fm-hit{background:rgba(234,88,12,.12);box-shadow:inset 4px 0 0 #ea580c}
.lm-trip[hidden]{display:none}/* .lm-trip is display:flex, which beats the bare [hidden] attribute: the count-bar filter needs this */
/* the marker root: MapLibre keeps it position:absolute - never override that (it would stretch across the map) */
.lm-coach{display:inline-flex;align-items:center;gap:5px;padding:2px 8px 2px 2px;border-radius:20px;background:rgba(11,27,58,.92);border:2px solid #D4A843;box-shadow:0 4px 12px rgba(0,0,0,.4);color:#fff;font:700 11px system-ui,sans-serif;white-space:nowrap;cursor:pointer}
.lm-coach img{width:32px;height:32px;border-radius:50%;object-fit:cover;background:#fff;display:block}
.lm-coach span:empty{display:none}
/* centred on the photo (2px padding + 16px): lmRing animates translate(-50%,-50%) like .lm-busm, so the static rule needs it too (reduced motion) */
.lm-coach.live::before{content:'';position:absolute;left:18px;top:18px;width:32px;height:32px;border-radius:50%;background:rgba(255,122,26,.35);transform:translate(-50%,-50%);animation:lmRing 2s ease-out infinite;z-index:-1}
.lm-hd{position:absolute;left:-7px;top:-7px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;pointer-events:none}
.lm-hd[hidden]{display:none}
.lm-hd svg{width:13px;height:13px;display:block}.lm-hd path{fill:#2E5FA8}
.lm-end{width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.45)}
.rp-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:10px 12px;margin:0 0 10px;display:flex;flex-direction:column;gap:8px}
.rp-h{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:13.5px}
.rp-h b{flex:1 1 200px;min-width:0;overflow-wrap:anywhere}
.rp-h .btn{min-height:44px;display:inline-flex;align-items:center}
.rp-ctl{display:flex;gap:8px;align-items:center}
.rp-ctl input[type=range]{flex:1 1 auto;min-width:0;height:44px;margin:0;accent-color:#E8652E}
.rp-ctl .btn,.rp-ctl select{min-height:44px;min-width:44px;font-size:15px}
.rp-info{font-size:12.5px;color:var(--mut);display:flex;gap:10px;flex-wrap:wrap}
@media(prefers-reduced-motion:reduce){.lm-coach.live::before{animation:none}}
@media(max-width:520px){.fm-c{flex:1 1 calc(50% - 8px)}}
@media(max-width:1020px){.lm-wrap{grid-template-columns:1fr}#lmMap{height:58vh;min-height:360px}.lm-side{max-height:none}}
/* 16 Nov 2026 (BUG2): the Boarding tab's Add / Edit stop boxes had no styles on
   this page (the class lives inline in agents.php), so "+ Add New Boarding"
   opened the form in page flow under the whole stop table - off screen. */
.id-preview-modal{position:fixed;inset:0;z-index:1000;background:rgba(10,20,40,.6);display:flex;align-items:center;justify-content:center;padding:16px}
.id-preview-modal[hidden]{display:none}
.id-preview-box{background:var(--card,#fff);color:var(--ink,#1e293b);border-radius:14px;width:100%;padding:16px;max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 24px 60px rgba(0,0,0,.35)}
.id-preview-box input,.id-preview-box select{font-size:16px;background:var(--card,#fff);color:var(--ink,#1e293b);min-width:0}
@media(max-width:420px){.id-preview-modal{padding:8px}.id-preview-box{padding:12px}.id-preview-box div[style*="grid-template-columns"]{grid-template-columns:1fr !important}}
</style>

<!-- CATEGORY TABS -->
<div class="map-tabs">
  <a class="map-tab <?= $mapTab === 'radar' ? 'active' : '' ?>" href="map.php?tab=radar">
    🛰️ 1. SHARI NAV Live GPS Radar &amp; Fleet Devices
  </a>
  <a class="map-tab <?= $mapTab === 'boarding' ? 'active' : '' ?>" href="map.php?tab=boarding">
    📍 2. Boarding Points &amp; Fares Manager
  </a>
  <a class="map-tab <?= $mapTab === 'status_dispatch' ? 'active' : '' ?>" href="map.php?tab=status_dispatch">
    ⚡ 3. Live Status Dispatcher &amp; Passenger WhatsApp Share
  </a>
</div>

<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>" style="margin-bottom:14px"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<?php
// Open driver SOS / breakdown (driver app buttons, 15 Nov 2026). Office-only close.
require_once INCLUDE_PATH . '/tripevents.php';
$openDriverAlerts = [];
try { $openDriverAlerts = TripEvents::openAlerts(20); } catch (Throwable) { $openDriverAlerts = []; }
?>
<?php if ($openDriverAlerts !== []): ?>
  <div class="card" style="border:2px solid #dc2626;margin-bottom:14px;padding:12px 14px">
    <div style="font-weight:800;color:#b91c1c;margin-bottom:8px">🚨 Driver alert — खुला SOS / Breakdown (<?= count($openDriverAlerts) ?>)</div>
    <?php foreach ($openDriverAlerts as $ev):
        $isSos = ($ev['kind'] ?? '') === 'sos';
        $lat = $ev['lat'] !== null ? (float) $ev['lat'] : null;
        $lng = $ev['lng'] !== null ? (float) $ev['lng'] : null;
        $pin = TripEvents::placeLink($lat, $lng);
    ?>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:8px 0;border-top:1px solid #fecaca">
        <div style="min-width:0;flex:1 1 220px">
          <b><?= $isSos ? 'SOS' : 'Bus bigriyo / Breakdown' ?></b>
          · <?= Security::e((string) ($ev['bus_label'] ?? '')) ?>
          · <?= Security::e(date('d M H:i', strtotime((string) $ev['created_at']) ?: time())) ?>
          <?php if (!empty($ev['driver_name'])): ?><br><?= Security::e((string) $ev['driver_name']) ?><?php endif; ?>
          <?php if (!empty($ev['driver_phone'])): ?> · <a href="tel:<?= Security::e((string) $ev['driver_phone']) ?>"><?= Security::e((string) $ev['driver_phone']) ?></a><?php endif; ?>
          <?php if (!empty($ev['note'])): ?><br><span style="color:#6b7280"><?= Security::e((string) $ev['note']) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($pin !== ''): ?><a class="btn" style="min-height:44px;display:inline-flex;align-items:center" href="<?= Security::e($pin) ?>" target="_blank" rel="noopener">📍 Map</a><?php endif; ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Mark this alert handled? · यो alert ह्यान्डल भयो?')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="resolve_driver_event">
            <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
            <button class="btn" type="submit" style="min-height:44px">✅ Handled</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- =====================================================================
     TAB 1: SHARI NAV LIVE GPS RADAR (YESTERDAY'S COMPLETE GPS SYSTEM)
     ===================================================================== -->
<?php if ($mapTab === 'radar'): ?>

<!-- GPSLIVE-20261025 -->
<div class="lm-top">
  <a class="btn ghost" href="<?= $base ?>/admin/gps-trackers.php">🛰️ Configure bus GPS</a>
  <a class="btn ghost" href="<?= $base ?>/admin/gps-center.php">🧭 GPS Control Center</a>
  <a class="btn ghost" href="/navigate.html" target="_blank" rel="noopener">🚌 S Hari Live Map (Passenger View)</a>
  <span class="muted" style="font-size:12.5px">GPS devices + driver phones · live speed and last update · refreshes every 10 s</span>
  <span class="spacer"></span>
  <button class="btn ghost" type="button" id="lmFollow">🎯 Follow bus</button>
  <button class="btn ghost" type="button" id="lmFit">🗺️ Fit routes</button>
  <a class="btn ghost" href="<?= $base ?>/admin/calendar.php">📅 Bus Calendar</a>
</div>

<?php
$fmChips = [
    'running'    => ['', 'Running · चलिरहेको', 'Buses the driver app or a bus GPS is running now'],
    'gpsOk'      => ['ok', 'GPS ok', 'Running, with a GPS fix in the last 10 minutes'],
    'silent'     => ['warn', 'GPS silent 10 min+ · GPS आएन', 'Running, but no GPS fix for more than 10 minutes'],
    'stopped'    => ['stop', 'Stopped 15 min+ · रोकिएको', 'GPS ok, but the bus has stayed within 200 m for 15 minutes'],
    'notStarted' => ['bad', 'Not started · सुरु भएन', 'Today\'s departures past their time with no GPS trip started'],
];
?>
<div class="fm-bar" id="fmBar" role="group" aria-label="Fleet status · बसको अवस्था">
  <?php foreach ($fmChips as $key => [$tone, $label, $help]): ?>
    <button type="button" class="fm-c <?= $tone ?>" data-f="<?= $key ?>" aria-pressed="false" title="<?= Security::e($help) ?>">
      <b id="fmN-<?= $key ?>"><?= (int) ($fleetBar['counts'][$key] ?? 0) ?></b><span><?= Security::e($label) ?></span>
    </button>
  <?php endforeach; ?>
</div>

<div class="lm-wrap">
  <div>
    <?php if ($replay !== null): ?>
    <div class="rp-card" id="rpCard">
      <div class="rp-h">
        <b>▶ Replay · <?= Security::e($replay['bus'] !== '' ? $replay['bus'] : 'Bus') ?> · <?= Security::e($replay['route']) ?></b>
        <span class="muted"><?= $replay['date'] !== '' ? Security::e(formatDate($replay['date'])) . ' · ' : '' ?><?= Security::e($replay['dep']) ?></span>
        <a class="btn ghost" href="<?= $base ?>/admin/map.php">✕ Close · बन्द</a>
      </div>
      <div class="rp-ctl">
        <button type="button" class="btn ok" id="rpPlay" aria-label="Play replay" disabled>▶</button>
        <input type="range" id="rpSlider" min="0" max="0" value="0" step="1" aria-label="Replay time" disabled>
        <select id="rpSpeed" aria-label="Replay speed">
          <option value="60">60×</option><option value="300" selected>300×</option><option value="1200">1200×</option>
        </select>
      </div>
      <div class="rp-info" id="rpInfo" aria-live="polite">Loading the GPS trail… · GPS रेखा लोड हुँदैछ…</div>
    </div>
    <?php endif; ?>
    <div id="lmMap"><div id="lmLoad" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px">Loading map…</div></div>
    <div class="lm-legend">
      <span><i style="background:#2E5FA8"></i> <?= Security::e($routes[0]['from_city'] ?? 'Outbound') ?> → <?= Security::e($routes[0]['to_city'] ?? '') ?></span>
      <span><i style="background:#0a6b3b"></i> return</span>
      <span>● stop · coach photo = live bus, arrow = heading · 🏢 head office (Mehsana)</span>
      <?php if ($replay !== null): ?><span><i style="background:#E8652E"></i> replay trail · <span style="color:#0a6b3b">●</span> start · <span style="color:#8a1f1f">●</span> end</span><?php endif; ?>
    </div>
  </div>

  <aside class="lm-side">
    <!-- CARD 1: GPS HARDWARE DEVICES -->
    <div class="lm-card">
      <h3>🛰️ Bus GPS devices <small id="gpsFleetAge">Checking…</small></h3>
      <div id="gpsFleetStatus" aria-live="polite" style="padding:10px 14px;font-size:13px">Connecting to GPS devices…</div>
      <button class="btn ghost" type="button" id="gpsFleetRefresh" style="margin:10px">Refresh status</button>
      <p class="muted" style="padding:0 14px 10px;font-size:12px;margin:0">Device positions include parked buses. Grey markers are last known positions. Public map positions require an active trip.</p>
    </div>

    <!-- CARD 2: DRIVER APP ON THE ROAD -->
    <div class="lm-card">
      <h3>🛰️ Buses on the road · driver app <small id="lmFleetAge">…</small></h3>
      <div id="lmFleet">
        <div class="muted" style="padding:10px 14px">No driver has started a trip in the driver app. Give a driver a PIN in <a href="<?= $base ?>/admin/drivers.php">Drivers</a>, then they open <b><?= Security::e(appUrl('driver/')) ?></b> on their phone and tap START TRIP.</div>
      </div>
    </div>

    <!-- CARD 3: SHARED LIVE BUS (NAVIGATOR) -->
    <div class="lm-card">
      <h3>🚌 Live bus <small id="lmBusAge">no position yet</small></h3>
      <div class="lm-bus" id="lmBus">
        <span class="muted">Waiting for the driver's phone. A signed-in driver taps "📡 I'm the driver" in the navigator; the office can also set a stop by hand from the app.</span>
      </div>
    </div>

    <!-- CARD 4: TODAY'S DEPARTURES -->
    <div class="lm-card">
      <h3>🕐 Today's departures <small><?= Security::e(formatDate($today)) ?></small></h3>
      <div id="lmTrips">
        <?php $shown = 0; foreach ($jsTrips as $t): if ($t['date'] !== $today && !in_array($t['stateKey'], ['departed', 'on_route', 'boarding', 'delayed'], true)) { continue; } $shown++; ?>
        <div class="lm-trip" data-id="<?= $t['id'] ?>" data-code="<?= Security::e($t['code']) ?>">
          <div class="t1">
            <span class="time"><?= Security::e($t['time']) ?></span>
            <span><?= Security::e($t['label']) ?></span>
            <?= $t['slot'] > 1 ? '<span class="pill" style="background:#efeaff;color:#5a3fb0">Bus ' . $t['slot'] . '</span>' : '' ?>
            <span class="pill" style="margin-left:auto;background:<?= Security::e($t['color']) ?>;color:#fff;border:1px solid <?= Security::e($t['color']) ?>"><?= Security::e($t['state'] ?: 'Scheduled') ?></span>
          </div>
          <div class="meta">
            <?= $t['date'] !== $today ? '<span>📅 ' . Security::e(formatDate($t['date'])) . '</span>' : '' ?>
            <span>🚌 <?= Security::e($t['bus'] ?: 'no vehicle') ?></span>
            <?= $t['driver'] !== '' ? '<span>👤 ' . Security::e($t['driver']) . ($t['driverPhone'] !== '' ? ' · <a href="tel:' . Security::e($t['driverPhone']) . '">' . Security::e($t['driverPhone']) . '</a>' : '') . '</span>' : '' ?>
            <span>🎟 <?= $t['sold'] ?>/<?= $t['total'] ?></span>
            <?= $t['delay'] > 0 ? '<span>⏱ +' . $t['delay'] . ' min</span>' : '' ?>
            <?= $t['blocked'] ? '<span style="color:#8a1f1f">🚫 OFF</span>' : '' ?>
            <?= $t['cancelled'] ? '<span style="color:#8a1f1f">❌ cancelled</span>' : '' ?>
          </div>
          <div class="acts">
            <a class="btn ghost" href="<?= $base ?>/admin/seatmap.php?sid=<?= $t['id'] ?>">🪑 Seats</a>
            <a class="btn ghost" href="<?= $base ?>/admin/manifest.php?sid=<?= $t['id'] ?>&amp;date=<?= Security::e($t['date']) ?>">📋 Manifest</a>
            <a class="btn ghost" href="<?= $base ?>/admin/trip-dashboard.php?sid=<?= $t['id'] ?>">📊 Trip</a>
            <a class="btn ghost" href="<?= $base ?>/admin/chalan.php?sid=<?= $t['id'] ?>">🧾 Chalan</a>
            <?php if (isset($replayOf[$t['id']])): ?><a class="btn ghost" href="<?= $base ?>/admin/map.php?trip=<?= (int) $replayOf[$t['id']] ?>">▶ Replay</a><?php endif; ?>
          </div>
        </div>
        <?php endforeach; if ($shown === 0): ?><div class="muted" style="padding:14px">No departures today.</div><?php endif; ?>
      </div>
    </div>

    <!-- CARD 5: ROAD LINE (VALHALLA POLYLINE6) -->
    <div class="lm-card">
      <h3>🛣️ Road line <small>for the customer map + ETA</small></h3>
      <?php if ($geoFlash !== null): ?><div class="flash <?= Security::e($geoFlash[0]) ?>" style="margin:10px 14px"><?= Security::e($geoFlash[1]) ?></div><?php endif; ?>
      <?php foreach ($routes as $r): $gi = $roadInfo[(int) $r['id']] ?? null; $missing = EtaAlerts::stopLine((int) $r['id'])['missing']; ?>
        <div class="lm-trip" style="cursor:default">
          <div class="t1">
            <span><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?></span>
            <span class="pill" style="margin-left:auto"><?= $gi !== null ? 'v' . (int) $gi['version'] . ' · ' . number_format($gi['length_m'] / 1000, 0) . ' km' : 'not built' ?></span>
          </div>
          <div class="meta">
            <?= $gi !== null ? '<span>built ' . Security::e(date('j M H:i', (int) strtotime($gi['built_at']))) . '</span><span>' . Security::e($gi['source']) . '</span>' : '<span>The map and ETA use the straight line of stops.</span>' ?>
            <?= $missing !== [] ? '<span style="color:#9a5b00">No coordinates: ' . Security::e(implode(', ', $missing)) . '</span>' : '' ?>
          </div>
          <?php if (Auth::isSuperadmin()): ?>
            <form method="post" class="acts" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Building… (up to a minute)'">
              <?= Security::csrfField() ?><input type="hidden" name="action" value="build_road"><input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
              <button class="btn ghost" type="submit">🛣️ <?= $gi !== null ? 'Rebuild' : 'Build' ?> road line</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="muted" style="padding:8px 14px 12px;font-size:12px">Built by the route engine through the stops that have coordinates, in travel order. A pickup without coordinates is never guessed - add it in Routes, then rebuild.</div>
    </div>

    <!-- CARD 6: PASSENGERS BY PICKUP WITH LIVE ETA MINUTES -->
    <div class="lm-card">
      <h3>👥 <?= $paxScope !== null ? 'My passengers' : 'Passengers' ?> · यात्रु <small>by pickup · चढ्ने ठाउँ अनुसार</small></h3>
      <?php if ($paxTrips === []): ?>
        <div class="muted" style="padding:10px 14px"><?= $paxScope !== null ? 'You have no passengers on today&#39;s buses. · आजको बसमा तपाईंको यात्रु छैन।' : 'No passengers on today&#39;s buses yet.' ?></div>
      <?php endif; ?>
      <?php foreach ($paxTrips as $pt): $t = $pt['trip']; ?>
        <div class="lm-pax-trip"><b><?= Security::e($t['time']) ?> · <?= Security::e($t['label']) ?></b></div>
        <?php foreach ($pt['groups'] as $g): ?>
          <details class="lm-pax">
            <summary>
              <span class="lm-pax-stop">📍 <?= Security::e($g['stop']) ?></span>
              <span class="lm-pax-n"><?= (int) $g['seats'] ?> seat<?= (int) $g['seats'] === 1 ? '' : 's' ?></span>
              <?php if ($g['eta'] !== null): ?><span class="lm-pax-eta">🚌 ~<?= (int) (ceil($g['eta'] / 5) * 5) ?> min</span><?php endif; ?>
            </summary>
            <?php if ($paxNames): foreach ($g['pax'] as $p):
              $tel = (string) preg_replace('/\D/', '', (string) $p['contact_phone']);
              $wa  = ($tel !== '' && preg_match('/^0+$/', $tel) !== 1) ? (strlen($tel) === 10 ? '91' . $tel : $tel) : ''; ?>
              <div class="lm-pax-row">
                <span><?= Security::e((string) ($p['name'] ?: '—')) ?> <small class="muted"><?= Security::e((string) $p['pnr']) ?></small></span>
                <?php if ($wa !== ''): ?>
                  <a class="lm-call" href="tel:+<?= Security::e($wa) ?>" aria-label="Call">📞</a>
                  <a class="lm-wa" href="https://wa.me/<?= Security::e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp">💬</a>
                <?php endif; ?>
              </div>
            <?php endforeach; endif; ?>
          </details>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </aside>
</div>

<script>
window.ADMAP = {
  routes: <?= json_encode($jsRoutes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  office: { lat: 23.588, lng: 72.369, name: <?= json_encode($company, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, phone: <?= json_encode($phone, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
  base: <?= json_encode($base) ?>,
  fleetUrl: <?= json_encode($base . '/admin/api/live-fleet.php') ?>,
  statusUrl: <?= json_encode($base . '/admin/api/fleet-status.php') ?>,
  trailUrl: <?= json_encode($base . '/admin/api/trip-trail.php') ?>,
  replay: <?= $replay !== null ? (int) $replay['id'] : 'null' ?>,
  bar: <?= json_encode($fleetBar, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  coach: '/assets/img/bus-shg-sm.webp'
};
(function () {
  var A = window.ADMAP, map = null, busMarker = null, follow = false, lastAt = 0, busDisp = null, busTarget = null, anim = null, busBrg = null;
  /* 15 Nov 2026: MapLibre from our own server first (assets/vendor, as assets/js/track-map.js), unpkg only if that fails;
     the OpenFreeMap style is fetched first and a plain OpenStreetMap raster map is drawn when it cannot be read. */
  var MLG = '4.7.1', OWN = '/assets/vendor/maplibre-gl-' + MLG + '/maplibre-gl.js', CDN = 'https://unpkg.com/maplibre-gl@' + MLG + '/dist/maplibre-gl.js';
  var STYLE = 'https://tiles.openfreemap.org/styles/liberty';
  var OSM = { version: 8, sources: { osm: { type: 'raster', tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256, maxzoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' } },
    layers: [{ id: 'osm', type: 'raster', source: 'osm' }] };
  var HD = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5l5.2 12.8L8 11.2l-5.2 3.1z"/></svg>';
  var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function loadLib(){
    return new Promise(function(res, rej){
      if (window.maplibregl) return res();
      function add(src, last){
        var s = document.createElement('script'); s.src = src;
        s.onload = function(){ if (window.maplibregl) res(); else if (!last) add(CDN, true); else rej(); };
        s.onerror = function(){ if (!last) add(CDN, true); else rej(); };
        document.head.appendChild(s);
      }
      add(OWN, false);
    });
  }
  function pickStyle(url){
    return new Promise(function(res){
      var done = false, ctl = window.AbortController ? new AbortController() : null;
      function fin(s){ if (done) return; done = true; clearTimeout(tm); res(s); }
      var tm = setTimeout(function(){ if (ctl) ctl.abort(); fin(OSM); }, 8000);
      fetch(url, { credentials: 'omit', signal: ctl ? ctl.signal : undefined }).then(function(r){ if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function(j){ fin(j && j.version === 8 && j.sources && j.layers ? j : OSM); }).catch(function(){ fin(OSM); });
    });
  }
  /* The real coach photo (owner rule: realistic only) + a small arrow badge turned to the heading. */
  function coachEl(){
    var el = document.createElement('div'); el.className = 'lm-coach';
    el.innerHTML = '<img alt="" width="32" height="32" decoding="async"><i class="lm-hd" hidden>' + HD + '</i><span></span>';
    el.querySelector('img').src = A.coach;
    return el;
  }
  function setHd(el, brg){
    var i = el.querySelector('.lm-hd');
    if (!i) return;
    if (brg == null || !isFinite(brg)) { i.hidden = true; i.removeAttribute('data-brg'); return; }
    i.hidden = false; i.setAttribute('data-brg', String(brg));
    i.style.transform = 'rotate(' + Math.round(brg - (map ? map.getBearing() : 0)) + 'deg)';
  }
  function reHd(){ document.querySelectorAll('#lmMap .lm-hd[data-brg]').forEach(function(i){ i.style.transform = 'rotate(' + Math.round(+i.getAttribute('data-brg') - map.getBearing()) + 'deg)'; }); }
  /* Heading from two fixes ({lng,lat}); null under 25 m (GPS jitter, or standing still). */
  function brgOf(a, b){
    if (!a || !b) return null;
    var R = Math.PI / 180, p1 = a.lat * R, p2 = b.lat * R, dl = (b.lng - a.lng) * R;
    var h = Math.pow(Math.sin((p2 - p1) / 2), 2) + Math.cos(p1) * Math.cos(p2) * Math.pow(Math.sin(dl / 2), 2);
    if (2 * 6371008.8 * Math.asin(Math.min(1, Math.sqrt(h))) < 25) return null;
    var y = Math.sin(dl) * Math.cos(p2), x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
    return (Math.round(Math.atan2(y, x) / R) % 360 + 360) % 360;
  }
  /* A reported heading, unless the bus is crawling (phones report noise then) and we already have one. */
  function nextBrg(prev, reported, kmh, moved){
    if (reported != null && isFinite(reported) && (kmh == null || kmh >= 3 || prev == null)) return (Math.round(+reported) % 360 + 360) % 360;
    return moved != null ? moved : prev;
  }
  function decode6(s){
    var pts = [], i = 0, lat = 0, lng = 0, n = s.length;
    while (i < n) { for (var k = 0; k < 2; k++) { var sh = 0, res = 0, b; do { b = s.charCodeAt(i++) - 63; res |= (b & 31) << sh; sh += 5; } while (b >= 32 && i < n); var v = (res & 1) ? ~(res >> 1) : (res >> 1); if (k === 0) lat += v; else lng += v; } pts.push([lng / 1e6, lat / 1e6]); }
    return pts;
  }
  function bounds(){
    var b = null;
    A.routes.forEach(function(r){ r.stops.forEach(function(s){ if (!b) b = new maplibregl.LngLatBounds([s.lng, s.lat], [s.lng, s.lat]); else b.extend([s.lng, s.lat]); }); });
    if (b) b.extend([A.office.lng, A.office.lat]);
    return b;
  }
  function draw(){
    A.routes.forEach(function(r, i){
      var pts = r.stops.filter(function(s){ return s.type === 'boarding'; }).concat(r.stops.filter(function(s){ return s.type === 'drop'; }));
      var coords = r.road ? decode6(r.road) : pts.map(function(s){ return [s.lng, s.lat]; });
      if (coords.length < 2) return;
      var color = i === 0 ? '#2E5FA8' : '#0a6b3b';
      map.addSource('r' + r.id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } } });
      map.addLayer({ id: 'r' + r.id + '-glow', type: 'line', source: 'r' + r.id, layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': color, 'line-width': 9, 'line-opacity': .18, 'line-blur': 2 } });
      map.addLayer({ id: 'r' + r.id + '-line', type: 'line', source: 'r' + r.id, layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': color, 'line-width': 3.2, 'line-dasharray': i === 0 ? [1, 0] : [2, 1.5] } });
      pts.forEach(function(s){
        var el = document.createElement('div'); el.className = 'lm-pin'; el.style.background = s.border ? '#F07C1F' : color; el.title = s.name;
        var pop = new maplibregl.Popup({ offset: 10, closeButton: false }).setHTML('<div class="lm-pop"><b>' + esc(s.name) + '</b>' + (s.landmark ? esc(s.landmark) + '<br>' : '') + esc(r.label) + (s.time ? ' · ' + esc(s.time) : '') + (s.border ? ' · 🛃 border' : '') + '</div>');
        new maplibregl.Marker({ element: el }).setLngLat([s.lng, s.lat]).setPopup(pop).addTo(map);
      });
    });
    var oel = document.createElement('div'); oel.className = 'lm-office'; oel.title = A.office.name;
    new maplibregl.Marker({ element: oel, anchor: 'bottom' }).setLngLat([A.office.lng, A.office.lat])
      .setPopup(new maplibregl.Popup({ offset: 34, closeButton: false }).setHTML('<div class="lm-pop"><b>🏢 ' + esc(A.office.name) + '</b>Head office · Mehsana<br>📞 ' + esc(A.office.phone) + '</div>')).addTo(map);
  }
  function chase(now){
    anim = null;
    if (!busMarker || !busTarget) return;
    if (!busDisp) busDisp = { lng: busTarget.lng, lat: busTarget.lat };
    var k = 0.12, dx = busTarget.lng - busDisp.lng, dy = busTarget.lat - busDisp.lat;
    busDisp = { lng: busDisp.lng + dx * k, lat: busDisp.lat + dy * k };
    busMarker.setLngLat([busDisp.lng, busDisp.lat]);
    if (follow) { var c = map.getCenter(); map.jumpTo({ center: [c.lng + (busDisp.lng - c.lng) * 0.1, c.lat + (busDisp.lat - c.lat) * 0.1] }); }
    if (Math.abs(dx) > 1e-7 || Math.abs(dy) > 1e-7) anim = requestAnimationFrame(chase);
  }
  function showBus(lb){
    var box = document.getElementById('lmBus'), age = document.getElementById('lmBusAge');
    if (!lb || !lb.at) { age.textContent = 'no position yet'; return; }
    var mins = Math.max(0, Math.round((Date.now() - lb.at) / 60000));
    var agoTxt = mins < 1 ? 'just now' : (mins < 60 ? mins + ' min ago' : Math.round(mins / 60) + ' h ago');
    var live = !!lb.gps && lb.lat != null && lb.lng != null && (Date.now() - lb.at) < 3 * 60000;
    age.textContent = (live ? '🛰️ live · ' : '') + agoTxt;
    box.innerHTML = '<div class="big">' + esc(lb.stop || '—') + (live ? ' 🛰️' : '') + '</div>'
      + (lb.next ? '<div>Next: <b>' + esc(lb.next) + '</b></div>' : '')
      + '<div class="muted">' + (lb.gps ? 'GPS ' + (lb.lat != null ? (+lb.lat).toFixed(4) + ', ' + (+lb.lng).toFixed(4) : '') + (lb.spd != null ? ' · ' + Math.round(lb.spd * 3.6) + ' km/h' : '') + (lb.acc != null ? ' · ±' + Math.round(lb.acc) + ' m' : '') : 'set by hand from the office') + (lb.by ? ' · by ' + esc(lb.by) : '') + '</div>'
      + (lb.lat != null ? '<div><a class="btn ghost" style="padding:5px 9px;font-size:12px" target="_blank" rel="noopener" href="https://www.google.com/maps?q=' + (+lb.lat) + ',' + (+lb.lng) + '">🗺️ Open in Google Maps</a></div>' : '');
    if (lb.gps && lb.lat != null && map && lb.at !== lastAt) {
      lastAt = lb.at;
      var to = { lng: +lb.lng, lat: +lb.lat };
      if (!busMarker) {
        busMarker = new maplibregl.Marker({ element: coachEl(), anchor: 'left', offset: [-19, 0] }).setLngLat([to.lng, to.lat]).addTo(map);
        busDisp = { lng: to.lng, lat: to.lat };
        if (!A.replay) map.flyTo({ center: [to.lng, to.lat], zoom: Math.max(map.getZoom(), 10), duration: 1500 });   // a replay keeps its own trail in view
      }
      var rep = lb.brg != null ? lb.brg : (lb.hd != null ? lb.hd : null);
      busBrg = nextBrg(busBrg, rep, lb.spd != null ? lb.spd * 3.6 : null, brgOf(busTarget, to));
      setHd(busMarker.getElement(), busBrg);
      busMarker.getElement().classList.toggle('live', live);
      busTarget = to;
      if (!anim) anim = requestAnimationFrame(chase);
    }
  }
  function poll(){
    if (document.hidden) return;
    fetch(A.base + '/api/kv.php?action=get&key=livebus', { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
      var v = (d && d.data && d.data.value !== undefined) ? d.data.value : (d && d.value !== undefined ? d.value : (d && d.data));
      if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { v = null; } }
      showBus(v);
    }).catch(function(){});
  }
  var fleet = {}, filt = '';
  function glide(f){
    f.anim = null;
    var k = 0.12, dx = f.to.lng - f.at.lng, dy = f.to.lat - f.at.lat;
    f.at = { lng: f.at.lng + dx * k, lat: f.at.lat + dy * k };
    f.marker.setLngLat([f.at.lng, f.at.lat]);
    if (Math.abs(dx) > 1e-7 || Math.abs(dy) > 1e-7) f.anim = requestAnimationFrame(function(){ glide(f); });
  }
  function ago(s){ if (s == null) return 'no fix yet'; var m = Math.round(s / 60); return s < 60 ? s + ' s ago' : (m < 60 ? m + ' min ago' : Math.round(m / 60) + ' h ago'); }
  /* ---- the count bar: tap a count to show only those buses (tap again = all) ---- */
  function inSet(k, id){ var s = A.bar && A.bar.sets && A.bar.sets[k]; return !!s && s.indexOf(+id) !== -1; }
  function applyFilter(){
    Object.keys(fleet).forEach(function(id){ fleet[id].el.style.display = !filt || (filt !== 'notStarted' && inSet(filt, id)) ? '' : 'none'; });
    document.querySelectorAll('#lmFleet [data-trip]').forEach(function(r){ r.hidden = !!filt && filt !== 'notStarted' && !inSet(filt, r.getAttribute('data-trip')); });
    var first = null;
    document.querySelectorAll('#lmTrips .lm-trip[data-id]').forEach(function(r){
      var hit = filt === 'notStarted' && inSet('notStarted', r.getAttribute('data-id'));
      r.classList.toggle('fm-hit', hit);
      if (hit && !first) first = r;
    });
    document.querySelectorAll('#fmBar [data-f]').forEach(function(b){ b.setAttribute('aria-pressed', b.getAttribute('data-f') === filt ? 'true' : 'false'); });
    return first;
  }
  function paintBar(b){
    if (!b || !b.counts || !b.sets) return;
    A.bar = b;
    Object.keys(b.counts).forEach(function(k){ var n = document.getElementById('fmN-' + k); if (n) n.textContent = b.counts[k]; });
    applyFilter();
  }
  document.querySelectorAll('#fmBar [data-f]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var f = btn.getAttribute('data-f');
      filt = filt === f ? '' : f;
      var first = applyFilter();
      if (first) first.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
      if (!map || !filt || filt === 'notStarted') return;
      var b = null;
      Object.keys(fleet).forEach(function(id){ if (inSet(filt, id)) { var p = fleet[id].to; if (!b) b = new maplibregl.LngLatBounds([p.lng, p.lat], [p.lng, p.lat]); else b.extend([p.lng, p.lat]); } });
      if (b) map.fitBounds(b, { padding: 60, maxZoom: 12, duration: reduce ? 0 : 800 });
    });
  });
  function pollBar(){
    if (document.hidden) return;
    fetch(A.statusUrl, { credentials: 'same-origin', cache: 'no-store' }).then(function(r){ return r.json(); }).then(function(d){
      if (d && d.ok) paintBar(d.data);
    }).catch(function(){});
  }
  function showFleet(list){
    var box = document.getElementById('lmFleet'), seen = {};
    document.getElementById('lmFleetAge').textContent = list.length ? list.length + ' running' : 'none';
    window.SHG_GPS_RUNNING_PLATES = {};
    list.forEach(function (t) { if (t.live) window.SHG_GPS_RUNNING_PLATES[t.bus] = true; });
    box.innerHTML = list.map(function(t){
      return '<div class="lm-trip" data-trip="' + (+t.tripId) + '"><div class="t1"><span>' + (t.live ? '🟢' : '⚪') + '</span><span>' + esc(t.route) + '</span>'
        + '<span class="pill" style="margin-left:auto">' + esc(t.bus || t.routeCode) + '</span></div>'
        + '<div class="meta"><span>🕐 ' + esc(t.dep) + ' · ' + esc(t.date) + '</span>' + (t.driver ? '<span>👤 ' + esc(t.driver) + (t.driverPhone ? ' · <a href="tel:' + esc(t.driverPhone) + '">' + esc(t.driverPhone) + '</a>' : '') + '</span>' : '')
        + '<span>🎟 ' + (+t.pax) + '</span>' + (t.kmh != null && t.live ? '<span>' + (+t.kmh) + ' km/h</span>' : '') + '<span>' + (t.live ? '🛰️ ' : '⏸ last seen ') + esc(ago(t.ageSec)) + '</span>'
        + (t.acc != null ? '<span>±' + (+t.acc) + ' m</span>' : '') + '</div>'
        + '<div class="acts"><a class="btn ghost" href="' + esc(A.base) + '/admin/map.php?trip=' + (+t.tripId) + '">▶ Replay</a></div></div>';
    }).join('');
    box.querySelectorAll('[data-trip]').forEach(function(el){ el.addEventListener('click', function(e){ if (e.target.closest('a')) return; var f = fleet[el.getAttribute('data-trip')]; if (f && map) map.easeTo({ center: [f.to.lng, f.to.lat], zoom: Math.max(map.getZoom(), 12), duration: 900 }); }); });
    if (!map) { applyFilter(); return; }
    list.forEach(function(t){
      if (t.lat == null || t.lng == null) return;
      seen[t.tripId] = true;
      var f = fleet[t.tripId], to = { lng: +t.lng, lat: +t.lat };
      if (!f) {
        var el = coachEl();
        f = fleet[t.tripId] = { el: el, at: { lng: to.lng, lat: to.lat }, to: { lng: to.lng, lat: to.lat }, anim: null, brg: null };
        f.marker = new maplibregl.Marker({ element: el, anchor: 'left', offset: [-19, 0] }).setLngLat([to.lng, to.lat]).addTo(map);
      }
      f.brg = nextBrg(f.brg, t.bearing, t.kmh, brgOf(f.to, to));
      setHd(f.el, f.brg);
      f.el.querySelector('span').textContent = (t.bus || t.routeCode) + (t.live && t.kmh != null ? ' · ' + t.kmh + ' km/h' : (t.live ? '' : ' · ' + ago(t.ageSec)));
      f.el.classList.toggle('live', !!t.live);
      f.el.style.opacity = t.live ? '1' : '.55';
      f.to = to;
      if (!f.anim) f.anim = requestAnimationFrame(function(){ glide(f); });
    });
    Object.keys(fleet).forEach(function(id){ if (!seen[id]) { if (fleet[id].anim) cancelAnimationFrame(fleet[id].anim); fleet[id].marker.remove(); delete fleet[id]; } });
    applyFilter();
  }
  function pollFleet(){
    if (document.hidden) return;
    fetch(A.fleetUrl, { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
      if (d && d.ok && d.data) showFleet(d.data.trips || []);
    }).catch(function(){});
  }
  /* ---- trip replay: admin/map.php?trip=<live trip id> ---- */
  var RP = null;
  function fmtT(ts){
    try { return new Date(ts * 1000).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }); }
    catch (e) { return new Date(ts * 1000).toLocaleString(); }
  }
  function rpAt(t){
    var p = RP.pts, lo = 0, hi = p.length - 1;
    if (t <= p[0][2]) return { ll: [p[0][0], p[0][1]], i: 0 };
    if (t >= p[hi][2]) return { ll: [p[hi][0], p[hi][1]], i: hi };
    while (hi - lo > 1) { var mid = (lo + hi) >> 1; if (p[mid][2] <= t) lo = mid; else hi = mid; }
    var span = p[hi][2] - p[lo][2], k = span > 0 ? (t - p[lo][2]) / span : 0;
    return { ll: [p[lo][0] + (p[hi][0] - p[lo][0]) * k, p[lo][1] + (p[hi][1] - p[lo][1]) * k], i: lo };
  }
  function rpBrg(i){
    var p = RP.pts;
    for (var j = i + 1; j < p.length && j <= i + 6; j++) { var b = brgOf({ lng: p[i][0], lat: p[i][1] }, { lng: p[j][0], lat: p[j][1] }); if (b != null) return nextBrg(RP.brg, p[i][4], p[i][3], b); }
    return nextBrg(RP.brg, p[i][4], p[i][3], null);
  }
  function rpShow(t){
    RP.t = t;
    var a = rpAt(t), p = RP.pts[a.i];
    RP.marker.setLngLat(a.ll);
    RP.slider.value = String(Math.round(t - RP.t0));
    if (a.i === RP.idx && RP.playing) return;
    RP.idx = a.i;
    RP.brg = rpBrg(a.i); setHd(RP.el, RP.brg);
    RP.el.querySelector('span').textContent = fmtT(p[2]).split(', ').pop();
    RP.info.innerHTML = '<span>🕐 <b>' + esc(fmtT(p[2])) + '</b></span>' + (p[3] != null ? '<span>' + Math.round(p[3]) + ' km/h</span>' : '')
      + '<span>' + (a.i + 1) + ' / ' + RP.pts.length + ' GPS points' + (RP.total > RP.pts.length ? ' (thinned from ' + RP.total + ')' : '') + '</span>';
  }
  function rpStop(){
    RP.playing = false; RP.last = 0;
    if (RP.raf) cancelAnimationFrame(RP.raf);
    RP.raf = 0; RP.play.textContent = '▶'; RP.play.setAttribute('aria-label', 'Play replay');
  }
  function rpTick(now){
    if (!RP.playing) return;
    var dt = RP.last ? Math.min(0.25, (now - RP.last) / 1000) : 0; RP.last = now;
    var t = RP.t + dt * (+RP.speed.value || 300), end = t >= RP.t1;
    rpShow(end ? RP.t1 : t);
    if (end) { rpStop(); RP.idx = -1; rpShow(RP.t1); } else RP.raf = requestAnimationFrame(rpTick);
  }
  function rpPlay(){
    if (RP.t >= RP.t1) RP.t = RP.t0;
    RP.playing = true; RP.last = 0; RP.play.textContent = '⏸'; RP.play.setAttribute('aria-label', 'Pause replay');
    RP.raf = requestAnimationFrame(rpTick);
  }
  function loadReplay(){
    var info = document.getElementById('rpInfo');
    if (!info) return;
    fetch(A.trailUrl + '?trip=' + encodeURIComponent(A.replay), { credentials: 'same-origin', cache: 'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok || !d.data) throw new Error(d && d.error ? d.error : '');
        var pts = d.data.points || [];
        if (!pts.length) { info.textContent = 'This trip has no GPS points yet. · यो यात्राको GPS बिन्दु छैन।'; return; }
        RP = { pts: pts, total: +d.data.total || pts.length, t0: pts[0][2], t1: pts[pts.length - 1][2], t: pts[0][2], brg: null, idx: -1,
               playing: false, last: 0, raf: 0, info: info, slider: document.getElementById('rpSlider'),
               play: document.getElementById('rpPlay'), speed: document.getElementById('rpSpeed') };
        var coords = pts.map(function(p){ return [p[0], p[1]]; });
        if (coords.length > 1) {
          map.addSource('rp', { type: 'geojson', data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } } });
          map.addLayer({ id: 'rp-case', type: 'line', source: 'rp', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 7, 'line-opacity': 0.85 } });
          map.addLayer({ id: 'rp-line', type: 'line', source: 'rp', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#E8652E', 'line-width': 4 } });
        }
        function end(ll, color, label){
          var e = document.createElement('div'); e.className = 'lm-end'; e.style.background = color; e.title = label;
          new maplibregl.Marker({ element: e }).setLngLat(ll).setPopup(new maplibregl.Popup({ offset: 10, closeButton: false }).setText(label)).addTo(map);
        }
        end(coords[0], '#0a6b3b', 'Start · सुरु · ' + fmtT(RP.t0));
        if (coords.length > 1) end(coords[coords.length - 1], '#8a1f1f', 'End · अन्त्य · ' + fmtT(RP.t1));
        RP.el = coachEl(); RP.el.classList.add('live');
        RP.marker = new maplibregl.Marker({ element: RP.el, anchor: 'left', offset: [-19, 0] }).setLngLat(coords[0]).addTo(map);
        var span = Math.max(0, RP.t1 - RP.t0);
        RP.slider.max = String(span); RP.slider.disabled = span === 0; RP.play.disabled = span === 0;
        RP.slider.addEventListener('input', function(){ if (RP.playing) rpStop(); rpShow(RP.t0 + (+RP.slider.value)); });
        RP.play.addEventListener('click', function(){ if (RP.playing) rpStop(); else rpPlay(); });
        rpShow(RP.t0);
        var b = new maplibregl.LngLatBounds(coords[0], coords[0]);
        coords.forEach(function(c){ b.extend(c); });
        map.fitBounds(b, { padding: 48, maxZoom: 14, duration: 0 });
      })
      .catch(function(e){ info.textContent = e && e.message ? e.message : 'Could not load this trip\'s GPS trail. · GPS रेखा खुलेन।'; });
  }
  document.getElementById('lmFollow').onclick = function(){ follow = !follow; this.classList.toggle('on', follow); this.textContent = follow ? '🎯 Following bus' : '🎯 Follow bus'; if (follow && busTarget && map) map.easeTo({ center: [busTarget.lng, busTarget.lat], zoom: Math.max(map.getZoom(), 12), duration: 900 }); };
  document.getElementById('lmFit').onclick = function(){ if (!map) return; var b = bounds(); if (b) map.fitBounds(b, { padding: 48, duration: 900 }); };
  document.querySelectorAll('.lm-trip').forEach(function(el){ el.addEventListener('click', function(e){ if (e.target.closest('a')) return; var r = A.routes.find(function(x){ return x.code === el.dataset.code; }); if (!r || !r.stops.length || !map) return; var b = null; r.stops.forEach(function(s){ if (!b) b = new maplibregl.LngLatBounds([s.lng, s.lat], [s.lng, s.lat]); else b.extend([s.lng, s.lat]); }); map.fitBounds(b, { padding: 48, duration: 900 }); }); });
  paintBar(A.bar);
  pollFleet(); setInterval(pollFleet, 10000);
  setInterval(pollBar, 30000);
  Promise.all([loadLib(), pickStyle(STYLE)]).then(function(res){
    try {
      map = new maplibregl.Map({ container: 'lmMap', style: res[1], center: [76.8, 25.6], zoom: 5, fadeDuration: 250, maxBounds: [[61.0, 4.5], [99.5, 38.5]], minZoom: 3.8,
        refreshExpiredTiles: false, attributionControl: { compact: true } });
    } catch (e) { document.getElementById('lmLoad').textContent = 'This browser cannot draw the map (no WebGL).'; return; }
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
    map.on('dragstart', function(){ if (follow) document.getElementById('lmFollow').click(); });
    map.on('rotate', reHd);
    map.on('error', function(){});   // a missing tile is not a broken page
    map.on('load', function(){
      var l = document.getElementById('lmLoad'); if (l) l.remove();
      draw();
      window.SHG_GPS_MAP = map; document.dispatchEvent(new Event('shg:admin-map-ready'));
      var b = bounds(); if (b && !A.replay) map.fitBounds(b, { padding: 48, duration: 0 });
      if (A.replay) loadReplay();
      poll(); setInterval(poll, 15000);
      pollFleet();
    });
  }).catch(function(){ document.getElementById('lmLoad').textContent = 'Map library could not be loaded — check the connection.'; });
})();
</script>
<script src="/assets/nav/gps-admin.js?v=20261025gps2" defer></script>

<!-- =====================================================================
     TAB 2: BOARDING & DROP POINTS MANAGER
     ===================================================================== -->
<?php elseif ($mapTab === 'boarding'): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
  <div style="font-size:13.5px;color:var(--mut)">
    Manage Gujarat &amp; Nepal pickup stops, timings, fare adjustments, and customs border checkpoints.
  </div>
  <?php if ($canEditStops): ?>
  <button type="button" class="btn ok" onclick="document.getElementById('addStopModal').hidden=false">
    + नयाँ पिकअप / ड्रप · Add pickup / drop point
  </button>
  <?php endif; ?>
</div>
<div class="dt-wrap">
  <table class="dt no-card">
    <thead><tr>
      <th>#</th><th>Route</th><th>Stop Type</th><th>Boarding / Drop Point</th><th>Departure Time</th><th>Standard Fare</th><th>Landmark</th><th>Border Check</th><th>Action</th>
    </tr></thead>
    <tbody>
      <?php foreach ($allStops as $st): ?>
        <tr>
          <td class="num"><b>#<?= (int)$st['id'] ?></b></td>
          <td><b><?= Security::e($st['route_code']) ?></b> <span class="muted" style="font-size:11px">(<?= Security::e($st['from_city'] . ' → ' . $st['to_city']) ?>)</span></td>
          <td><span class="pill" style="background:<?= $st['stop_type'] === 'boarding' ? '#d7f4e3;color:#0a6b3b' : '#fee2e2;color:#991b1b' ?>"><?= ucfirst((string)$st['stop_type']) ?></span></td>
          <td><b><?= Security::e($st['stop_name']) ?></b></td>
          <td class="mono"><b><?= $st['stop_time'] ? substr((string)$st['stop_time'], 0, 5) : '<span class="muted">—</span>' ?></b>
            <?php $lr = $stopLearned['stop:' . (int) $st['id']] ?? null; if ($lr !== null && $lr['suggested_time'] !== null): $sug = substr((string) $lr['suggested_time'], 0, 5); ?>
              <div class="muted" style="font-size:11.5px;font-family:inherit;white-space:normal" data-learned="<?= (int) $st['id'] ?>">
                GPS ≈ <?= Security::e($sug) ?><?= (int) $lr['day_offset'] > 0 ? ' (+' . (int) $lr['day_offset'] . ' दिन · day)' : '' ?>
                · <?= (int) $lr['samples'] ?> trips · p80 <?= (int) $lr['p80_offset_min'] - (int) $lr['median_offset_min'] >= 0 ? '+' : '' ?><?= (int) $lr['p80_offset_min'] - (int) $lr['median_offset_min'] ?> min
                <?php if ($lr['median_dwell_min'] !== null): ?> · रोकिने · stop <?= (int) $lr['median_dwell_min'] ?> min<?php endif; ?>
              </div>
              <?php if ($canEditStops && $sug !== substr((string) ($st['stop_time'] ?? ''), 0, 5)): ?>
              <form method="post" style="margin-top:4px" onsubmit="return confirm('<?= Security::e(addslashes($st['stop_name'])) ?>: समय <?= Security::e($sug) ?> राख्ने? Set the time to <?= Security::e($sug) ?>?')">
                <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_stop">
                <input type="hidden" name="stop_id" value="<?= (int) $st['id'] ?>"><input type="hidden" name="stop_time" value="<?= Security::e($sug) ?>">
                <input type="hidden" name="fare" value="<?= (float) ($st['fare'] ?? 0) > 0 ? Security::e((string) (float) $st['fare']) : '' ?>">
                <input type="hidden" name="landmark" value="<?= Security::e((string) ($st['landmark'] ?? '')) ?>">
                <button type="submit" class="btn ghost" style="font-size:11.5px;padding:3px 8px">यो समय राख्नुहोस् · Use this time</button>
              </form>
              <?php endif; ?>
            <?php endif; ?></td>
          <td class="num"><b><?= (float)$st['fare'] > 0 ? '₹' . number_format((float)$st['fare']) : '<span class="muted">Default</span>' ?></b></td>
          <td><?= !empty($st['landmark']) ? Security::e($st['landmark']) : '<span class="muted">—</span>' ?></td>
          <td><?= (int)$st['is_border'] === 1 ? '<span class="pill" style="background:#fef3c7;color:#92400e">🛃 Border Point</span>' : '<span class="muted">—</span>' ?></td>
          <td>
            <?php if ($canEditStops): ?>
            <button type="button" class="btn ghost" onclick="openStopModal(<?= (int)$st['id'] ?>, '<?= Security::e(addslashes($st['stop_name'])) ?>', '<?= substr((string)($st['stop_time'] ?? ''), 0, 5) ?>', <?= (float)($st['fare'] ?? 0) ?>, '<?= Security::e(addslashes($st['landmark'] ?? '')) ?>')">✏️ Edit</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($learnedHalts !== []): ?>
<h3 style="font-size:14.5px;margin:18px 0 8px">☕ बाटोका रोकाइ · Halts on the way — GPS timings</h3>
<div class="muted" style="font-size:12px;margin-bottom:8px">Learned at night from the GPS trail. Halt km are typed by hand (≈), so these times are approximate. To change a halt: Admin → Routes → Halts on the way.</div>
<div class="dt-wrap">
  <table class="dt no-card">
    <thead><tr><th>Route</th><th>Halt</th><th>km</th><th>Timetable</th><th>GPS ≈</th><th>Trips</th><th>Stop (min)</th></tr></thead>
    <tbody>
      <?php foreach ($learnedHalts as $h): $lr = $haltLearned[(int) $h['id']]; ?>
        <tr data-learned-halt="<?= (int) $h['id'] ?>">
          <td><b><?= Security::e($h['route_code']) ?></b> <span class="muted" style="font-size:11px">(<?= Security::e($h['from_city'] . ' → ' . $h['to_city']) ?>)</span></td>
          <td><b><?= Security::e($h['name']) ?></b> <span class="muted" style="font-size:11px"><?= Security::e((string) $h['kind']) ?></span></td>
          <td class="num"><?= $h['km_from_origin'] !== null ? ((int) $h['is_approx'] === 1 ? '≈ ' : '') . Security::e((string) (float) $h['km_from_origin']) : '<span class="muted">—</span>' ?></td>
          <td class="mono"><?= $h['clock_time'] !== null ? Security::e(substr((string) $h['clock_time'], 0, 5)) : '<span class="muted">—</span>' ?></td>
          <td class="mono"><b><?= $lr['suggested_time'] !== null ? Security::e(substr((string) $lr['suggested_time'], 0, 5)) : '—' ?></b><?= (int) $lr['day_offset'] > 0 ? ' <span class="muted">(+' . (int) $lr['day_offset'] . ' day)</span>' : '' ?>
            <span class="muted" style="font-size:11px"> · p80 <?= (int) $lr['p80_offset_min'] - (int) $lr['median_offset_min'] >= 0 ? '+' : '' ?><?= (int) $lr['p80_offset_min'] - (int) $lr['median_offset_min'] ?> min</span></td>
          <td class="num"><?= (int) $lr['samples'] ?></td>
          <td class="num"><?= $lr['median_dwell_min'] !== null ? (int) $lr['median_dwell_min'] : '<span class="muted">—</span>' ?><span class="muted" style="font-size:11px"> / <?= (int) $h['halt_min'] ?> planned</span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($canEditStops): /* 16 Nov 2026: the edit / add forms only for routes.edit (the handlers refuse the rest) */ ?>
<!-- STOP EDIT MODAL -->
<div class="id-preview-modal" id="stopEditModal" hidden onclick="if(event.target===this)this.hidden=true">
  <div class="id-preview-box" style="max-width:440px">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:14px">
      <h3 style="margin:0;font-size:16px">📍 ठाउँ बदल्नुहोस् · Edit boarding / drop point</h3>
      <button type="button" class="btn ghost" onclick="document.getElementById('stopEditModal').hidden=true">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_stop"><input type="hidden" name="stop_id" id="editStopId">
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <div>Stop: <b id="editStopName"></b></div>
        <label>Scheduled Departure Time (HH:MM)
          <input type="time" name="stop_time" id="editStopTime" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <label>Fare from / to this point (₹)
          <input type="number" step="1" min="0" max="100000" inputmode="numeric" name="fare" id="editStopFare" placeholder="blank / 0 = default fare" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <label>Landmark / Meeting Spot
          <input type="text" name="landmark" id="editStopLandmark" placeholder="e.g. Near Char Rasta Petrol Pump" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px">
          <button type="button" class="btn ghost" onclick="document.getElementById('stopEditModal').hidden=true">Cancel</button>
          <button type="submit" class="btn ok">💾 Save Stop Details</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ADD NEW STOP MODAL -->
<div class="id-preview-modal" id="addStopModal" hidden onclick="if(event.target===this)this.hidden=true">
  <div class="id-preview-box" style="max-width:480px">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:14px">
      <h3 style="margin:0;font-size:16px">📍 नयाँ पिकअप / ड्रप · Add pickup / drop point</h3>
      <button type="button" class="btn ghost" onclick="document.getElementById('addStopModal').hidden=true">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_stop">
      <div style="display:flex;flex-direction:column;gap:11px;font-size:13px">
        <label>Route
          <select name="route_id" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
            <?php foreach ($routes as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= Security::e($r['route_code'] . ' (' . $r['from_city'] . ' → ' . $r['to_city'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:8px">
          <label>Stop / Station Name *
            <input type="text" name="stop_name" required placeholder="e.g. Anand Toll Plaza" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          </label>
          <label>Stop Type
            <select name="stop_type" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
              <option value="boarding">पिकअप · Boarding</option>
              <option value="drop">ड्रप · Drop off</option>
            </select>
            <small class="muted" style="display:block;margin-top:3px">Tea / meal halt: Admin → Routes → Halts on the way</small>
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label>Scheduled Time (HH:MM)
            <input type="time" name="stop_time" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          </label>
          <label>Special Fare (₹)
            <input type="number" step="1" min="0" max="100000" inputmode="numeric" name="fare" placeholder="blank = default" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          </label>
        </div>
        <label>Landmark / Specific Pickup Point
          <input type="text" name="landmark" placeholder="e.g. Under flyover bridge opposite hotel" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label>Latitude (Optional GPS)
            <input type="number" step="any" min="-90" max="90" inputmode="decimal" name="latitude" placeholder="e.g. 22.5645" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          </label>
          <label>Longitude (Optional GPS)
            <input type="number" step="any" min="-180" max="180" inputmode="decimal" name="longitude" placeholder="e.g. 72.9289" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          </label>
        </div>
        <label style="display:flex;align-items:center;gap:8px;margin-top:4px;cursor:pointer">
          <input type="checkbox" name="is_border" value="1" style="width:16px;height:16px">
          <span>This is an official India-Nepal customs / border checkpoint</span>
        </label>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
          <button type="button" class="btn ghost" onclick="document.getElementById('addStopModal').hidden=true">Cancel</button>
          <button type="submit" class="btn ok">✓ थप्नुहोस् · Add point</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function openStopModal(id, name, time, fare, landmark) {
  document.getElementById('editStopId').value = id;
  document.getElementById('editStopName').textContent = name;
  document.getElementById('editStopTime').value = time || '';
  document.getElementById('editStopFare').value = fare || '';
  document.getElementById('editStopLandmark').value = landmark || '';
  document.getElementById('stopEditModal').hidden = false;
}
</script>
<?php endif; ?>

<!-- =====================================================================
     TAB 3: LIVE STATUS DISPATCHER & PASSENGER WHATSAPP SHARE
     ===================================================================== -->
<?php elseif ($mapTab === 'status_dispatch'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
  
  <!-- QUICK STATUS UPDATE CARD -->
  <div style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:18px">
    <h3 style="margin:0 0 14px;font-size:16px;color:#0d3b66;display:flex;align-items:center;gap:8px">📡 Quick Status Dispatcher</h3>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_live_status">
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <label>Select Today's Active Bus / Trip
          <select name="schedule_id" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
            <?php foreach ($jsTrips as $jt): ?>
              <option value="<?= (int)$jt['id'] ?>"><?= Security::e($jt['label']) ?> (<?= Security::e($jt['bus']) ?>) &bull; <?= Security::e($jt['time']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <div style="font-weight:700;color:#334155;margin-top:4px">1-Tap Status Dispatch:</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <button type="submit" name="live_state" value="on_time" class="btn ok" style="padding:10px;font-size:12px">🟢 On Time (समयमै)</button>
          <button type="submit" name="live_state" value="border_crossed" class="btn" style="background:#0d3b66;color:#fff;padding:10px;font-size:12px">🛃 Crossed Border</button>
          <button type="submit" name="live_state" value="at_station" class="btn ghost" style="padding:10px;font-size:12px">📍 At Boarding Station</button>
          <button type="submit" name="live_state" value="delayed" class="btn danger" style="padding:10px;font-size:12px">⏱ Delayed (ढिलाइ)</button>
        </div>

        <label>Current Stop / Location Milestone
          <input type="text" name="current_stop" placeholder="e.g. Surat Kamrej Boarding or Crossed Rupaidiha Checkpoint" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <label>Delay in Minutes (if delayed)
          <input type="number" name="delay_minutes" value="0" min="0" step="15" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <label>Status Announcement Note
          <input type="text" name="status_note" placeholder="e.g. Heavy traffic near Godhra, tea halt for 20 mins" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
        </label>
        <button type="submit" class="btn ok" style="margin-top:6px;padding:10px;font-weight:700">✓ Broadcast Live Status</button>
      </div>
    </form>
  </div>

  <!-- PASSENGER WHATSAPP SHARE GENERATOR -->
  <div style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:18px">
    <h3 style="margin:0 0 14px;font-size:16px;color:#0d3b66;display:flex;align-items:center;gap:8px">📲 1-Tap Passenger WhatsApp Share</h3>
    <p style="font-size:13px;color:var(--mut);margin-top:0">Send real-time SHARI NAV live bus tracking and border checklist link to passengers via WhatsApp with 1 tap.</p>

    <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
      <label>Passenger Mobile (10 digits)
        <input type="tel" id="waPaxPhone" placeholder="e.g. 9812345678" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
      </label>
      <label>Select Active Trip
        <select id="waTripSel" onchange="updateWaMsg()" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
          <?php foreach ($jsTrips as $jt): ?>
            <option value="<?= Security::e($jt['label']) ?>" data-bus="<?= Security::e($jt['bus']) ?>" data-time="<?= Security::e($jt['time']) ?>" data-state="<?= Security::e($jt['state']) ?>">
              <?= Security::e($jt['label']) ?> (<?= Security::e($jt['bus']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Generated WhatsApp Message Preview:
        <textarea id="waPreviewText" rows="6" readonly style="width:100%;padding:8px;border-radius:6px;border:1px solid var(--line);background:#f8fafc;font-family:monospace;font-size:12px"></textarea>
      </label>

      <a id="btnSendWaLive" href="#" target="_blank" class="btn ok" style="padding:10px;text-align:center;font-weight:700;text-decoration:none;background:#25d366;color:#fff">
        💬 Open WhatsApp &amp; Send Live Tracker
      </a>
    </div>
  </div>

</div>

<!-- TODAY'S BOOKED PASSENGERS LIST -->
<div style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:18px;margin-top:16px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <h3 style="margin:0;font-size:16px;color:#0d3b66;display:flex;align-items:center;gap:8px">
      👥 Today's Booked Passengers (1-Tap WhatsApp Radar Dispatch)
    </h3>
    <span class="muted" style="font-size:12.5px"><?= count($todayPaxBookings) ?> Passenger(s) Booked Today</span>
  </div>
  <?php if ($todayPaxBookings === []): ?>
    <p class="muted" style="margin:0;font-size:13px">No passengers booked for today's outbound trips yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="dt" style="width:100%;font-size:12.5px">
        <thead><tr>
          <th>PNR</th><th>Passenger Name</th><th>Mobile</th><th>Journey</th><th>Boarding Point</th><th>Seats</th><th>1-Tap Radar WA</th>
        </tr></thead>
        <tbody>
          <?php foreach ($todayPaxBookings as $pb): 
            $pDigits = preg_replace('/\D/', '', (string)$pb['contact_phone']);
            if (strlen($pDigits) === 10) { $pDigits = '91' . $pDigits; }
            $pWaText = "Namaste " . ($pb['pax_name'] ?: 'Passenger') . "! S Hari Global Pvt Ltd Live Bus Tracking:\n"
                     . "PNR: " . $pb['pnr'] . " (Seats: " . ($pb['seats'] ?: '—') . ")\n"
                     . "Trip: " . $pb['from_city'] . " -> " . $pb['to_city'] . "\n"
                     . "Boarding Point: " . ($pb['boarding_stop'] ?: 'Main Station') . "\n"
                     . "Track Live Radar: https://www.shreehariglobal.in/#/tracking\n"
                     . "Please carry Aadhaar / Nepal Nagrikta ID for Rupaidiha customs border.\n"
                     . "Shubha Yatra!";
            $pWaLink = "https://wa.me/" . $pDigits . "?text=" . urlencode($pWaText);
          ?>
            <tr>
              <td class="mono"><b><a href="/admin/booking-view.php?pnr=<?= urlencode((string)$pb['pnr']) ?>"><?= Security::e($pb['pnr']) ?></a></b></td>
              <td><b><?= Security::e($pb['pax_name'] ?: 'Passenger') ?></b></td>
              <td class="mono"><?= Security::e($pb['contact_phone']) ?></td>
              <td><?= Security::e($pb['from_city'] . ' → ' . $pb['to_city']) ?></td>
              <td><span class="pill" style="background:#e0f2fe;color:#0369a1"><?= Security::e($pb['boarding_stop'] ?: 'Main Station') ?></span></td>
              <td class="mono"><b><?= Security::e($pb['seats'] ?: '—') ?></b></td>
              <td>
                <a href="<?= $pWaLink ?>" target="_blank" rel="noopener" class="btn" style="background:#25d366;color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;text-decoration:none;font-weight:700">📲 Send Radar Link</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
function updateWaMsg() {
  var sel = document.getElementById('waTripSel');
  var opt = sel.options[sel.selectedIndex];
  var trip = opt ? opt.value : 'Gujarat <-> Nepal Direct';
  var bus = opt ? opt.getAttribute('data-bus') : 'S Hari AC Sleeper';
  var time = opt ? opt.getAttribute('data-time') : '';
  var state = opt ? opt.getAttribute('data-state') : 'Running On Time';
  var phone = (document.getElementById('waPaxPhone').value || '').replace(/\D/g, '');
  if (phone.length === 10) phone = '91' + phone;

  var msg = "Namaste! S Hari Global Pvt Ltd (SHARI NAV Live Tracking):\n"
          + "Trip: " + trip + "\n"
          + "Bus: " + bus + (time ? " (" + time + ")" : "") + "\n"
          + "Status: " + state + "\n"
          + "Live GPS Map Link: https://www.shreehariglobal.in/#/tracking\n"
          + "Border Note: Please carry original Aadhaar / Nepal Nagrikta ID.\n"
          + "Shubha Yatra!";

  document.getElementById('waPreviewText').value = msg;
  document.getElementById('btnSendWaLive').href = 'https://wa.me/' + (phone || '') + '?text=' + encodeURIComponent(msg);
}
document.getElementById('waPaxPhone').addEventListener('input', updateWaMsg);
updateWaMsg();
</script>
<?php endif; ?>

<?php admin_footer(); ?>
