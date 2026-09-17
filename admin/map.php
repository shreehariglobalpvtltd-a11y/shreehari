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
.lm-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
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

<div class="lm-top">
  <span class="muted" style="font-size:12.5px">Routes and stops from the database · live bus from the driver's phone (navigator → "I'm the driver") · refreshes every 15 s</span>
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
    <div class="lm-card"><h3>🚌 Live bus <small id="lmBusAge">no position yet</small></h3>
      <div class="lm-bus" id="lmBus"><span class="muted">Waiting for the driver's phone. A signed-in driver taps "📡 I'm the driver" in the navigator; the office can also set a stop by hand from the app.</span></div>
    </div>
    <div class="lm-card"><h3>🕐 Today's departures <small><?= Security::e(formatDate($today)) ?></small></h3>
      <div id="lmTrips">
        <?php $shown = 0; foreach ($jsTrips as $t): if ($t['date'] !== $today && !in_array($t['stateKey'], ['departed', 'on_route', 'boarding', 'delayed'], true)) { continue; } $shown++; ?>
        <div class="lm-trip" data-id="<?= $t['id'] ?>" data-code="<?= Security::e($t['code']) ?>">
          <div class="t1"><span class="time"><?= Security::e($t['time']) ?></span><span><?= Security::e($t['label']) ?></span><?= $t['slot'] > 1 ? '<span class="pill" style="background:#efeaff;color:#5a3fb0">Bus ' . $t['slot'] . '</span>' : '' ?>
            <span class="pill" style="margin-left:auto;background:<?= Security::e($t['color']) ?>1a;color:<?= Security::e($t['color']) ?>;border:1px solid <?= Security::e($t['color']) ?>55"><?= Security::e($t['state'] ?: 'Scheduled') ?></span></div>
          <div class="meta"><?= $t['date'] !== $today ? '<span>📅 ' . Security::e(formatDate($t['date'])) . '</span>' : '' ?><span>🚌 <?= Security::e($t['bus'] ?: 'no vehicle') ?></span><?= $t['driver'] !== '' ? '<span>👤 ' . Security::e($t['driver']) . ($t['driverPhone'] !== '' ? ' · <a href="tel:' . Security::e($t['driverPhone']) . '">' . Security::e($t['driverPhone']) . '</a>' : '') . '</span>' : '' ?><span>🎟 <?= $t['sold'] ?>/<?= $t['total'] ?></span><?= $t['delay'] > 0 ? '<span>⏱ +' . $t['delay'] . ' min</span>' : '' ?><?= $t['blocked'] ? '<span style="color:#8a1f1f">🚫 OFF</span>' : '' ?><?= $t['cancelled'] ? '<span style="color:#8a1f1f">❌ cancelled</span>' : '' ?></div>
          <div class="acts"><a class="btn ghost" href="<?= $base ?>/admin/seatmap.php?sid=<?= $t['id'] ?>">🪑 Seats</a><a class="btn ghost" href="<?= $base ?>/admin/manifest.php?sid=<?= $t['id'] ?>&amp;date=<?= Security::e($t['date']) ?>">📋 Manifest</a><a class="btn ghost" href="<?= $base ?>/admin/trip-dashboard.php?sid=<?= $t['id'] ?>">📊 Trip</a><a class="btn ghost" href="<?= $base ?>/admin/chalan.php?sid=<?= $t['id'] ?>">🧾 Chalan</a></div>
        </div>
        <?php endforeach; if ($shown === 0): ?><div class="muted" style="padding:14px">No departures today.</div><?php endif; ?>
      </div>
    </div>
  </aside>
</div>

<script>
window.ADMAP = {
  routes: <?= json_encode($jsRoutes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  office: { lat: 23.588, lng: 72.369, name: <?= json_encode($company, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, phone: <?= json_encode($phone, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> },
  base: <?= json_encode($base) ?>
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
  function bounds(){
    var b = null;
    A.routes.forEach(function(r){ r.stops.forEach(function(s){ if (!b) b = new maplibregl.LngLatBounds([s.lng, s.lat], [s.lng, s.lat]); else b.extend([s.lng, s.lat]); }); });
    if (b) b.extend([A.office.lng, A.office.lat]);
    return b;
  }
  function draw(){
    A.routes.forEach(function(r, i){
      var pts = r.stops.filter(function(s){ return s.type === 'boarding'; }).concat(r.stops.filter(function(s){ return s.type === 'drop'; }));
      var coords = pts.map(function(s){ return [s.lng, s.lat]; });
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
    fetch(A.base + '/api/kv.php?action=get&key=livebus', { credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
      var v = (d && d.data && d.data.value !== undefined) ? d.data.value : (d && d.value !== undefined ? d.value : (d && d.data));
      if (typeof v === 'string') { try { v = JSON.parse(v); } catch (e) { v = null; } }
      showBus(v);
    }).catch(function(){});
  }
  document.getElementById('lmFollow').onclick = function(){ follow = !follow; this.classList.toggle('on', follow); this.textContent = follow ? '🎯 Following bus' : '🎯 Follow bus'; if (follow && busTarget) map.easeTo({ center: [busTarget.lng, busTarget.lat], zoom: Math.max(map.getZoom(), 12), duration: 900 }); };
  document.getElementById('lmFit').onclick = function(){ var b = bounds(); if (b) map.fitBounds(b, { padding: 48, duration: 900 }); };
  document.querySelectorAll('.lm-trip').forEach(function(el){ el.addEventListener('click', function(e){ if (e.target.closest('a')) return; var r = A.routes.find(function(x){ return x.code === el.dataset.code; }); if (!r || !r.stops.length || !map) return; var b = null; r.stops.forEach(function(s){ if (!b) b = new maplibregl.LngLatBounds([s.lng, s.lat], [s.lng, s.lat]); else b.extend([s.lng, s.lat]); }); map.fitBounds(b, { padding: 48, duration: 900 }); }); });
  loadLib().then(function(){
    try {
      map = new maplibregl.Map({ container: 'lmMap', style: 'https://tiles.openfreemap.org/styles/liberty', center: [76.8, 25.6], zoom: 5, fadeDuration: 250, maxBounds: [[61.0, 4.5], [99.5, 38.5]], minZoom: 3.8, attributionControl: { compact: true } });
    } catch (e) { document.getElementById('lmLoad').textContent = 'This browser cannot draw the map (no WebGL).'; return; }
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'top-right');
    map.on('dragstart', function(){ if (follow) document.getElementById('lmFollow').click(); });
    map.on('load', function(){
      var l = document.getElementById('lmLoad'); if (l) l.remove();
      draw();
      var b = bounds(); if (b) map.fitBounds(b, { padding: 48, duration: 0 });
      poll(); setInterval(poll, 15000);
    });
  }).catch(function(){ document.getElementById('lmLoad').textContent = 'Map library could not be loaded — check the connection.'; });
})();
</script>
<?php admin_footer(); ?>
