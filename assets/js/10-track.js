
/* ================================================================
   [JS] 10e. TRACK MY BUS — honest route map + schedule data.
   No GPS hardware on the bus, so we never fake a live bus position —
   the map shows the real road route, real stops, and the passenger's
   own device location (see [JS] 14B UNIFIED LIVE MAP).
================================================================ */
/* "30h 45m" → minutes (fallback 30h for this corridor) */
function durationMinutes(r) {
  const m = String((r && r.duration) || '').match(/(\d+)\s*h(?:\s*(\d+)\s*m)?/i);
  return m ? (parseInt(m[1], 10) * 60 + (parseInt(m[2], 10) || 0)) : 1800;
}
/* Route waypoints with SVG positions and real distances (km).
   Additive fields (trackMapSVG only reads name/flag/x/y/km):
   m    = minutes offset from departure (schedule estimate incl. halts, ~30h30m corridor)
   amen = amenity flags at that halt {meal,fuel,wash,hotel,prayer,hosp,police,border} */
const ROUTE_STOPS = [
  { name: 'Surat',        flag: '🇮🇳', x: 24,  y: 296, km: 0,    m: 0,    lat: 21.1700, lng: 72.8310, amen: {} },
  { name: 'Baroda',       flag: '',     x: 64,  y: 264, km: 150,  m: 240,  lat: 22.3070, lng: 73.1810, amen: {} },
  /* 'Emli Bhupal' (19:00 halt) has no coordinates yet — deliberately
     skipped here; add it once real lat/lng are known. */
  { name: 'S Hari Parking, Nana Chiloda', flag: '', x: 98, y: 246, km: 260, m: 480, lat: 23.1710, lng: 72.6230, amen: {} },
  { name: 'Mehsana',      flag: '',     x: 126, y: 232, km: 325,  m: 600,  lat: 23.5880, lng: 72.3690, amen: {} },
  { name: 'Rupaidiha',    flag: '🛃',   x: 440, y: 78,  km: 1600, m: 1800, lat: 28.0600, lng: 81.6170, amen: { border: true, police: true, wash: true } }
];
function trackMapSVG(pct, reverse) {
  const p = Math.max(0, Math.min(100, pct == null ? 0 : pct));
  const stops = ROUTE_STOPS;
  const pathD = 'M' + stops.map(s => s.x + ',' + s.y).join(' L');
  let dotsSVG = '';
  stops.forEach((s, i) => {
    const isEnd = i === 0 || i === stops.length - 1;
    const isBorder = s.name === 'Rupaidiha';
    const isLunch = !!(s.amen && s.amen.lunch);
    const r = isEnd ? 6 : (isBorder ? 5 : (isLunch ? 5 : 3.5));
    const fill = isEnd ? (i === 0 ? 'var(--orange)' : '#5FA8E8') : (isBorder ? '#E91E63' : (isLunch ? '#FF9800' : 'var(--muted)'));
    const sw = isEnd ? 2 : 1.2;
    dotsSVG += '<circle cx="' + s.x + '" cy="' + s.y + '" r="' + r + '" fill="' + fill + '" stroke="#fff" stroke-width="' + sw + '" class="trk-stop-dot" data-stop="' + esc(s.name) + '"' + (isLunch ? ' data-lunch="1"' : '') + '/>';
    const anchor = i === 0 ? 'start' : (i === stops.length - 1 ? 'end' : 'middle');
    const lx = s.x + (i === 0 ? -2 : (i === stops.length - 1 ? 2 : 0));
    const ly = (i % 2 === 0) ? s.y - 14 : s.y + 20;
    const fs = isEnd ? '11' : '9';
    const fw = isEnd ? '700' : '600';
    dotsSVG += '<text x="' + lx + '" y="' + ly + '" text-anchor="' + anchor + '" fill="var(--ink)" font-size="' + fs + '" font-weight="' + fw + '" class="trk-stop-label" data-stop="' + esc(s.name) + '" style="cursor:pointer">'
      + (isLunch ? '🍽️ ' : '') + (s.flag ? s.flag + ' ' : '') + s.name + '</text>';
    if (i > 0) {
      const prev = stops[i - 1];
      const mx = (prev.x + s.x) / 2, my = (prev.y + s.y) / 2 - 5;
      const segKm = s.km - prev.km;
      dotsSVG += '<text x="' + mx + '" y="' + my + '" text-anchor="middle" fill="var(--muted)" font-size="7.5" opacity=".7">' + segKm + ' km</text>';
    }
  });
  return `<svg viewBox="0 0 540 310" role="img" aria-label="Route progress map" class="trk-map" style="max-width:100%;height:auto">
    <defs><linearGradient id="trkGrad" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="var(--orange)"/><stop offset="100%" stop-color="#5FA8E8"/></linearGradient></defs>
    <path d="M141,12 L125,32 L108,58 L88,78 L64,102 L40,128 L25,142 L47,150 L38,163 L62,172 L82,162 L96,175 L104,192 L118,226 L138,258 L163,290 L184,301 L206,278 L228,252 L246,232 L270,208 L300,190 L332,172 L352,166 L364,158 L372,146 L370,130 L380,120 L396,116 L424,120 L425,142 L440,134 L448,110 L468,120 L500,102 L516,92 L472,84 L436,98 L400,104 L378,106 L366,116 L330,114 L296,106 L261,101 L229,92 L219,76 L200,58 L172,38 Z"
          fill="var(--blue-50)" stroke="var(--line)" stroke-width="1.5" stroke-linejoin="round"/>
    <path d="M229,92 L246,76 L272,80 L302,86 L334,94 L368,100 L366,116 L330,114 L296,106 L261,101 Z"
          fill="rgba(220,20,60,.12)" stroke="var(--muted)" stroke-width="1.2" stroke-linejoin="round"/>
    <text x="135" y="220" text-anchor="middle" fill="var(--muted)" font-size="22" font-weight="800" letter-spacing="8" opacity=".15">INDIA</text>
    <text x="330" y="68" text-anchor="middle" fill="var(--muted)" font-size="11" font-weight="800" letter-spacing="4" opacity=".25">NEPAL</text>
    <path id="trkRoutePath" d="${pathD}" fill="none" stroke="url(#trkGrad)" stroke-width="3" stroke-dasharray="8 6" stroke-linecap="round"/>
    ${dotsSVG}
    <rect x="160" y="282" width="220" height="22" rx="11" fill="var(--card)" stroke="var(--line)" stroke-width="1"/>
    <text x="270" y="297" text-anchor="middle" fill="var(--ink)" font-size="10" font-weight="600">🚌 Total: ≈ 1,600 km · ~30 hrs · ${stops.length} stops</text>
    <g id="trkBusMarker" data-pct="${p}" data-rev="${reverse ? 1 : 0}">
      <circle r="14" fill="var(--card)" stroke="var(--orange)" stroke-width="2.5"/>
      <text y="5" text-anchor="middle" font-size="14">🚌</text>
    </g>
  </svg>`;
}
/* Place the bus marker at N% along the path (after the SVG is in the DOM). */
function positionTrackMarker() {
  const path = $('#trkRoutePath'), g = $('#trkBusMarker');
  if (!path || !g) return;
  const pct = parseFloat(g.getAttribute('data-pct')) || 0;
  const rev = g.getAttribute('data-rev') === '1';
  const L = path.getTotalLength();
  const pt = path.getPointAtLength((rev ? (100 - pct) : pct) / 100 * L);
  g.setAttribute('transform', 'translate(' + pt.x + ',' + pt.y + ')');
}
function fmtMinutes(min) {
  const h = Math.floor(min / 60), m = Math.round(min % 60);
  return (h ? h + 'h ' : '') + m + 'm';
}

/* ================================================================
   ROUTE OVERVIEW CARD (5 Sep 2026) — the "3D" map the owner asked for.
   Real geography: India and Nepal outlines are lat/lng polygons projected
   into the SVG, the two ends are the booking's own boarding town and
   destination (coordinates from the same CITY_COORDS the distance widget
   uses), distance / hours come from ROUTE_STOPS. A bus rides the dashed
   arc on an SVG animateMotion loop; the card itself tilts in perspective
   and floats. Everything is transform / opacity only, and every motion
   stops under prefers-reduced-motion (CSS). Shown first on the ticket and
   under the home search card; NOT a live position (the trip page has that).
================================================================ */
const ROV_INDIA = [[34.6,74.0],[32.8,74.3],[31.9,76.6],[30.2,79.0],[28.8,80.2],[27.6,82.4],[26.7,84.5],[26.5,86.6],[26.8,88.2],[27.3,88.9],[26.1,89.8],[26.9,92.0],[28.2,95.6],[27.2,97.2],[24.2,94.6],[22.3,93.1],[23.6,91.2],[21.6,89.0],[20.3,86.7],[17.6,83.3],[15.8,80.3],[13.0,80.3],[10.3,79.9],[8.1,77.5],[8.8,76.5],[12.9,74.8],[15.4,73.8],[19.0,72.8],[20.9,72.7],[22.3,72.0],[20.8,70.3],[22.6,68.6],[23.8,68.2],[24.3,69.0],[27.0,70.2],[29.9,73.4],[32.2,74.5]];
const ROV_NEPAL = [[30.4,80.1],[29.6,80.2],[28.6,80.5],[28.1,80.9],[27.6,82.2],[27.4,83.4],[26.8,85.2],[26.5,86.9],[26.4,88.1],[27.3,88.2],[27.9,87.1],[28.3,85.5],[29.0,84.0],[29.7,82.6],[30.3,81.2]];
function rovProject(lat, lng) { return [Math.round((24 + (lng - 66) * 19) * 10) / 10, Math.round((24 + (36 - lat) * 12.5) * 10) / 10]; }
function rovCoords(name) {
  const n = String(name || '').toLowerCase();
  const key = Object.keys(CITY_COORDS).find(c => n.indexOf(c) >= 0) || (n.indexOf('vadodara') >= 0 ? 'barauda' : (n.indexOf('bhupal') >= 0 || n.indexOf('emli') >= 0 ? 'ahmedabad' : (n.indexOf('hari parking') >= 0 || n.indexOf('nana') >= 0 ? 'chiloda' : null)));
  return key ? { lat: CITY_COORDS[key][0], lng: CITY_COORDS[key][1] } : null;
}
function rovCode(name) {
  return ({ Surat: 'STV', Rupaidiha: 'RPD', Ahmedabad: 'AMD', Nepalgunj: 'NPJ', Mehsana: 'MSN', Baroda: 'BRC', Vadodara: 'BRC' })[String(name || '').trim()]
    || String(name || '---').replace(/[^A-Za-z]/g, '').slice(0, 3).toUpperCase();
}
function rovStopKm(name) {
  const n = String(name || '').toLowerCase();
  const s = ROUTE_STOPS.find(x => n.indexOf(x.name.toLowerCase().split(',')[0]) >= 0 || x.name.toLowerCase().indexOf(n.split(/[ ·(]/)[0]) === 0);
  return s ? { km: s.km, m: s.m } : null;
}
/* opts: { from, to, reverse?, compact?, title? } — from/to are town names. */
function routeOverviewSVG(opts) {
  opts = opts || {};
  const fromName = String(opts.from || 'Surat'), toName = String(opts.to || 'Rupaidiha');
  const a = rovCoords(fromName) || { lat: 21.17, lng: 72.831 }, b = rovCoords(toName) || { lat: 28.06, lng: 81.617 };
  const A = rovProject(a.lat, a.lng), B = rovProject(b.lat, b.lng);
  // Arc: quadratic curve bowed to the north-west, like a flight path.
  const mx = (A[0] + B[0]) / 2, my = (A[1] + B[1]) / 2;
  const dx = B[0] - A[0], dy = B[1] - A[1], len = Math.max(1, Math.hypot(dx, dy));
  const bow = Math.min(90, len * 0.22);
  const C = [mx + (dy / len) * bow, my - (dx / len) * bow];
  const path = 'M' + A[0] + ',' + A[1] + ' Q' + C[0] + ',' + C[1] + ' ' + B[0] + ',' + B[1];
  const poly = pts => pts.map(p => rovProject(p[0], p[1]).join(',')).join(' ');
  const isNep = n => /rupaidiha|nepalgunj|kohalpur/i.test(n);
  const flag = n => isNep(n) ? 'NP' : 'IN';
  const sub  = n => isNep(n) ? 'INDIA–NEPAL BORDER' : 'GUJARAT · IST';
  const ka = rovStopKm(fromName), kb = rovStopKm(toName);
  const km = (ka && kb) ? Math.abs(kb.km - ka.km) : 1600, hrs = (ka && kb) ? Math.round(Math.abs(kb.m - ka.m) / 60) : 30;
  const dur = Math.max(6, Math.min(14, 6 + km / 250)) + 's';
  const lblA = [A[0] + (A[0] < 360 ? -8 : 8), A[1] + 26], lblB = [B[0] + (B[0] < 360 ? -8 : 8), B[1] - 18];
  const anchorA = A[0] < 360 ? 'start' : 'end', anchorB = B[0] < 360 ? 'start' : 'end';
  const uid = 'rov' + Math.floor(Math.random() * 1e6);
  return `<div class="rov rov-anim${opts.compact ? ' rov-compact' : ''}"><div class="rov-3d">
  <svg viewBox="0 0 720 430" class="rov-svg" role="img" aria-label="Route overview ${esc(fromName)} to ${esc(toName)}">
    <defs>
      <radialGradient id="${uid}g" cx="18%" cy="8%" r="70%"><stop offset="0" stop-color="#8a4a1c" stop-opacity=".55"/><stop offset=".55" stop-color="#1b2b55" stop-opacity="0"/></radialGradient>
      <pattern id="${uid}d" width="26" height="26" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="1.5" r="1.1" fill="#fff" opacity=".08"/></pattern>
      <filter id="${uid}s" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="6" stdDeviation="6" flood-color="#000" flood-opacity=".35"/></filter>
    </defs>
    <rect width="720" height="430" rx="26" fill="#0f1f45"/><rect width="720" height="430" rx="26" fill="url(#${uid}g)"/><rect width="720" height="430" rx="26" fill="url(#${uid}d)"/>
    <text x="34" y="46" class="rov-eyebrow">ROUTE OVERVIEW</text>
    <text x="686" y="46" class="rov-eyebrow" text-anchor="end">${esc(rovCode(fromName))} → ${esc(rovCode(toName))}</text>
    <g class="rov-land">
      <polygon pathLength="1" points="${poly(ROV_INDIA)}" fill="rgba(255,255,255,.07)" stroke="rgba(255,255,255,.28)" stroke-width="1.4" stroke-linejoin="round"/>
      <polygon pathLength="1" points="${poly(ROV_NEPAL)}" fill="rgba(220,20,60,.22)" stroke="rgba(255,255,255,.3)" stroke-width="1.2" stroke-linejoin="round"/>
      <text x="330" y="330" class="rov-country">INDIA</text>
      <text x="560" y="128" class="rov-country rov-country-sm">NEPAL</text>
    </g>
    <path d="${path}" class="rov-glow"/>
    <path d="${path}" pathLength="1" class="rov-trace"/>
    <path d="${path}" class="rov-line"/>
    <text class="rov-dist" text-anchor="middle"><textPath href="#${uid}p" startOffset="50%">≈ ${km.toLocaleString('en-IN')} km · ~${hrs} hrs</textPath></text>
    <path id="${uid}p" d="${path}" fill="none" stroke="none" transform="translate(0,-12)"/>
    <g class="rov-end rov-from" transform="translate(${A[0]},${A[1]})"><circle r="16" class="rov-pulse"/><circle r="8" fill="#F07C1F" stroke="#fff" stroke-width="3"/></g>
    <g class="rov-end rov-to" transform="translate(${B[0]},${B[1]})"><circle r="16" class="rov-pulse rov-pulse-b"/><circle r="8" fill="#5FA8E8" stroke="#fff" stroke-width="3"/></g>
    <text x="${lblA[0]}" y="${lblA[1]}" text-anchor="${anchorA}" class="rov-city rov-from-lbl"><tspan class="rov-flag">${flag(fromName)}</tspan> ${esc(fromName)}</text>
    <text x="${lblA[0]}" y="${lblA[1] + 20}" text-anchor="${anchorA}" class="rov-sub rov-from-lbl">${sub(fromName)}</text>
    <text x="${lblB[0]}" y="${lblB[1]}" text-anchor="${anchorB}" class="rov-city rov-to-lbl"><tspan class="rov-flag">${flag(toName)}</tspan> ${esc(toName)}</text>
    <text x="${lblB[0]}" y="${lblB[1] + 20}" text-anchor="${anchorB}" class="rov-sub rov-to-lbl">${sub(toName)}</text>
    <g class="rov-bus" filter="url(#${uid}s)"><circle r="17" fill="#fff"/><text y="6" text-anchor="middle" font-size="18">🚌</text>
      <animateMotion dur="${dur}" repeatCount="indefinite" path="${path}" calcMode="linear" keyPoints="0;1" keyTimes="0;1"/></g>
    <line x1="34" y1="376" x2="686" y2="376" stroke="rgba(255,255,255,.18)" stroke-dasharray="3 5"/>
    <text x="34" y="410" class="rov-foot"><tspan class="rov-flag">IN</tspan> India <tspan fill="#F07C1F">⇄</tspan> <tspan class="rov-flag">NP</tspan> Nepal</text>
    <text x="686" y="410" class="rov-foot rov-foot-r" text-anchor="end">ROUTE OVERVIEW · NOT LIVE POSITION</text>
  </svg></div></div>`;
}
/* Home: the card follows the chosen direction + town. */
function renderHomeRouteMap() {
  const box = document.getElementById('homeRouteMap'); if (!box) return;
  const townSel = document.getElementById('pointSel');
  const town = (townSel && townSel.value) || 'Surat';
  const dir = (typeof searchDir !== 'undefined' && searchDir === 'back') ? 'back' : 'go';
  const hub = (typeof SHG_NEP_HUB !== 'undefined') ? SHG_NEP_HUB : 'Rupaidiha';
  const key = dir + '|' + town;
  if (box.getAttribute('data-key') === key) return;
  box.setAttribute('data-key', key);
  box.innerHTML = routeOverviewSVG(dir === 'go' ? { from: town, to: hub } : { from: hub, to: town });
  rovAttachTilt(box);
}
/* Pointer tilt for the 3D route card (17 Sep 2026) — desktop, hover-capable
   pointers only: the card follows the cursor a few degrees while hovered and
   hands back to its float animation on leave. Delegated to the container
   (the card is re-rendered on every direction/town change), rAF-throttled,
   transform-only. */
function rovAttachTilt(box) {
  if (!box || box._rovTilt) return;
  var mq = window.matchMedia ? window.matchMedia('(hover:hover) and (pointer:fine)') : null;
  if (!mq || !mq.matches) return;
  box._rovTilt = true;
  var raf = 0, px = 0, py = 0;
  box.addEventListener('pointermove', function (e) {
    var rov = box.querySelector('.rov'), card = rov && rov.querySelector('.rov-3d');
    if (!card) return;
    var r = rov.getBoundingClientRect();
    px = (e.clientX - r.left) / Math.max(1, r.width) - .5;
    py = (e.clientY - r.top) / Math.max(1, r.height) - .5;
    rov.classList.add('rov-hover');
    if (raf) return;
    raf = requestAnimationFrame(function () {
      raf = 0;
      card.style.transform = 'rotateX(' + (10 - py * 10).toFixed(2) + 'deg) rotateY(' + (px * 12).toFixed(2) + 'deg) scale(.985)';
    });
  });
  box.addEventListener('pointerleave', function () {
    var rov = box.querySelector('.rov'), card = rov && rov.querySelector('.rov-3d');
    if (!rov) return;
    rov.classList.remove('rov-hover');
    if (card) card.style.transform = '';
  });
}
/* Navigator loader (17 Sep 2026): while MapLibre boots, the same route card
   draws itself inside #snLoad instead of a bare spinner. Idempotent. */
function snLoadMapInit() {
  try {
    var m = document.getElementById('snLoadMap');
    if (m && !m.firstChild) m.innerHTML = routeOverviewSVG({ from: 'Surat', to: 'Rupaidiha', compact: true });
  } catch (e) {}
}
/* ================================================================
   [JS] 10f. DISTANCE TO BOARDING POINT — the PASSENGER's own phone
   location vs the fixed boarding-point coordinates (NOT bus GPS —
   that's section 10e). Pure client-side: navigator.geolocation +
   Haversine + an admin-configured assumed average speed. Deliberately
   kept separate from booking/commission/loyalty logic.
================================================================ */
/* Fallback coordinates for known corridor cities (used when a stored
   route predates the [lat,lng] suffix in its boarding points). */
const CITY_COORDS = {
  'surat': [21.170, 72.831], 'barauda': [22.307, 73.181],
  'ahmedabad': [23.022, 72.571], 'chiloda': [23.157, 72.655],
  'mehsana': [23.588, 72.369], 'unjha': [23.804, 72.391], 'sidhpur': [23.917, 72.373],
  'palanpur': [24.171, 72.438], 'visnagar': [23.700, 72.554],
  'nepalgunj': [28.050, 81.616], 'rupaidiha': [28.060, 81.617], 'kohalpur': [28.196, 81.700]
};
function bpCoords(bpRaw) {
  const p = parseBP(bpRaw);
  if (p.lat != null && p.lng != null) return { lat: p.lat, lng: p.lng, name: p.name };
  const key = Object.keys(CITY_COORDS).find(c => p.name.toLowerCase().indexOf(c) === 0);
  return key ? { lat: CITY_COORDS[key][0], lng: CITY_COORDS[key][1], name: p.name } : null;
}
/* One shared button+result widget (ticket page & My Bookings). */
function distanceWidgetHTML(b) {
  const p = parseBP(b.boarding);
  return '<div class="dist-box"><button class="btn btn-ghost btn-sm" type="button" data-dist="' + esc(b.id) + '">'
    + tf('distBtn', { c: esc(p.name) }) + '</button></div>';
}
function runDistanceCheck(bookingId, box) {
  const b = DB.bookings.find(x => x.id === bookingId); if (!b || !box) return;
  const p = parseBP(b.boarding);
  const label = esc(p.name + (p.landmark ? ' (' + p.landmark + ')' : ''));
  const target = bpCoords(b.boarding);
  const note = '<br><small style="color:var(--muted)">' + t('distNote') + '</small>';
  if (!target || !navigator.geolocation) {
    box.innerHTML = '<div class="verify-note">📍 <span>' + tf('distNoGeo', { c: label }) + '</span></div>';
    return;
  }
  box.innerHTML = '<div class="verify-note">⏳ <span>' + t('distLocating') + '</span></div>';
  navigator.geolocation.getCurrentPosition(pos => {
    const km = haversineKm(pos.coords.latitude, pos.coords.longitude, target.lat, target.lng);
    const v = S().assumedTravelSpeedKmh;
    const mins = km / v * 60;
    box.innerHTML = '<div class="verify-note" style="background:var(--blue-50);border-color:var(--blue-100)">📍 <span>'
      + tf('distResult', { km: km < 10 ? km.toFixed(1) : Math.round(km), c: label, v: v, t: fmtMinutes(mins) })
      + note + '</span></div>';
  }, () => {
    box.innerHTML = '<div class="verify-note">📍 <span>' + tf('distDenied', { c: label }) + '</span></div>';
  }, { timeout: 12000, maximumAge: 120000 });
}
function runGpsDistanceAll() {
  var resultBox = $('#gpsDistResult');
  var cells = document.querySelectorAll('.gps-col');
  var headers = document.querySelectorAll('.gps-col-h');
  if (!navigator.geolocation) {
    if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = '<div class="verify-note">📡 GPS not available on this device</div>'; }
    return;
  }
  if (resultBox) { resultBox.style.display = 'block'; resultBox.innerHTML = '<div class="verify-note" style="background:var(--blue-50);border-color:var(--blue-100)">⏳ <span>Locating you... · तपाईंको स्थान खोज्दै... · आपकी लोकेशन खोज रहे हैं...</span></div>'; }
  headers.forEach(function(h) { h.style.display = 'table-cell'; });
  cells.forEach(function(c) { c.style.display = 'table-cell'; c.textContent = '...'; });
  navigator.geolocation.getCurrentPosition(function(pos) {
    var lat = pos.coords.latitude, lng = pos.coords.longitude;
    var minD = Infinity, nearest = null, nearIdx = -1;
    cells.forEach(function(c, i) {
      var sLat = parseFloat(c.getAttribute('data-lat'));
      var sLng = parseFloat(c.getAttribute('data-lng'));
      if (isNaN(sLat) || isNaN(sLng)) { c.textContent = '—'; return; }
      var d = haversineKm(lat, lng, sLat, sLng);
      if (d < minD) { minD = d; nearest = ROUTE_STOPS[i]; nearIdx = i; }
      var mins = Math.round(d / 40 * 60);
      c.innerHTML = '<b style="color:var(--blue)">' + (d < 1 ? (d * 1000).toFixed(0) + ' m' : d.toFixed(1) + ' km') + '</b><br><small style="color:var(--muted)">~' + mins + ' min</small>';
    });
    if (nearest && resultBox) {
      var tr = document.querySelectorAll('#routeDistTbody tr')[nearIdx];
      if (tr) { tr.style.background = 'var(--blue-50)'; tr.style.fontWeight = '700'; }
      resultBox.innerHTML = '<div class="verify-note" style="background:linear-gradient(135deg,var(--blue-50),rgba(255,152,0,.08));border-color:var(--blue-100);border-left:4px solid var(--orange)">'
        + '<b>📍 You are nearest to: ' + nearest.name + '</b>'
        + (nearest.flag ? ' ' + nearest.flag : '')
        + '<br><span style="font-size:14px">Distance: <b>' + (minD < 1 ? (minD * 1000).toFixed(0) + ' m' : minD.toFixed(1) + ' km') + '</b>'
        + ' · ~' + Math.round(minD / 40 * 60) + ' min by road</span>'
        + '<br><small style="color:var(--muted)">Your location: ' + lat.toFixed(4) + '°N, ' + lng.toFixed(4) + '°E · Haversine straight-line distance · Actual road distance may differ</small>'
        + '</div>';
    }
  }, function() {
    if (resultBox) { resultBox.innerHTML = '<div class="verify-note">📡 Location access denied. Enable GPS in your browser settings.</div>'; }
    cells.forEach(function(c) { c.textContent = '—'; });
  }, { timeout: 15000, maximumAge: 120000 });
}
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-dist]'); if (!btn) return;
  runDistanceCheck(btn.getAttribute('data-dist'), btn.closest('.dist-box'));
});

/* ================================================================
   [JS] 10g. TICKET EXTRAS — add-to-calendar (.ics, pure JS),
   destination weather (one isolated fetch, graceful fallback),
   travel checklist (content from CONFIG). None of these touch
   booking/payment state.
================================================================ */
/* Downloadable .ics for the departure, reminder 2 hours before. */
function downloadICS(b) {
  const r = routeById(b.routeId) || {};
  const dep = (r.depTime || '09:00').split(':').map(Number);
  const d = b.date.split('-').map(Number);
  const pad = (n) => String(n).padStart(2, '0');
  const dtStart = d[0] + pad(d[1]) + pad(d[2]) + 'T' + pad(dep[0]) + pad(dep[1]) + '00';
  const durMin = durationMinutes(r);
  const end = new Date(d[0], d[1] - 1, d[2], dep[0], dep[1] + durMin);
  const dtEnd = end.getFullYear() + pad(end.getMonth() + 1) + pad(end.getDate()) + 'T' + pad(end.getHours()) + pad(end.getMinutes()) + '00';
  const escIcs = (s) => String(s || '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
  const ics = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//S Hari Global//Bus Ticket//EN',
    'BEGIN:VEVENT',
    'UID:' + b.id + '@sharihariglobal',
    'DTSTAMP:' + dtStart,
    'DTSTART:' + dtStart,
    'DTEND:' + dtEnd,
    'SUMMARY:' + escIcs('🚌 Bus ' + (r.from || '') + ' → ' + (r.to || '') + ' (' + b.id + ')'),
    'LOCATION:' + escIcs(bpLabel(b.boarding)),
    'DESCRIPTION:' + escIcs('Seats ' + seatLabelJoin(b.seats, r.type, b.bookingType) + ' · ' + (r.busName || '') + ' ' + (r.busNo || '') + ' · Carry original photo ID + ticket. Support ' + S().phone),
    'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY',
    'DESCRIPTION:' + escIcs('Leave for the bus — departs ' + (r.depTime || '') + ' from ' + parseBP(b.boarding).name),
    'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR'
  ].join('\r\n');
  const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = b.id + '.ics';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  toast(t('calDone'));
}

/* Destination weather via Open-Meteo (free, no key). Isolated fetch —
   if it fails or we're offline the ticket renders exactly the same. */
const WX_CODES = { 0: '☀️ Clear', 1: '🌤 Mostly clear', 2: '⛅ Partly cloudy', 3: '☁️ Overcast', 45: '🌫 Fog', 48: '🌫 Fog', 51: '🌦 Drizzle', 53: '🌦 Drizzle', 55: '🌧 Drizzle', 61: '🌧 Light rain', 63: '🌧 Rain', 65: '🌧 Heavy rain', 71: '🌨 Snow', 73: '🌨 Snow', 75: '❄️ Heavy snow', 80: '🌦 Showers', 81: '🌧 Showers', 82: '⛈ Heavy showers', 95: '⛈ Thunderstorm', 96: '⛈ Thunderstorm', 99: '⛈ Hailstorm' };
function loadDestinationWeather(b) {
  const box = $('#wxBox'); if (!box) return;
  const r = routeById(b.routeId) || {};
  const dest = bpCoords(b.drop) || (r.to ? bpCoords(r.to) : null);
  const daysAhead = Math.round((depTimestamp(b) - Date.now()) / 86400000);
  if (!dest || typeof fetch !== 'function' || daysAhead > 15) { box.remove(); return; }
  const url = 'https://api.open-meteo.com/v1/forecast?latitude=' + dest.lat + '&longitude=' + dest.lng
    + '&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=auto'
    + '&start_date=' + b.date + '&end_date=' + b.date;
  fetch(url).then(res => res.ok ? res.json() : Promise.reject())
    .then(j => {
      const dly = j && j.daily;
      if (!dly || !dly.time || !dly.time.length) { box.remove(); return; }
      const label = WX_CODES[dly.weather_code[0]] || '🌡';
      box.innerHTML = '<small>' + tf('wxTitle', { c: esc(parseBP(b.drop).name || r.to || '') }) + '</small>'
        + '<b>' + label + ' · ' + Math.round(dly.temperature_2m_min[0]) + '–' + Math.round(dly.temperature_2m_max[0]) + '°C</b>';
    })
    .catch(() => { box.innerHTML = '<small>' + t('wxNA') + '</small>'; });
}
/* Travel checklist — static content from CONFIG.booking.checklist. */
function checklistHTML() {
  const items = CONFIG.booking.checklist || [];
  if (!items.length) return '';
  return '<details class="cl-box"><summary>🧳 ' + t('clTitle') + '</summary><ul>'
    + items.map(i => '<li>' + esc(i) + '</li>').join('') + '</ul></details>';
}
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-ics]'); if (!btn) return;
  const b = DB.bookings.find(x => x.id === btn.getAttribute('data-ics'));
  if (b) downloadICS(b);
});
