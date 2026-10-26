/* GPSLIVE-20261025: private device map, independent of departure state. */
(function () {
'use strict';
var maps = {}, rows = [], busy = false, failures = 0, timer, map;
var root = document.getElementById('gpsFleetStatus');
if (!root) return;
var labels = { live: 'GPS connected', waiting: 'Waiting for first GPS report', offline: 'Device offline', no_fix: 'Connected · waiting for GPS fix', stale: 'Connected · GPS fix is old', disabled: 'Tracker switched off' };
function esc(v) { var e = document.createElement('span'); e.textContent = String(v == null ? '' : v); return e.innerHTML; }
function age(t) { if (!t) return 'never'; var s = Math.max(0, Math.floor((Date.now() - t) / 1000)); return s < 60 ? s + ' s ago' : Math.floor(s / 60) + ' min ago'; }
function clear(id) { if (maps[id]) { if (maps[id].anim) cancelAnimationFrame(maps[id].anim); maps[id].marker.remove(); delete maps[id]; } }
function draw() {
  map = window.SHG_GPS_MAP || null;
  var seen = {}, running = window.SHG_GPS_RUNNING_PLATES || {};
  rows.forEach(function (r) {
    if (!map || !window.maplibregl || r.lat == null || r.lng == null || running[r.bus] || r.state === 'disabled') return;
    seen[r.id] = true;
    var f = maps[r.id];
    if (!f) {
      var e = document.createElement('button'); e.type = 'button'; e.title = r.bus; e.style.cssText = 'background:#fff;color:#173049;border:2px solid #bd8017;border-radius:16px;padding:5px 9px;font:700 12px system-ui;cursor:pointer';
      e.onclick = function () { focus(r.id); };
      f = maps[r.id] = { el: e, marker: new maplibregl.Marker({ element: e }).setLngLat([r.lng, r.lat]).addTo(map), anim: null, fixAt: null };
    }
    f.el.textContent = '🛰️ ' + r.bus + (r.kmh != null ? ' · ' + Math.round(r.kmh) + ' km/h' : ' · last known');
    f.el.style.opacity = r.state === 'live' ? '1' : '.55';
    if (f.fixAt !== r.fixAt) {
      if (f.anim) cancelAnimationFrame(f.anim);
      var p = f.marker.getLngLat(), t0 = performance.now();
      var reduced = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (r.state !== 'live' || reduced) f.marker.setLngLat([r.lng, r.lat]);
      else (function step(t) {
        var k = Math.min(1, (t - t0) / 1200);
        f.marker.setLngLat([p.lng + (r.lng - p.lng) * k, p.lat + (r.lat - p.lat) * k]);
        f.anim = k < 1 ? requestAnimationFrame(step) : null;
      })(t0);
      f.fixAt = r.fixAt;
    }
  });
  Object.keys(maps).forEach(function (id) { if (!seen[id]) clear(id); });
}
function focus(id) {
  var r = rows.find(function (x) { return x.id === Number(id); });
  if (r && map && r.lat != null && r.lng != null) map.easeTo({ center: [r.lng, r.lat], zoom: Math.max(map.getZoom(), 12), duration: 700 });
}
function render() {
  root.innerHTML = rows.length ? rows.map(function (r) {
    return '<div class="lm-trip"><div class="t1"><b>' + (r.state === 'live' ? '🟢 ' : '⚪ ') + esc(r.bus) + '</b><span>' + esc(labels[r.state] || r.state) + '</span></div>' +
      (r.diagnostic ? '<p style="margin:6px 0;font-size:12px">GPS: ' + esc(({poor_accuracy:'Accuracy too weak for live tracking',old_fix:'Phone is sending an old GPS fix',stale_report:'Delayed GPS report',no_fix:'No valid GPS fix in report',invalid_fix:'Invalid GPS fix',accepted:'Fresh GPS fix accepted'})[r.diagnostic.reason] || r.diagnostic.reason) + (r.diagnostic.accuracyM != null ? ' · ±' + Math.round(r.diagnostic.accuracyM) + ' m' : '') + '</p>' : '') +
      '<div class="meta"><span>' + esc(r.label) + '</span><span>Received: ' + esc(age(r.seenAt)) + '</span><span>Fix: ' + esc(age(r.fixAt)) + '</span>' +
      (r.kmh != null ? '<span>' + r.kmh + ' km/h</span>' : '') + (r.battery != null ? '<span>Battery ' + r.battery + '%</span>' : '') + '</div>' +
      '<div class="acts">' + (r.lat != null ? '<button class="btn ghost" type="button" data-gps-focus="' + r.id + '">📍 ' + (r.state === 'live' ? 'Locate bus' : 'Last known position') + '</button>' : '') +
      '<a class="btn ghost" href="/admin/gps-trackers.php?bus=' + r.busId + '#add">Configure device</a></div></div>';
  }).join('') : '<p style="padding:12px">No GPS devices. <a href="/admin/gps-trackers.php#add">Add a bus GPS device</a>.</p>';
  root.querySelectorAll('[data-gps-focus]').forEach(function (e) { e.onclick = function () { focus(e.dataset.gpsFocus); }; });
  var badge = document.getElementById('gpsFleetAge'); if (badge) badge.textContent = rows.filter(function (r) { return r.state === 'live'; }).length + ' connected';
}
function poll() {
  clearTimeout(timer);
  if (document.hidden || busy) return;
  busy = true;
  fetch('/admin/api/gps-fleet.php', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) })
    .then(function (r) { if (!r.ok) throw Error('http'); return r.json(); })
    .then(function (j) { if (!j.ok) throw Error('data'); rows = j.data.devices; failures = 0; render(); draw(); })
    .catch(function () {
      failures++; root.textContent = 'GPS connection status unavailable · retrying. Last positions are not live.';
      rows.forEach(function (r) { if (r.state === 'live') { r.state = 'offline'; r.kmh = null; } }); draw();
    })
    .finally(function () { busy = false; if (!document.hidden) timer = setTimeout(poll, Math.min(60000, 1000 * Math.pow(2, failures))); });
}
var b = document.getElementById('gpsFleetRefresh'); if (b) b.onclick = poll;
document.addEventListener('shg:admin-map-ready', draw);
document.addEventListener('visibilitychange', function () { if (document.hidden) { clearTimeout(timer); Object.keys(maps).forEach(clear); } else poll(); });
window.addEventListener('online', poll);
poll();
})();
