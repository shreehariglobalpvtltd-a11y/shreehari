<?php
/**
 * admin/map.php — Live Map (5 Sep 2026).
 *
 * The office's own map: both routes drawn from the route_stops the customer
 * app and the boarding cut-off already use, every stop as a pin, the
 * Mehsana head office, today's departures with their live state, and the
 * driver's phone position (the shared `livebus` key a signed-in driver
 * publishes from the navigator) refreshed every 15 s. Read-only — nothing
 * here writes. MapLibre is loaded from the CDN only on this page.
 *
 * Permission: schedules.view (every staff role that sees trips).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
$admin = admin_boot('schedules.view');
$base  = '';
$today = todayISO();

/* 🛣️ Road line (20 Oct 2026, live tracking phase 2): the owner builds each
   route's real road line once (includes/routegeo.php - Valhalla through the
   stops that HAVE coordinates). The customer track map draws it and the
   "bus is near" engine measures km along it. Owner only: it changes what
   every passenger's ETA is measured on. */
require_once INCLUDE_PATH . '/routegeo.php';
$geoFlash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'build_road') {
    if (!Auth::isSuperadmin()) {
        $geoFlash = ['bad', 'Only the owner can build a road line.'];
    } elseif (!Security::verifyCsrf()) {
        $geoFlash = ['bad', 'Session expired - open the page again.'];
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
}

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
/* The stored road line per route (null = the straight line of stops is drawn). */
$roadInfo = [];
foreach ($jsRoutes as $i => $r) {
    $rg = RouteGeo::load($r['id']);
    $roadInfo[$r['id']] = RouteGeo::info($r['id']);
    $jsRoutes[$i]['road'] = $rg !== null ? $rg['polyline6'] : null;
}
/* 👥 Passengers by pickup (19 Sep 2026, owner: "agent mode in the map").
   Who boards where on today's buses, with call / WhatsApp, and - when the
   driver's phone is publishing - roughly how many minutes the bus is from each
   pickup (EtaAlerts::stopEtas: silent when the fix is stale or off-route).
   A counter agent sees ONLY the passengers they sold (bookingScopeAdminId);
   names and phones need bookings.view, otherwise only the counts show. */
require_once INCLUDE_PATH . '/etaalerts.php';
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
    /* 20 Oct 2026: per coach - its own driver-app fix first (fixForTrip). */
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

$company = Settings::getString('company_name', 'S Hari Global Pvt Ltd');
$phone   = Settings::officePhone();

admin_header('Live Map', 'map');
?>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css">
<style>
.lm-wrap{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start}
#lmMap{height:calc(100vh - 170px);min-height:420px;border-radius:14px;border:1px solid var(--line);overflow:hidden;background:#0f1a2e;position:relative}
#lmMap .maplibregl-canvas{outline:none}
.lm-side{display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 170px);overflow:auto}
.lm-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;flex-shrink:0}
.lm-pax-trip{padding:10px 14px 4px;font-size:13px}
.lm-pax{border-top:1px solid var(--line)}
.lm-pax summary{display:flex;gap:8px;align-items:center;padding:10px 14px;cursor:pointer;min-height:46px;list-style:none;font-size:13.5px}
.lm-pax summary::-webkit-details-marker{display:none}
.lm-pax-stop{font-weight:700;flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lm-pax-n{font-size:12px;font-weight:800;padding:3px 9px;border-radius:999px;background:var(--blue-50);color:var(--blue-600);white-space:nowrap}
.lm-pax-eta{font-size:12px;font-weight:800;padding:3px 9px;border-radius:999px;background:var(--ok-bg);color:var(--ok);white-space:nowrap}
.lm-pax-row{display:flex;gap:8px;align-items:center;padding:6px 14px 6px 34px;font-size:13.5px}
.lm-pax-row span{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
.lm-pax-row a{width:40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:18px;flex:0 0 auto}
.lm-call{background:var(--blue-50)} .lm-wa{background:#DCF8E7}
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
.lm-top{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 10px}
.lm-top .spacer{flex:1}
@media(max-width:1020px){.lm-wrap{grid-template-columns:1fr}#lmMap{height:58vh;min-height:360px}.lm-side{max-height:none}}
</style>

<!-- GPSLIVE-20261025 -->
<div class="lm-top">
  <a class="btn ghost" href="<?= $base ?>/admin/gps-trackers.php">🛰️ Configure bus GPS</a>
  <a class="btn ghost" href="/navigate.html" target="_blank" rel="noopener">🚌 S Hari Live Map</a>
  <span class="muted" style="font-size:12.5px">GPS devices + driver phones · live speed and last update · refreshes every 10 s</span>
  <span class="spacer"></span>
  <button class="btn ghost" type="button" id="lmFollow">🎯 Follow bus</button>
  <button class="btn ghost" type="button" id="lmFit">🗺️ Fit routes</button>
  <a class="btn ghost" href="<?= $base ?>/admin/calendar.php">📅 Bus Calendar</a>
</div>
<div class="lm-wrap">
  <div>
    <div id="lmMap"><div id="lmLoad" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px">Loading map…</div></div>
    <div class="lm-legend"><span><i style="background:#2E5FA8"></i> <?= Security::e($routes[0]['from_city'] ?? 'Outbound') ?> → <?= Security::e($routes[0]['to_city'] ?? '') ?></span><span><i style="background:#0a6b3b"></i> return</span><span>● stop · 🚌 live bus · 🏢 head office (Mehsana)</span></div>
  </div>
  <aside class="lm-side">
    <div class="lm-card"><h3>🛰️ Bus GPS devices <small id="gpsFleetAge">Checking…</small></h3>
      <div id="gpsFleetStatus" aria-live="polite">Connecting to GPS devices…</div>
      <button class="btn ghost" type="button" id="gpsFleetRefresh" style="margin:10px">Refresh status</button>
      <p class="muted" style="padding:0 14px;font-size:12px">Device positions include parked buses. Grey markers are last known positions. Public map positions require an active trip.</p>
    </div>
    <div class="lm-card"><h3>🛰️ Buses on the road · driver app <small id="lmFleetAge">…</small></h3>
      <div id="lmFleet"><div class="muted" style="padding:10px 14px">No driver has started a trip in the driver app. Give a driver a PIN in <a href="<?= $base ?>/admin/drivers.php">Drivers</a>, then they open <b><?= Security::e(appUrl('driver/')) ?></b> on their phone and tap START TRIP.</div></div>
    </div>
    <div class="lm-card"><h3>🚌 Live bus <small id="lmBusAge">no position yet</small></h3>
      <div class="lm-bus" id="lmBus"><span class="muted">Waiting for the driver's phone. A signed-in driver taps "📡 I'm the driver" in the navigator; the office can also set a stop by hand from the app.</span></div>
    </div>
    <div class="lm-card"><h3>🕐 Today's departures <small><?= Security::e(formatDate($today)) ?></small></h3>
      <div id="lmTrips">
        <?php $shown = 0; foreach ($jsTrips as $t): if ($t['date'] !== $today && !in_array($t['stateKey'], ['departed', 'on_route', 'boarding', 'delayed'], true)) { continue; } $shown++; ?>
        <div class="lm-trip" data-id="<?= $t['id'] ?>" data-code="<?= Security::e($t['code']) ?>">
          <div class="t1"><span class="time"><?= Security::e($t['time']) ?></span><span><?= Security::e($t['label']) ?></span><?= $t['slot'] > 1 ? '<span class="pill" style="background:#efeaff;color:#5a3fb0">Bus ' . $t['slot'] . '</span>' : '' ?>
            <span class="pill" style="margin-left:auto;background:<?= Security::e($t['color']) ?>;color:#fff;border:1px solid <?= Security::e($t['color']) ?>"><?= Security::e($t['state'] ?: 'Scheduled') ?></span></div>
          <div class="meta"><?= $t['date'] !== $today ? '<span>📅 ' . Security::e(formatDate($t['date'])) . '</span>' : '' ?><span>🚌 <?= Security::e($t['bus'] ?: 'no vehicle') ?></span><?= $t['driver'] !== '' ? '<span>👤 ' . Security::e($t['driver']) . ($t['driverPhone'] !== '' ? ' · <a href="tel:' . Security::e($t['driverPhone']) . '">' . Security::e($t['driverPhone']) . '</a>' : '') . '</span>' : '' ?><span>🎟 <?= $t['sold'] ?>/<?= $t['total'] ?></span><?= $t['delay'] > 0 ? '<span>⏱ +' . $t['delay'] . ' min</span>' : '' ?><?= $t['blocked'] ? '<span style="color:#8a1f1f">🚫 OFF</span>' : '' ?><?= $t['cancelled'] ? '<span style="color:#8a1f1f">❌ cancelled</span>' : '' ?></div>
          <div class="acts"><a class="btn ghost" href="<?= $base ?>/admin/seatmap.php?sid=<?= $t['id'] ?>">🪑 Seats</a><a class="btn ghost" href="<?= $base ?>/admin/manifest.php?sid=<?= $t['id'] ?>&amp;date=<?= Security::e($t['date']) ?>">📋 Manifest</a><a class="btn ghost" href="<?= $base ?>/admin/trip-dashboard.php?sid=<?= $t['id'] ?>">📊 Trip</a><a class="btn ghost" href="<?= $base ?>/admin/chalan.php?sid=<?= $t['id'] ?>">🧾 Chalan</a></div>
        </div>
        <?php endforeach; if ($shown === 0): ?><div class="muted" style="padding:14px">No departures today.</div><?php endif; ?>
      </div>
    </div>
    <div class="lm-card"><h3>🛣️ Road line <small>for the customer map + ETA</small></h3>
      <?php if ($geoFlash !== null): ?><div class="flash <?= Security::e($geoFlash[0]) ?>" style="margin:10px 14px"><?= Security::e($geoFlash[1]) ?></div><?php endif; ?>
      <?php foreach ($routes as $r): $gi = $roadInfo[(int) $r['id']] ?? null; $missing = EtaAlerts::stopLine((int) $r['id'])['missing']; ?>
        <div class="lm-trip" style="cursor:default">
          <div class="t1"><span><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?></span>
            <span class="pill" style="margin-left:auto"><?= $gi !== null ? 'v' . (int) $gi['version'] . ' · ' . number_format($gi['length_m'] / 1000, 0) . ' km' : 'not built' ?></span></div>
          <div class="meta"><?= $gi !== null ? '<span>built ' . Security::e(date('j M H:i', (int) strtotime($gi['built_at']))) . '</span><span>' . Security::e($gi['source']) . '</span>' : '<span>The map and ETA use the straight line of stops.</span>' ?>
            <?= $missing !== [] ? '<span style="color:#9a5b00">No coordinates: ' . Security::e(implode(', ', $missing)) . '</span>' : '' ?></div>
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
    <div class="lm-card"><h3>👥 <?= $paxScope !== null ? 'My passengers' : 'Passengers' ?> · यात्रु <small>by pickup · चढ्ने ठाउँ अनुसार</small></h3>
      <?php if ($paxTrips === []): ?>
        <div class="muted" style="padding:10px 14px"><?= $paxScope !== null ? 'You have no passengers on today&#39;s buses. · आजको बसमा तपाईंको यात्रु छैन।' : 'No passengers on today&#39;s buses yet.' ?></div>
      <?php endif; ?>
      <?php foreach ($paxTrips as $pt): $t = $pt['trip']; ?>
        <div class="lm-pax-trip"><b><?= Security::e($t['time']) ?> · <?= Security::e($t['label']) ?></b></div>
        <?php foreach ($pt['groups'] as $g): ?>
          <details class="lm-pax">
            <summary><span class="lm-pax-stop">📍 <?= Security::e($g['stop']) ?></span>
              <span class="lm-pax-n"><?= (int) $g['seats'] ?> seat<?= (int) $g['seats'] === 1 ? '' : 's' ?></span>
              <?php if ($g['eta'] !== null): ?><span class="lm-pax-eta">🚌 ~<?= (int) (ceil($g['eta'] / 5) * 5) ?> min</span><?php endif; ?></summary>
            <?php if ($paxNames): foreach ($g['pax'] as $p):
              $tel = (string) preg_replace('/\D/', '', (string) $p['contact_phone']);
              $wa  = ($tel !== '' && preg_match('/^0+$/', $tel) !== 1) ? (strlen($tel) === 10 ? '91' . $tel : $tel) : ''; ?>
              <div class="lm-pax-row"><span><?= Security::e((string) ($p['name'] ?: '—')) ?> <small class="muted"><?= Security::e((string) $p['pnr']) ?></small></span>
                <?php if ($wa !== ''): ?><a class="lm-call" href="tel:+<?= Security::e($wa) ?>" aria-label="Call">📞</a><a class="lm-wa" href="https://wa.me/<?= Security::e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp">💬</a><?php endif; ?></div>
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
  fleetUrl: <?= json_encode($base . '/admin/api/live-fleet.php') ?>
};
(function () {
  var A = window.ADMAP, map = null, busMarker = null, follow = false, lastAt = 0, busDisp = null, busTarget = null, anim = null;
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function loadLib(){
    return new Promise(function(res, rej){
      if (window.maplibregl) return res();
      var s = document.createElement('script'); s.src = 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js'; s.onload = res; s.onerror = rej; document.head.appendChild(s);
    });
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
      var coords = r.road ? decode6(r.road) : pts.map(function(s){ return [s.lng, s.lat]; });   // 20 Oct 2026: the stored road line when built
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
      if (!busMarker) {
        var el = document.createElement('div'); el.className = 'lm-busm'; el.textContent = '🚌';
        busMarker = new maplibregl.Marker({ element: el }).setLngLat([lb.lng, lb.lat]).addTo(map);
        busDisp = { lng: lb.lng, lat: lb.lat };
        map.flyTo({ center: [lb.lng, lb.lat], zoom: Math.max(map.getZoom(), 10), duration: 1500 });
      }
      busTarget = { lng: lb.lng, lat: lb.lat };
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
  /* 🛰️ Every coach the driver app runs (20 Oct 2026): its own marker,
     gliding to each new fix; grey and "last seen" once the fix is stale. */
  var fleet = {};
  function glide(f){
    f.anim = null;
    var k = 0.12, dx = f.to.lng - f.at.lng, dy = f.to.lat - f.at.lat;
    f.at = { lng: f.at.lng + dx * k, lat: f.at.lat + dy * k };
    f.marker.setLngLat([f.at.lng, f.at.lat]);
    if (Math.abs(dx) > 1e-7 || Math.abs(dy) > 1e-7) f.anim = requestAnimationFrame(function(){ glide(f); });
  }
  function ago(s){ if (s == null) return 'no fix yet'; var m = Math.round(s / 60); return s < 60 ? s + ' s ago' : (m < 60 ? m + ' min ago' : Math.round(m / 60) + ' h ago'); }
  function showFleet(list){
    var box = document.getElementById('lmFleet'), seen = {};
    document.getElementById('lmFleetAge').textContent = list.length ? list.length + ' running' : 'none';
    window.SHG_GPS_RUNNING_PLATES = {};
    list.forEach(function (t) { if (t.live) window.SHG_GPS_RUNNING_PLATES[t.bus] = true; });
    box.innerHTML = list.map(function(t){
      return '<div class="lm-trip" data-trip="' + t.tripId + '"><div class="t1"><span>' + (t.live ? '🟢' : '⚪') + '</span><span>' + esc(t.route) + '</span>'
        + '<span class="pill" style="margin-left:auto">' + esc(t.bus || t.routeCode) + '</span></div>'
        + '<div class="meta"><span>🕐 ' + esc(t.dep) + ' · ' + esc(t.date) + '</span>' + (t.driver ? '<span>👤 ' + esc(t.driver) + (t.driverPhone ? ' · <a href="tel:' + esc(t.driverPhone) + '">' + esc(t.driverPhone) + '</a>' : '') + '</span>' : '')
        + '<span>🎟 ' + t.pax + '</span>' + (t.kmh != null && t.live ? '<span>' + t.kmh + ' km/h</span>' : '') + '<span>' + (t.live ? '🛰️ ' : '⏸ last seen ') + esc(ago(t.ageSec)) + '</span>'
        + (t.acc != null ? '<span>±' + t.acc + ' m</span>' : '') + '</div></div>';
    }).join('');
    box.querySelectorAll('[data-trip]').forEach(function(el){ el.addEventListener('click', function(e){ if (e.target.closest('a')) return; var f = fleet[el.dataset.trip]; if (f && map) map.easeTo({ center: [f.to.lng, f.to.lat], zoom: Math.max(map.getZoom(), 12), duration: 900 }); }); });
    if (!map) return;
    list.forEach(function(t){
      if (t.lat == null || t.lng == null) return;
      seen[t.tripId] = true;
      var f = fleet[t.tripId];
      if (!f) {
        var el = document.createElement('div'); el.className = 'lm-busm';
        el.innerHTML = '🚌<span style="position:absolute;left:30px;top:4px;white-space:nowrap;font:700 11px system-ui;background:#0B1B3A;color:#fff;padding:2px 6px;border-radius:6px"></span>';
        f = fleet[t.tripId] = { el: el, at: { lng: t.lng, lat: t.lat }, to: { lng: t.lng, lat: t.lat }, anim: null };
        f.marker = new maplibregl.Marker({ element: el }).setLngLat([t.lng, t.lat]).addTo(map);
      }
      f.el.querySelector('span').textContent = (t.bus || t.routeCode) + (t.live && t.kmh != null ? ' · ' + t.kmh + ' km/h' : (t.live ? '' : ' · ' + ago(t.ageSec)));
      f.el.style.opacity = t.live ? '1' : '.55';
      f.to = { lng: t.lng, lat: t.lat };
      if (!f.anim) f.anim = requestAnimationFrame(function(){ glide(f); });
    });
    Object.keys(fleet).forEach(function(id){ if (!seen[id]) { if (fleet[id].anim) cancelAnimationFrame(fleet[id].anim); fleet[id].marker.remove(); delete fleet[id]; } });
  }
  function pollFleet(){
    if (document.hidden) return;
    fetch(A.fleetUrl, { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
      if (d && d.ok && d.data) showFleet(d.data.trips || []);
    }).catch(function(){});
  }
  document.getElementById('lmFollow').onclick = function(){ follow = !follow; this.classList.toggle('on', follow); this.textContent = follow ? '🎯 Following bus' : '🎯 Follow bus'; if (follow && busTarget) map.easeTo({ center: [busTarget.lng, busTarget.lat], zoom: Math.max(map.getZoom(), 12), duration: 900 }); };
  document.getElementById('lmFit').onclick = function(){ var b = bounds(); if (b) map.fitBounds(b, { padding: 48, duration: 900 }); };
  document.querySelectorAll('.lm-trip').forEach(function(el){ el.addEventListener('click', function(e){ if (e.target.closest('a')) return; var r = A.routes.find(function(x){ return x.code === el.dataset.code; }); if (!r || !r.stops.length || !map) return; var b = null; r.stops.forEach(function(s){ if (!b) b = new maplibregl.LngLatBounds([s.lng, s.lat], [s.lng, s.lat]); else b.extend([s.lng, s.lat]); }); map.fitBounds(b, { padding: 48, duration: 900 }); }); });
  /* the list does not wait for the map: a slow tile server must not hide the buses */
  pollFleet(); setInterval(pollFleet, 10000);
  loadLib().then(function(){
    try {
      map = new maplibregl.Map({ container: 'lmMap', style: 'https://tiles.openfreemap.org/styles/liberty', center: [76.8, 25.6], zoom: 5, fadeDuration: 250, maxBounds: [[61.0, 4.5], [99.5, 38.5]], minZoom: 3.8, attributionControl: { compact: true } });
    } catch (e) { document.getElementById('lmLoad').textContent = 'This browser cannot draw the map (no WebGL).'; return; }
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
    map.on('dragstart', function(){ if (follow) document.getElementById('lmFollow').click(); });
    map.on('load', function(){
      var l = document.getElementById('lmLoad'); if (l) l.remove();
      draw();
      window.SHG_GPS_MAP = map; document.dispatchEvent(new Event('shg:admin-map-ready'));
      var b = bounds(); if (b) map.fitBounds(b, { padding: 48, duration: 0 });
      poll(); setInterval(poll, 15000);
      pollFleet();   // draw the markers now the map is ready
    });
  }).catch(function(){ document.getElementById('lmLoad').textContent = 'Map library could not be loaded — check the connection.'; });
})();
</script>
<script src="/assets/nav/gps-admin.js?v=20261025gps2" defer></script>
<?php admin_footer(); ?>
