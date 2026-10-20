<?php
/**
 * =====================================================================
 *  driver/ - SHG Driver app (20 Oct 2026).
 *
 *  The phone in the driver's cabin: sign in once (mobile + PIN from the
 *  office), tap START TRIP on today's bus, and the coach's position goes to
 *  api/driver.php every live_ping_sec seconds - into live_trips for the
 *  office Live Map, the passenger's track page and the "bus is near"
 *  WhatsApp (includes/livetrips.php, EtaAlerts::fixForTrip).
 *
 *  Built for the road, not for looks:
 *    - no map on this screen: the GPS is the only battery cost
 *    - every fix is queued in IndexedDB first and sent oldest-first, so a
 *      dead zone near the border loses nothing - it arrives late, in order
 *    - a screen wake lock keeps the GPS alive (a web page gets no GPS once
 *      the screen is off). The screen tells the driver to keep the phone on
 *      the charger, and says how long tracking was paused if it ever was.
 *
 *  Static page (no database on load) so the service worker can open it
 *  with no network at all.
 * ===================================================================== */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(self)');
?><!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#08122A">
<meta name="robots" content="noindex">
<title>SHG Driver</title>
<link rel="manifest" href="/driver/manifest.php">
<link rel="icon" href="/assets/img/icon-192.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<style>
:root{--bg:#08122A;--card:#0F1C3A;--line:#22345C;--ink:#EEF1F8;--mut:#9BA7C0;--gold:#E3BE4F;--go:#1FA463;--go2:#168A52;--stop:#D9452B;--warn:#F2C35A;--bad:#FF7A6B;color-scheme:dark}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-tap-highlight-color:transparent}
body{padding:max(14px,env(safe-area-inset-top)) max(16px,env(safe-area-inset-right)) max(18px,env(safe-area-inset-bottom)) max(16px,env(safe-area-inset-left));min-height:100vh;display:flex;flex-direction:column;gap:14px}
header{display:flex;align-items:center;gap:10px}
header img{width:34px;height:34px;border-radius:9px}
header b{font-size:17px}
header small{display:block;color:var(--mut);font-size:12.5px}
header .who{margin-left:auto;text-align:right;font-size:12.5px;color:var(--mut)}
header .who button{background:none;border:0;color:var(--gold);font:inherit;padding:2px 0;cursor:pointer}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px}
h1{font-size:20px;margin:0 0 4px}
p{margin:6px 0}
.mut{color:var(--mut);font-size:14px}
label{display:block;font-size:14px;color:var(--mut);margin:12px 0 6px}
input{width:100%;font:600 20px/1.2 system-ui,sans-serif;padding:14px;border-radius:12px;border:1px solid var(--line);background:#0A1530;color:var(--ink);letter-spacing:.04em}
input:focus{outline:2px solid var(--gold);outline-offset:1px}
.btn{display:block;width:100%;border:0;border-radius:14px;padding:16px;font:700 18px/1.2 system-ui,sans-serif;color:#fff;background:#2B4C8C;cursor:pointer;margin-top:14px}
.btn:disabled{opacity:.5}
.btn.go{background:linear-gradient(180deg,var(--go),var(--go2));font-size:24px;padding:26px 16px}
.btn small{display:block;font-weight:500;font-size:14px;opacity:.9;margin-top:4px}
.trip{display:block;width:100%;text-align:left;background:#0A1530;border:1px solid var(--line);border-radius:14px;padding:14px;color:var(--ink);font:inherit;margin-top:10px;cursor:pointer}
.trip b{font-size:17px}
.trip .m{color:var(--mut);font-size:14px;margin-top:2px}
.trip.on{border-color:var(--go);box-shadow:0 0 0 2px rgba(31,164,99,.35)}
.err{color:var(--bad);font-weight:600;min-height:1.4em;margin-top:10px}
.big{font-size:30px;font-weight:800;letter-spacing:-.01em}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.stat{background:#0A1530;border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.stat span{display:block;color:var(--mut);font-size:12.5px}
.stat b{font-size:19px;font-variant-numeric:tabular-nums}
.dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:var(--mut);margin-right:8px;vertical-align:middle}
.dot.ok{background:var(--go);box-shadow:0 0 0 4px rgba(31,164,99,.25)}
.dot.warn{background:var(--warn)}
.dot.bad{background:var(--bad)}
.note{border-left:3px solid var(--warn);padding:8px 12px;background:#14203F;border-radius:0 10px 10px 0;font-size:14px}
.end{position:relative;overflow:hidden;background:var(--stop);margin-top:auto;user-select:none;-webkit-user-select:none;touch-action:none}
.end i{position:absolute;inset:0;width:0;background:rgba(0,0,0,.28);transition:width .1s linear}
.end span{position:relative}
[hidden]{display:none!important}
</style>
</head>
<body>
<header>
  <img src="/assets/img/icon-192.png" alt="">
  <div><b>SHG Driver</b><small>S Hari Global · बस लोकेशन</small></div>
  <div class="who" id="who" hidden><span id="whoName"></span><br><button type="button" id="logout">Sign out · लॉग आउट</button></div>
</header>

<!-- 1. Sign in -->
<section class="card" id="vLogin" hidden>
  <h1>Sign in · लॉग इन</h1>
  <p class="mut">Use your mobile number and the PIN the office gave you. · ऑफिस से मिला PIN डालें।</p>
  <form id="fLogin" autocomplete="off">
    <label for="phone">Mobile number · मोबाइल नंबर</label>
    <input id="phone" type="tel" inputmode="numeric" maxlength="15" required>
    <label for="pin">PIN</label>
    <input id="pin" type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required>
    <button class="btn" type="submit" id="bLogin">Sign in · लॉग इन</button>
    <div class="err" id="eLogin" role="alert"></div>
  </form>
</section>

<!-- 2. Pick today's bus -->
<section class="card" id="vPick" hidden>
  <h1>Which bus are you driving? · कौन सी बस?</h1>
  <p class="mut">Tap your bus, then START TRIP. · अपनी बस चुनें, फिर START TRIP दबाएं।</p>
  <div id="tripList"></div>
  <button class="btn go" type="button" id="bStart" disabled>START TRIP<small>यात्रा शुरू करें</small></button>
  <div class="err" id="ePick" role="alert"></div>
</section>

<!-- 3. Trip running -->
<section id="vRun" hidden style="display:flex;flex-direction:column;gap:14px;flex:1">
  <div class="card">
    <div class="mut" id="rRoute"></div>
    <div class="big" id="rState"><span class="dot" id="rDot"></span>Starting GPS…</div>
    <div class="row">
      <div class="stat"><span>Speed · गति</span><b id="rSpeed">–</b></div>
      <div class="stat"><span>GPS accuracy · सटीकता</span><b id="rAcc">–</b></div>
      <div class="stat"><span>Last sent · भेजा</span><b id="rSent">–</b></div>
      <div class="stat"><span>Waiting to send · बाकी</span><b id="rQueue">0</b></div>
    </div>
  </div>
  <div class="note" id="rNote">Keep this screen open and the phone on the charger. · यह स्क्रीन खुली रखें और फोन चार्जर पर लगाएं।</div>
  <div class="err" id="eRun" role="alert"></div>
  <button class="btn end" type="button" id="bEnd"><i id="endFill"></i><span>Hold to END TRIP · दबाकर रखें: यात्रा समाप्त</span></button>
</section>

<script>
(function () {
  'use strict';
  var API = '/api/driver.php';
  var K = { token: 'shgDrv:token', trip: 'shgDrv:trip', seq: 'shgDrv:seq', name: 'shgDrv:name' };
  var S = { token: null, trip: null, pingSec: 15, pick: null, watchId: null, fix: null, lastRec: 0,
            lastSentAt: 0, sending: false, wake: null, hiddenAt: 0, timer: null, ending: false };
  var $ = function (id) { return document.getElementById(id); };

  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function show(v) { ['vLogin', 'vPick', 'vRun'].forEach(function (id) { $(id).hidden = id !== v; }); $('who').hidden = v === 'vLogin'; }

  /* ---------- queue: IndexedDB, memory if the browser refuses ---------- */
  var mem = [], dbp = null;
  function idb() {
    if (dbp) return dbp;
    dbp = new Promise(function (res) {
      try {
        var r = indexedDB.open('shg-driver', 1);
        r.onupgradeneeded = function () { r.result.createObjectStore('q', { keyPath: 'seq' }); };
        r.onsuccess = function () { res(r.result); };
        r.onerror = function () { res(null); };
      } catch (e) { res(null); }
    });
    return dbp;
  }
  function qPut(p) {
    return idb().then(function (db) {
      if (!db) { mem.push(p); return; }
      return new Promise(function (res) { var tx = db.transaction('q', 'readwrite'); tx.objectStore('q').put(p); tx.oncomplete = res; tx.onerror = function () { mem.push(p); res(); }; });
    });
  }
  function qAll() {
    return idb().then(function (db) {
      if (!db) return mem.slice();
      return new Promise(function (res) {
        var out = [], tx = db.transaction('q', 'readonly'), cur = tx.objectStore('q').openCursor();
        cur.onsuccess = function () { var c = cur.result; if (c) { out.push(c.value); c.continue(); } else res(out.concat(mem)); };
        cur.onerror = function () { res(mem.slice()); };
      });
    });
  }
  /* drop everything acknowledged for this trip, and anything left from another trip */
  function qDrop(tripId, ack) {
    mem = mem.filter(function (p) { return p.tripId === tripId && p.seq > ack; });
    return idb().then(function (db) {
      if (!db) return;
      return new Promise(function (res) {
        var tx = db.transaction('q', 'readwrite'), cur = tx.objectStore('q').openCursor();
        cur.onsuccess = function () { var c = cur.result; if (!c) return; if (c.value.tripId !== tripId || c.value.seq <= ack) c.delete(); c.continue(); };
        tx.oncomplete = res; tx.onerror = res;
      });
    });
  }

  function nextSeq() { var n = (parseInt(lsGet(K.seq) || '0', 10) || 0) + 1; lsSet(K.seq, String(n)); return n; }
  function bumpSeq(min) { if ((parseInt(lsGet(K.seq) || '0', 10) || 0) < min) lsSet(K.seq, String(min)); }

  /* ---------- server ---------- */
  function api(method, body) {
    var opt = { method: method, headers: { 'X-Driver-Token': S.token || '' }, cache: 'no-store' };
    var url = API;
    if (method === 'GET') url += '?action=' + encodeURIComponent(body.action);
    else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { j.status = r.status; return j; });
    });
  }
  function signedOut() {
    stopGps(); S.token = null; S.trip = null; lsSet(K.token, null); lsSet(K.trip, null);
    show('vLogin');
  }

  /* ---------- sign in ---------- */
  $('fLogin').addEventListener('submit', function (e) {
    e.preventDefault();
    $('eLogin').textContent = ''; $('bLogin').disabled = true;
    api('POST', { action: 'login', phone: $('phone').value, pin: $('pin').value }).then(function (j) {
      $('bLogin').disabled = false;
      if (!j.ok) { $('eLogin').textContent = j.error || 'Could not sign in.'; return; }
      S.token = j.data.token; lsSet(K.token, S.token); lsSet(K.name, j.data.driver.name);
      $('pin').value = '';
      loadMe();
    }).catch(function () { $('bLogin').disabled = false; $('eLogin').textContent = 'No internet. Try again. · इंटरनेट नहीं है।'; });
  });
  $('logout').addEventListener('click', function () {
    if (S.trip && !window.confirm('A trip is running. Sign out and stop sending the position?')) return;
    api('POST', { action: 'logout' }).catch(function () {});
    signedOut();
  });

  /* ---------- pick + start ---------- */
  function fmtDate(d) { var p = String(d).split('-'); return p.length === 3 ? p[2] + '/' + p[1] : d; }
  function loadMe() {
    $('whoName').textContent = lsGet(K.name) || '';
    return api('GET', { action: 'me' }).then(function (j) {
      if (j.status === 401) { signedOut(); return; }
      if (!j.ok) { $('ePick').textContent = j.error || 'Could not load.'; show('vPick'); return; }
      S.pingSec = j.data.pingSec || 15;
      $('whoName').textContent = j.data.driver.name;
      if (j.data.trip) {
        var t = (j.data.trips || []).find(function (x) { return x.scheduleId === j.data.trip.scheduleId; }) || {};
        S.trip = { tripId: j.data.trip.tripId, scheduleId: j.data.trip.scheduleId, route: t.route || '', bus: t.bus || '', dep: t.dep || '', date: t.date || '' };
        bumpSeq(j.data.trip.lastSeq || 0);
        lsSet(K.trip, JSON.stringify(S.trip));
        run();
        return;
      }
      if (S.trip) {
        // The server has no running trip for this phone any more (ended by the office): stop.
        stopGps(); S.trip = null; lsSet(K.trip, null); qDrop(-1, 0);
      }
      renderTrips(j.data.trips || []);
    }).catch(function () {
      // Offline with a trip already running: keep tracking from the saved trip.
      if (S.trip) { run(); return; }
      $('ePick').textContent = 'No internet. The list needs a connection once. · इंटरनेट नहीं है।';
      show('vPick');
    });
  }
  function renderTrips(list) {
    var box = $('tripList'); box.innerHTML = ''; S.pick = null; $('bStart').disabled = true;
    if (!list.length) { box.innerHTML = '<p class="mut">No bus is due now. Ask the office. · अभी कोई बस नहीं है।</p>'; }
    list.forEach(function (t, i) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'trip';
      b.innerHTML = '<b></b><div class="m"></div>';
      b.querySelector('b').textContent = t.route;
      b.querySelector('.m').textContent = fmtDate(t.date) + ' · ' + t.dep + (t.bus ? ' · ' + t.bus : '') + (t.tripStatus === 'running' ? ' · already running (other phone)' : '');
      b.setAttribute('aria-label', t.route + ', ' + b.querySelector('.m').textContent);
      b.addEventListener('click', function () {
        box.querySelectorAll('.trip').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on'); S.pick = t; $('bStart').disabled = false;
      });
      box.appendChild(b);
      if (i === 0 && list.length === 1) b.click();
    });
    show('vPick');
  }
  $('bStart').addEventListener('click', function () {
    if (!S.pick) return;
    $('bStart').disabled = true; $('ePick').textContent = '';
    askGps().then(function () {
      return api('POST', { action: 'start', scheduleId: S.pick.scheduleId });
    }).then(function (j) {
      $('bStart').disabled = false;
      if (j.status === 401) { signedOut(); return; }
      if (!j.ok) { $('ePick').textContent = j.error || 'Could not start.'; return; }
      S.pingSec = j.data.pingSec || S.pingSec;
      S.trip = { tripId: j.data.trip.tripId, scheduleId: S.pick.scheduleId, route: S.pick.route, bus: S.pick.bus, dep: S.pick.dep, date: S.pick.date };
      bumpSeq(j.data.trip.lastSeq || 0);
      lsSet(K.trip, JSON.stringify(S.trip));
      run();
    }).catch(function (err) {
      $('bStart').disabled = false;
      $('ePick').textContent = err && err.gps ? err.gps : 'No internet. Try again when you have signal. · इंटरनेट नहीं है।';
    });
  });
  /* Ask for location once, from the tap, so the permission prompt appears now and not mid-journey. */
  function askGps() {
    return new Promise(function (res, rej) {
      if (!navigator.geolocation) { rej({ gps: 'This phone has no GPS in the browser. Use Chrome.' }); return; }
      navigator.geolocation.getCurrentPosition(function () { res(); }, function (e) {
        if (e && e.code === 1) rej({ gps: 'Location is blocked. Allow location for this site in Chrome settings. · लोकेशन की अनुमति दें।' });
        else res();   // a slow first fix is fine: the trip starts and the GPS keeps trying
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
    });
  }

  /* ---------- running ---------- */
  function run() {
    show('vRun');
    $('rRoute').textContent = [S.trip.route, S.trip.bus, S.trip.dep ? fmtDate(S.trip.date) + ' ' + S.trip.dep : ''].filter(Boolean).join(' · ');
    startGps(); keepAwake();
    if (S.timer) clearInterval(S.timer);
    S.timer = setInterval(tick, 1000);
    flush();
  }
  function startGps() {
    if (S.watchId != null || !navigator.geolocation) return;
    S.watchId = navigator.geolocation.watchPosition(onFix, function (e) {
      setState(e && e.code === 1 ? 'bad' : 'warn', e && e.code === 1 ? 'Location blocked' : 'Searching GPS…');
      if (e && e.code === 1) $('eRun').textContent = 'Allow location for this site in Chrome settings. · लोकेशन की अनुमति दें।';
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
  }
  function stopGps() {
    if (S.watchId != null && navigator.geolocation) navigator.geolocation.clearWatch(S.watchId);
    S.watchId = null; if (S.timer) { clearInterval(S.timer); S.timer = null; }
    if (S.wake) { try { S.wake.release(); } catch (e) {} S.wake = null; }
  }
  function onFix(pos) {
    var c = pos.coords;
    S.fix = { lat: c.latitude, lng: c.longitude, acc: c.accuracy, kmh: c.speed != null && c.speed >= 0 ? c.speed * 3.6 : null, brg: c.heading != null && !isNaN(c.heading) ? c.heading : null, t: pos.timestamp || Date.now() };
    $('eRun').textContent = '';
    var now = Date.now();
    /* parked: one point a minute is enough; moving: one per ping interval */
    var gap = (S.fix.kmh != null && S.fix.kmh < 3) ? 60000 : S.pingSec * 1000;
    if (S.trip && now - S.lastRec >= gap - 500) {
      S.lastRec = now;
      qPut({ seq: nextSeq(), tripId: S.trip.tripId, t: S.fix.t, lat: +S.fix.lat.toFixed(7), lng: +S.fix.lng.toFixed(7),
             kmh: S.fix.kmh != null ? Math.round(S.fix.kmh * 10) / 10 : null, brg: S.fix.brg != null ? Math.round(S.fix.brg) : null,
             acc: S.fix.acc != null ? Math.round(S.fix.acc) : null });
    }
  }
  function flush() {
    if (S.sending || !S.trip || !S.token) return;
    var trip = S.trip;
    S.sending = true;
    qAll().then(function (all) {
      var mine = all.filter(function (p) { return p.tripId === trip.tripId; }).sort(function (a, b) { return a.seq - b.seq; }).slice(0, 200);
      if (!mine.length) { S.sending = false; return; }
      var pts = mine.map(function (p) { return { seq: p.seq, t: p.t, lat: p.lat, lng: p.lng, kmh: p.kmh, brg: p.brg, acc: p.acc }; });
      return api('POST', { action: 'ping', tripId: trip.tripId, points: pts }).then(function (j) {
        if (j.status === 401) { S.sending = false; signedOut(); return; }
        if (j.status === 409) { S.sending = false; tripGone('This trip is now running on another phone. · यह यात्रा दूसरे फोन पर चल रही है।'); return; }
        if (!j.ok) { S.sending = false; $('eRun').textContent = j.error || 'Server refused the position.'; return; }
        S.lastSentAt = Date.now();
        if (j.data.pingSec) S.pingSec = j.data.pingSec;
        return qDrop(trip.tripId, j.data.ack).then(function () {
          S.sending = false;
          if (j.data.ended) { tripGone('The office ended this trip. · ऑफिस ने यात्रा समाप्त की।'); return; }
          if (mine.length === 200) flush();   // more waiting after a dead zone: keep going
        });
      });
    }).catch(function () { S.sending = false; });
  }
  function tripGone(msg) {
    stopGps(); S.trip = null; lsSet(K.trip, null);
    qDrop(-1, 0);
    loadMe().then(function () { $('ePick').textContent = msg; });
  }
  var lastFlush = 0, lastQ = -1;
  function tick() {
    var now = Date.now();
    if (now - lastFlush >= S.pingSec * 1000) { lastFlush = now; flush(); }
    var f = S.fix, age = f ? Math.round((now - f.t) / 1000) : null;
    if (!f) setState('warn', 'Searching GPS… · GPS खोज रहा है');
    else if (age > 60) setState('bad', 'No GPS for ' + Math.round(age / 60) + ' min');
    else if (f.acc > 100) setState('warn', 'Weak GPS · कमजोर GPS');
    else setState('ok', 'Tracking ON · चालू');
    $('rSpeed').textContent = f && f.kmh != null ? Math.round(f.kmh) + ' km/h' : '–';
    $('rAcc').textContent = f && f.acc != null ? '±' + Math.round(f.acc) + ' m' : '–';
    $('rSent').textContent = S.lastSentAt ? ago(now - S.lastSentAt) : (navigator.onLine === false ? 'offline' : '–');
    if (now % 5000 < 1000) {
      qAll().then(function (all) { var n = all.filter(function (p) { return S.trip && p.tripId === S.trip.tripId; }).length; if (n !== lastQ) { lastQ = n; $('rQueue').textContent = String(n); } });
    }
  }
  function ago(ms) { var s = Math.round(ms / 1000); return s < 60 ? s + ' s ago' : Math.round(s / 60) + ' min ago'; }
  function setState(kind, text) {
    $('rDot').className = 'dot ' + kind;
    var st = $('rState'); if (st.lastChild && st.lastChild.nodeType === 3) st.lastChild.nodeValue = text; else st.appendChild(document.createTextNode(text));
  }

  /* keep the screen (and so the GPS) on */
  function keepAwake() {
    if (!('wakeLock' in navigator) || S.wake || document.visibilityState !== 'visible') return;
    navigator.wakeLock.request('screen').then(function (w) { S.wake = w; w.addEventListener('release', function () { S.wake = null; }); }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (!S.trip) return;
    if (document.visibilityState === 'hidden') { S.hiddenAt = Date.now(); return; }
    keepAwake(); flush();
    if (S.hiddenAt && Date.now() - S.hiddenAt > 90000) {
      $('rNote').textContent = 'Tracking was paused for ' + Math.round((Date.now() - S.hiddenAt) / 60000) + ' min while the screen was off. Keep this screen open. · स्क्रीन बंद होने पर लोकेशन रुक जाती है।';
    }
    S.hiddenAt = 0;
  });
  window.addEventListener('online', flush);

  /* END TRIP: hold for 2 seconds, so a bump on the dashboard never ends it */
  (function () {
    var b = $('bEnd'), fill = $('endFill'), t0 = 0, raf = 0;
    function step() {
      var p = Math.min(1, (Date.now() - t0) / 2000); fill.style.width = (p * 100) + '%';
      if (p >= 1) { cancel(); endTrip(); return; }
      raf = requestAnimationFrame(step);
    }
    function cancel() { t0 = 0; cancelAnimationFrame(raf); fill.style.width = '0'; }
    b.addEventListener('pointerdown', function (e) { e.preventDefault(); t0 = Date.now(); raf = requestAnimationFrame(step); });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) { b.addEventListener(ev, function () { if (t0) cancel(); }); });
    b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); endTrip(); } });
  })();
  function endTrip() {
    if (!S.trip || S.ending) return;
    S.ending = true; $('eRun').textContent = 'Ending… · समाप्त हो रही है…';
    var trip = S.trip;
    var tries = 0;
    (function sendRest() {
      flush();
      if (S.sending && tries++ < 20) { setTimeout(sendRest, 500); return; }
      api('POST', { action: 'end', tripId: trip.tripId }).then(function (j) {
        S.ending = false;
        if (j.ok || j.status === 409) { stopGps(); S.trip = null; lsSet(K.trip, null); qDrop(-1, 0); $('eRun').textContent = ''; loadMe(); }
        else $('eRun').textContent = j.error || 'Could not end the trip.';
      }).catch(function () { S.ending = false; $('eRun').textContent = 'No internet. The trip ends when you have signal; tracking stays on. · इंटरनेट आने पर दोबारा दबाएं।'; });
    })();
  }

  /* ---------- boot ---------- */
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/driver/sw.php', { scope: '/driver/' }).catch(function () {});
  }
  S.token = lsGet(K.token);
  try { S.trip = JSON.parse(lsGet(K.trip) || 'null'); } catch (e) { S.trip = null; }
  if (!S.token) { show('vLogin'); return; }
  if (S.trip) run();       // resume at once, even with no network
  loadMe();
})();
</script>
</body>
</html>
