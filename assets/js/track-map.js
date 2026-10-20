/* =====================================================================
 *  track-map.js — the road map on track.php (20 Oct 2026, live tracking
 *  phase 2). Loaded ONLY when the passenger taps "Show map", so a 3G phone
 *  pays nothing for it until then.
 *
 *  · the route's stored road line (api/track-map.php?part=line, cached by
 *    version) — grey where the coach has driven, blue where it still goes
 *  · the route's stops that have coordinates; the passenger's own pickup
 *    (and drop) highlighted; a pickup without coordinates is said, not drawn
 *  · the coach where WhereIs::status() says it is. Between two polls it
 *    GLIDES along the road — the km number is animated, never a straight
 *    lat/lng line across fields — and it never moves on its own: no live
 *    fix = no moving bus; a "last seen" coach is grey and still.
 *  · auto-fit to bus + pickup until the passenger moves the map.
 *
 *  Updates ride on the page's own 15 s refresh (track.php paint() calls
 *  SHG_TRACK.mapUpdate) — no second poll.
 * ===================================================================== */
(function (S) {
  'use strict';
  if (!S || !S.mapCfg || S.mapOn) return;
  S.mapOn = true;
  var T = S.T, C = S.mapCfg, fill = S.fill;
  var card = document.getElementById('mapCard'), btn = document.getElementById('mapBtn'), sub = document.getElementById('mapSub');
  var MLG = '4.7.1', OWN = '/assets/vendor/maplibre-gl-' + MLG + '/maplibre-gl', CDN = 'https://unpkg.com/maplibre-gl@' + MLG + '/dist/maplibre-gl';
  var GREY = '#9AA5B8', BLUE = '#2F7BE5';
  var reduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
  var map = null, ready = false, box = null, V = null, line = null, cum = [], merc = [], stops = [], stopEls = [];
  var bus = null, busEl = null, busLbl = null, busKm = null, anim = 0, userMoved = false, fitBtn = null, chip = null, lastD = null;

  function css(href) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l); }
  function fail() {
    if (box) box.remove();
    btn.classList.remove('hidden'); btn.disabled = false; btn.textContent = T.mapFail;
    S.mapOn = false; S.mapUpdate = null;
  }
  /* MapLibre from our own server (assets/vendor, as the app's loadMapLibre), unpkg only if that fails. */
  function lib() {
    return new Promise(function (res, rej) {
      if (window.maplibregl) { res(); return; }
      function add(base, last) {
        var q = base === OWN ? '?v=' + MLG : '';
        css(base + '.css' + q);
        var s = document.createElement('script');
        s.src = base + '.js' + q;
        s.onload = function () { if (window.maplibregl) res(); else if (!last) add(CDN, true); else rej(); };
        s.onerror = function () { if (!last) add(CDN, true); else rej(); };
        document.head.appendChild(s);
      }
      add(OWN, false);
    });
  }
  function getJSON(u) {
    return fetch(u, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error('bad'); return j.data; });
  }

  /* ---------- the line: decode, km and Web-Mercator length at every point ---------- */
  function decode(s) {
    var pts = [], i = 0, lat = 0, lng = 0, n = s.length;
    while (i < n) {
      for (var k = 0; k < 2; k++) {
        var sh = 0, res = 0, b;
        do { b = s.charCodeAt(i++) - 63; res |= (b & 31) << sh; sh += 5; } while (b >= 32 && i < n);
        var v = (res & 1) ? ~(res >> 1) : (res >> 1);
        if (k === 0) lat += v; else lng += v;
      }
      pts.push([lng / 1e6, lat / 1e6]);
    }
    return pts;
  }
  function rad(d) { return d * Math.PI / 180; }
  function hav(a, b) {
    var dLat = rad(b[1] - a[1]), dLng = rad(b[0] - a[0]);
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(rad(a[1])) * Math.cos(rad(b[1])) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * 6371.0088 * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  /* MapLibre's line-progress runs along the line in Mercator units, not km; keep both so grey ends exactly under the bus. */
  function prep(pts) {
    line = pts; cum = [0]; merc = [0];
    var py = Math.log(Math.tan(Math.PI / 4 + rad(pts[0][1]) / 2)), px = rad(pts[0][0]);
    for (var i = 1; i < pts.length; i++) {
      cum[i] = cum[i - 1] + hav(pts[i - 1], pts[i]);
      var x = rad(pts[i][0]), y = Math.log(Math.tan(Math.PI / 4 + rad(pts[i][1]) / 2));
      merc[i] = merc[i - 1] + Math.sqrt((x - px) * (x - px) + (y - py) * (y - py));
      px = x; py = y;
    }
  }
  function at(km) {
    var n = cum.length, total = cum[n - 1];
    km = Math.max(0, Math.min(total, km));
    var lo = 0, hi = n - 1;
    while (hi - lo > 1) { var mid = (lo + hi) >> 1; if (cum[mid] <= km) lo = mid; else hi = mid; }
    var span = cum[hi] - cum[lo], t = span > 0 ? (km - cum[lo]) / span : 0;
    return {
      ll: [line[lo][0] + t * (line[hi][0] - line[lo][0]), line[lo][1] + t * (line[hi][1] - line[lo][1])],
      frac: (merc[lo] + t * (merc[hi] - merc[lo])) / merc[n - 1]
    };
  }
  function split(frac) {
    if (!ready) return;
    var f = Math.max(0.000001, Math.min(0.999999, frac));
    map.setPaintProperty('tm-road', 'line-gradient', ['step', ['line-progress'], GREY, f, BLUE]);
  }

  /* ---------- the coach ---------- */
  function busAt(ll) {
    if (!bus) {
      busEl = document.createElement('div'); busEl.className = 'tm-bus';
      busEl.innerHTML = '<i></i>🚌<span></span>';
      busLbl = busEl.querySelector('span');
      bus = new maplibregl.Marker({ element: busEl }).setLngLat(ll).addTo(map);
    } else {
      bus.setLngLat(ll);
    }
  }
  function busOff() { cancelAnimationFrame(anim); anim = 0; busKm = null; if (bus) { bus.remove(); bus = null; } }
  function paintStops(km) {
    stopEls.forEach(function (el, i) { el.classList.toggle('done', km != null && stops[i].km <= km + 0.5); });
  }
  /* Glide: animate the km number from where the bus is drawn to the new fix, over 1.6 s. Never past it, never on its own. */
  function glideTo(km) {
    cancelAnimationFrame(anim); anim = 0;
    var from = busKm;
    if (from === null || reduce || Math.abs(km - from) > 30) {
      busKm = km; var p = at(km); busAt(p.ll); split(p.frac); paintStops(km); return;
    }
    var t0 = performance.now(), dur = 1600;
    function step(now) {
      var u = Math.min(1, (now - t0) / dur), e = u < 0.5 ? 2 * u * u : 1 - Math.pow(-2 * u + 2, 2) / 2;
      busKm = from + (km - from) * e;
      var p = at(busKm); busAt(p.ll); split(p.frac);
      if (u < 1) anim = requestAnimationFrame(step); else { anim = 0; paintStops(km); }
    }
    anim = requestAnimationFrame(step);
  }

  function showChip(txt) { chip.textContent = txt || ''; chip.classList.toggle('hidden', !txt); }
  function mine(kind) { for (var i = 0; i < stops.length; i++) if (stops[i].mine === kind) return stops[i]; return null; }
  function fit(d) {
    if (!ready || userMoved) return;
    var pts = [], pick = mine('board');
    if (bus) pts.push(bus.getLngLat().toArray());
    if (pick && !(d && d.passed)) pts.push([pick.lng, pick.lat]);
    if (!pts.length) {
      var b = new maplibregl.LngLatBounds(line[0], line[0]);
      line.forEach(function (p) { b.extend(p); });
      map.fitBounds(b, { padding: 30, duration: 0 });
    } else if (pts.length === 1) {
      map.easeTo({ center: pts[0], zoom: Math.max(map.getZoom(), 10), duration: reduce ? 0 : 800 });
    } else {
      map.fitBounds(new maplibregl.LngLatBounds(pts[0], pts[0]).extend(pts[1]), { padding: { top: 64, bottom: 76, left: 44, right: 44 }, maxZoom: 13, duration: reduce ? 0 : 800 });
    }
  }

  /* One status answer (the page's refresh, or api/track-map.php's first one) → the map. */
  function update(d) {
    if (!d) return;
    lastD = d;
    if (!ready) return;
    var r = d.road && (d.road.v == null || d.road.v === V) ? d.road : null;
    var done = d.state === 'arrived' || d.state === 'completed';
    var txt = '';
    if (done) {
      busOff(); split(1); paintStops(1e9);
    } else if (d.live && d.lat != null) {
      if (r && r.on && (r.off == null || r.off <= 1)) {
        glideTo(r.km);   // on the road: glide along it
      } else {
        cancelAnimationFrame(anim); anim = 0; busKm = null;
        busAt([d.lng, d.lat]);   // near the route but not on the stored road: where it really is
        if (r) { split(at(r.km).frac); paintStops(r.km); }
      }
      busEl.className = 'tm-bus live';
      busLbl.textContent = d.kmh != null ? d.kmh + ' km/h' : '';
    } else if (d.lastKnown && d.lastLat != null) {
      cancelAnimationFrame(anim); anim = 0; busKm = null;
      busAt([d.lastLng, d.lastLat]);
      busEl.className = 'tm-bus last';
      txt = fill(T.mapLast, [d.lastAt]);
      busLbl.textContent = txt;
      if (r) { split(at(r.km).frac); paintStops(r.km); }
    } else {
      busOff(); split(0); paintStops(null);
      txt = T.mapNotLive;
    }
    showChip(txt);
    fit(d);
  }

  function draw(info) {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    try {
      map = new maplibregl.Map({
        container: 'tmMap', style: dark ? 'https://tiles.openfreemap.org/styles/dark' : 'https://tiles.openfreemap.org/styles/liberty',
        bounds: [line.reduce(function (a, p) { return [Math.min(a[0], p[0]), Math.min(a[1], p[1])]; }, [180, 90]),
                 line.reduce(function (a, p) { return [Math.max(a[0], p[0]), Math.max(a[1], p[1])]; }, [-180, -90])],
        fitBoundsOptions: { padding: 30 }, maxBounds: [[61.0, 4.5], [99.5, 38.5]], minZoom: 3.8,
        dragRotate: false, pitchWithRotate: false, touchPitch: false, attributionControl: { compact: true }
      });
    } catch (e) { fail(); return; }
    map.touchZoomRotate.disableRotation();
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    function moved(e) { if (e && e.originalEvent) { userMoved = true; fitBtn.classList.remove('hidden'); } }
    map.on('dragstart', moved); map.on('zoomstart', moved);
    map.on('error', function () {});   // a missing tile is not a broken page
    map.on('load', function () {
      map.addSource('tm-road', { type: 'geojson', lineMetrics: true, data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: line } } });
      map.addLayer({ id: 'tm-case', type: 'line', source: 'tm-road', layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-color': dark ? '#0B1220' : '#FFFFFF', 'line-width': 8, 'line-opacity': 0.9 } });
      map.addLayer({ id: 'tm-road', type: 'line', source: 'tm-road', layout: { 'line-cap': 'round', 'line-join': 'round' },
        paint: { 'line-width': 4.5, 'line-gradient': ['step', ['line-progress'], GREY, 0.000001, BLUE] } });
      stops = info.stops || [];
      stopEls = stops.map(function (s) {
        var el = document.createElement('div');
        el.className = 'tm-stop' + (s.mine ? ' ' + s.mine : '');
        el.title = s.name;
        if (s.mine) { var b = document.createElement('b'); b.textContent = s.mine === 'board' ? T.you : T.youOff; el.appendChild(b); }
        var pop = document.createElement('div'); pop.className = 'tm-pop'; pop.textContent = s.name;
        new maplibregl.Marker({ element: el }).setLngLat([s.lng, s.lat]).setPopup(new maplibregl.Popup({ offset: 12, closeButton: false }).setDOMContent(pop)).addTo(map);
        return el;
      });
      var at = box.querySelector('.maplibregl-ctrl-attrib');   // credits behind the (i), not over the bus on a phone
      if (at) at.classList.remove('maplibregl-compact-show');
      ready = true;
      update(S.D && S.D.updatedAt && info.pos && info.pos.updatedAt && S.D.updatedAt > info.pos.updatedAt ? S.D : (info.pos || S.D));
    });
  }

  css(C.css);
  Promise.all([lib(), getJSON(C.url)]).then(function (res) {
    var info = res[1];
    if (!info || !info.v) throw new Error('no road line');
    V = info.v;
    return getJSON(C.url + '&part=line&v=' + encodeURIComponent(info.v)).then(function (g) {
      var pts = decode(g.line || '');
      if (pts.length < 2) throw new Error('empty line');
      prep(pts);
      box = document.createElement('div'); box.className = 'tm-box';
      box.innerHTML = '<div id="tmMap" class="tm-map"></div><div class="tm-chip hidden"></div><button type="button" class="tm-fit hidden">⤢ </button>';
      chip = box.querySelector('.tm-chip'); fitBtn = box.querySelector('.tm-fit');
      fitBtn.textContent = '⤢ ' + T.mapFit;
      fitBtn.onclick = function () { userMoved = false; fitBtn.classList.add('hidden'); fit(lastD); };
      card.insertBefore(box, btn);
      btn.classList.add('hidden');
      if (info.mineMissing && sub) sub.textContent = T.mapNoPick;
      else if (sub) sub.classList.add('hidden');
      S.mapUpdate = update;
      draw(info);
    });
  }).catch(fail);
})(window.SHG_TRACK);
