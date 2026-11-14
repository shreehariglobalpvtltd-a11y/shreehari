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
 *  15 Nov 2026 (SHARI NAV driver actions, includes/tripevents.php):
 *    - buttons on the trip screen: "Stop pugyo", "Dhilo", "Bus bigriyo",
 *      rest start / end and a red SOS behind a confirm step. Each tap is
 *      queued like a GPS fix (localStorage) and sent with the phone's last
 *      position; SOS / breakdown reach the owner and the office at once.
 *    - the passengers by pickup (seat, first name, call, boarded tick)
 *    - "Google Maps ma kholnu": directions through the stops still ahead
 *    - continuous-driving timer (4 h gentle, 5 h strong), speed warning
 *      above driver_speed_warn_kmh, low-battery banner; the battery level
 *      rides on each GPS ping. The page is already dark, day and night,
 *      so there is no separate night style.
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
/* 14 Nov 2026 (staff 360px audit): Sign out was a 22px line of text - 40px to tap */
header .who button{min-height:40px;padding:0 0 0 12px}
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
.stat{background:#0A1530;border:1px solid var(--line);border-radius:12px;padding:10px 12px;min-width:0}
.stat span{display:block;color:var(--mut);font-size:12.5px}
.stat b{font-size:19px;font-variant-numeric:tabular-nums;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dot{display:inline-block;width:12px;height:12px;border-radius:50%;background:var(--mut);margin-right:8px;vertical-align:middle}
.dot.ok{background:var(--go);box-shadow:0 0 0 4px rgba(31,164,99,.25)}
.dot.warn{background:var(--warn)}
.dot.bad{background:var(--bad)}
.note{border-left:3px solid var(--warn);padding:8px 12px;background:#14203F;border-radius:0 10px 10px 0;font-size:14px}
.end{position:relative;overflow:hidden;background:var(--stop);margin-top:auto;user-select:none;-webkit-user-select:none;touch-action:none}
.end i{position:absolute;inset:0;width:0;background:rgba(0,0,0,.28);transition:width .1s linear}
.end span{position:relative}
/* 15 Nov 2026: trip-screen buttons, banners, passengers, sheets (every target >= 44px, fits 360px) */
.acts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.act{min-height:60px;border:1px solid var(--line);border-radius:14px;background:#13254A;color:var(--ink);font:700 16px/1.2 system-ui,sans-serif;padding:10px 8px;cursor:pointer;min-width:0}
.act small{display:block;font-weight:500;font-size:12.5px;color:var(--mut);margin-top:3px}
.act.on{border-color:var(--gold);background:#2A2410}
.btn.nav{background:#1A73E8;text-decoration:none;text-align:center}
.btn.sos{background:linear-gradient(180deg,#E5352B,#B71C1C);font-size:26px;letter-spacing:.08em;padding:18px 16px;min-height:72px}
.btn.sos small{letter-spacing:0}
.btn.warnb{background:#B8641A}
.btn.ghost{background:transparent;border:1px solid var(--line);color:var(--ink)}
.btn.call2{background:#163A2A;color:#BFF5D6;text-decoration:none;text-align:center}
.ban{border-radius:12px;padding:12px 14px;font-weight:700;font-size:15px}
.ban+.ban{margin-top:10px}
.ban.warn{background:#3A2F0B;border:1px solid var(--warn);color:#FFE6A3}
.ban.bad{background:#3B1212;border:1px solid var(--bad);color:#FFD2CC}
#rBanners:empty{display:none}
.mf summary{min-height:44px;cursor:pointer;font-weight:700;display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;list-style:none}
.mf summary::-webkit-details-marker{display:none}
.mf summary::before{content:'▸';color:var(--gold)}
.mf[open] summary::before{content:'▾'}
.mf h3{font-size:14px;color:var(--gold);margin:16px 0 4px}
.pax{display:grid;grid-template-columns:46px minmax(0,1fr) auto 44px;gap:8px;align-items:center;padding:4px 0;border-top:1px solid var(--line);min-height:52px}
.pax .seat{font-weight:800;font-variant-numeric:tabular-nums}
.pax .nm{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pax .tag{font-size:11.5px;color:var(--warn);margin-left:6px;font-weight:700}
.pax a.call{width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;background:#163A2A;color:#BFF5D6;text-decoration:none;font-size:18px}
.tick{font-size:13px;font-weight:700;color:var(--go)}
.left{font-size:13px;color:var(--mut)}
.sheet{position:fixed;inset:0;background:rgba(3,8,20,.72);display:flex;align-items:flex-end;justify-content:center;z-index:20}
.sheet .box{background:var(--card);border:1px solid var(--line);border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:88vh;overflow:auto;padding:18px 16px max(18px,env(safe-area-inset-bottom))}
.sheet h2{font-size:19px;margin:0 0 6px}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.chip{min-height:44px;padding:0 14px;border-radius:22px;border:1px solid var(--line);background:#0A1530;color:var(--ink);font:600 15px system-ui,sans-serif;cursor:pointer}
.chip.on{border-color:var(--gold);background:#2A2410}
.sheet input{font-size:17px;letter-spacing:0;margin-top:10px}
.stopb{display:block;width:100%;text-align:left;min-height:52px;background:#0A1530;border:1px solid var(--line);border-radius:12px;padding:10px 12px;color:var(--ink);font:inherit;margin-top:8px;cursor:pointer}
.stopb small{display:block;color:var(--mut);font-size:13px}
.stopb.past{opacity:.55}
.stopb.next{border-color:var(--go);box-shadow:0 0 0 2px rgba(31,164,99,.35)}
.toast{position:fixed;left:16px;right:16px;bottom:max(16px,env(safe-area-inset-bottom));z-index:30;background:#14324F;border:1px solid #2F5F8F;color:var(--ink);border-radius:12px;padding:12px 14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.toast.bad{background:#3B1212;border-color:var(--bad)}
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
  <h1 id="pickT">Which bus are you driving? · कौन सी बस?</h1>
  <p class="mut">Tap your bus, then START TRIP. · अपनी बस चुनें, फिर START TRIP दबाएं।</p>
  <p class="note" id="gpsWhy">📍 लोकेसन अनुमति माग्दा <b>Allow / अनुमति दिनुहोस्</b> थिच्नुहोस्। बस कहाँ पुग्यो भनेर अफिस र टिकट भएका यात्रुले मात्र देख्छन् — यात्रा सकिएपछि END TRIP थिच्नुहोस्, त्यसपछि लोकेसन जाँदैन। · Allow location when asked: only the office and this bus's passengers see where the bus is, and only until END TRIP.</p>
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
      <div class="stat"><span id="rDriveL">Driving · लगातार ड्राइभ</span><b id="rDrive">–</b></div>
      <div class="stat" id="stNext" hidden><span>Next stop · अर्को स्टप</span><b id="rNext">–</b></div>
    </div>
  </div>
  <div id="rBanners" aria-live="polite"></div>
  <div class="card" id="rActs" hidden>
    <div class="acts">
      <button type="button" class="act" id="aStop">📍 स्टप पुग्यो<small>Stop reached</small></button>
      <button type="button" class="act" id="aDelay">⏱ ढिलो<small>Delay</small></button>
      <button type="button" class="act" id="aBreak">🛠 बस बिग्रियो<small>Breakdown</small></button>
      <button type="button" class="act" id="aRest">☕ आराम सुरु<small>Start rest</small></button>
    </div>
    <a class="btn nav" id="bMaps" href="#" target="_blank" rel="noopener" hidden>Google Maps मा खोल्नुहोस्<small>बाँकी स्टपहरू हुँदै · directions through the stops ahead</small></a>
    <p class="mut" id="mapsWhy" hidden>Maps खोल्दा यो app पछाडि जान्छ र लोकेसन रोकिन सक्छ — छिट्टै यहीँ फर्कनुहोस्। · Tracking may pause while Maps is in front: come back to this screen soon.</p>
    <button type="button" class="btn sos" id="aSos">SOS<small>आपतकाल · Emergency</small></button>
  </div>
  <details class="card mf" id="rMf" hidden>
    <summary><span>यात्रु सूची · Passengers</span><span class="mut" id="mfTot"></span></summary>
    <div id="mfList"></div>
  </details>
  <div class="note" id="rNote">Keep this screen open and the phone on the charger. · यह स्क्रीन खुली रखें और फोन चार्जर पर लगाएं।</div>
  <div class="err" id="eRun" role="alert"></div>
  <button class="btn end" type="button" id="bEnd"><i id="endFill"></i><span>Hold to END TRIP · दबाकर रखें: यात्रा समाप्त</span></button>
</section>

<!-- one sheet for every button that needs a choice or a confirm -->
<div class="sheet" id="sheet" hidden role="dialog" aria-modal="true" aria-labelledby="shT">
  <div class="box">
    <h2 id="shT"></h2>
    <div id="shBody"></div>
    <div class="err" id="shErr" role="alert"></div>
    <button type="button" class="btn ghost" id="shClose">रद्द · Cancel</button>
  </div>
</div>
<div class="toast" id="toast" hidden role="status" aria-live="polite"></div>

<script>
(function () {
  'use strict';
  var API = '/api/driver.php';
  var K = { token: 'shgDrv:token', trip: 'shgDrv:trip', seq: 'shgDrv:seq', name: 'shgDrv:name', role: 'shgDrv:role',
            info: 'shgDrv:info', evq: 'shgDrv:evq', cfg: 'shgDrv:cfg' };
  var S = { token: null, trip: null, pingSec: 15, pick: null, watchId: null, fix: null, lastRec: 0,
            lastSentAt: 0, sending: false, wake: null, hiddenAt: 0, timer: null, ending: false,
            cfg: {}, info: null, infoAt: 0, infoLoading: false, skew: 0, rest: null, batt: null, battInit: false,
            speedHits: 0, evSending: false, toastT: 0, sosT: 0, buzzHard: false, sheetAt: 0 };
  var $ = function (id) { return document.getElementById(id); };

  function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function show(v) { ['vLogin', 'vPick', 'vRun'].forEach(function (id) { $(id).hidden = id !== v; }); $('who').hidden = v === 'vLogin'; if (v !== 'vRun') hideSheet(); }
  function el(tag, text) { var e = document.createElement(tag); if (text != null) e.textContent = text; return e; }

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
    if (method === 'GET') url += '?' + Object.keys(body).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(body[k]); }).join('&');
    else { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { j.status = r.status; return j; });
    });
  }
  /* what belongs to one trip only: the screen's data and its unsent taps */
  function clearTripData() { S.info = null; S.infoAt = 0; S.rest = null; lsSet(K.info, null); lsSet(K.evq, null); hideSheet(); }
  function signedOut() {
    stopGps(); S.token = null; S.trip = null; lsSet(K.token, null); lsSet(K.trip, null); lsSet(K.role, null);
    clearTripData();
    show('vLogin');
  }

  /* ---------- sign in ---------- */
  $('fLogin').addEventListener('submit', function (e) {
    e.preventDefault();
    $('eLogin').textContent = ''; $('bLogin').disabled = true;
    api('POST', { action: 'login', phone: $('phone').value, pin: $('pin').value }).then(function (j) {
      $('bLogin').disabled = false;
      if (!j.ok) { $('eLogin').textContent = j.error || 'Could not sign in.'; return; }
      S.token = j.data.token; lsSet(K.token, S.token); lsSet(K.name, j.data.driver.name); lsSet(K.role, j.data.driver.role || 'driver');
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
      lsSet(K.role, j.data.driver.role || 'driver'); roleText();
      if (j.data.crew) { S.cfg = j.data.crew; lsSet(K.cfg, JSON.stringify(S.cfg)); renderActs(); }
      if (j.data.trip) {
        var t = (j.data.trips || []).find(function (x) { return x.scheduleId === j.data.trip.scheduleId; }) || {};
        var prev = S.trip && S.trip.tripId === j.data.trip.tripId ? S.trip : null;
        S.trip = { tripId: j.data.trip.tripId, scheduleId: j.data.trip.scheduleId, route: t.route || '', bus: t.bus || '', dep: t.dep || '', date: t.date || '',
                   since: prev && prev.since ? prev.since : null, startedTs: j.data.trip.startedTs || null };
        bumpSeq(j.data.trip.lastSeq || 0);
        lsSet(K.trip, JSON.stringify(S.trip));
        run();
        return;
      }
      if (S.trip) {
        // The server has no running trip for this phone any more (ended by the office): stop.
        stopGps(); S.trip = null; lsSet(K.trip, null); qDrop(-1, 0); clearTripData();
      }
      renderTrips(j.data.trips || []);
    }).catch(function () {
      // Offline with a trip already running: keep tracking from the saved trip.
      if (S.trip) { run(); return; }
      $('ePick').textContent = 'No internet. The list needs a connection once. · इंटरनेट नहीं है।';
      show('vPick');
    });
  }
  /* a conductor (or host) shares the bus location exactly like the driver - only the words differ */
  function roleText() {
    var r = lsGet(K.role) || 'driver', crew = r === 'conductor' || r === 'host';
    $('pickT').textContent = crew ? 'Which bus are you on? · कुन बसमा हुनुहुन्छ?' : 'Which bus are you driving? · कौन सी बस?';
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
      clearTripData();
      /* since = when THIS phone took the wheel: a co-driver taking the trip over starts a fresh driving timer */
      S.trip = { tripId: j.data.trip.tripId, scheduleId: S.pick.scheduleId, route: S.pick.route, bus: S.pick.bus, dep: S.pick.dep, date: S.pick.date,
                 since: Date.now(), startedTs: null };
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
    startGps(); keepAwake(); initBattery();
    if (S.timer) clearInterval(S.timer);
    S.timer = setInterval(tick, 1000);
    renderActs();
    if (!S.info) {
      // the last copy of this trip's screen, so a reload in a dead zone still shows the stops and passengers
      try { var c = JSON.parse(lsGet(K.info) || 'null'); if (c && c.tripId === S.trip.tripId && c.data) applyInfo(c.data); } catch (e) {}
    }
    flush(); flushEvents(false); loadInfo();
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
    /* speed warning: two fixes in a row above the office's limit (one GPS spike is not speeding) */
    var lim = (S.cfg && S.cfg.speedWarnKmh) || 80;
    if (S.fix.kmh != null && S.fix.kmh > lim && (S.fix.acc == null || S.fix.acc <= 100)) { S.speedHits++; if (S.speedHits === 2) buzz(); }
    else S.speedHits = 0;
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
      /* the phone's battery rides along (optional: the server keeps it for the office) */
      return api('POST', { action: 'ping', tripId: trip.tripId, points: pts, battery: S.batt || undefined }).then(function (j) {
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
    qDrop(-1, 0); clearTripData();
    loadMe().then(function () { $('ePick').textContent = msg; });
  }
  var lastFlush = 0, lastQ = -1, lastEvFlush = 0;
  function tick() {
    var now = Date.now();
    if (now - lastFlush >= S.pingSec * 1000) { lastFlush = now; flush(); }
    if (now - lastEvFlush >= S.pingSec * 1000) { lastEvFlush = now; flushEvents(false); }
    if (S.trip && !S.infoLoading && now - S.infoAt > 300000 && navigator.onLine !== false && document.visibilityState === 'visible') loadInfo();
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
    renderDrive(now); banners(now);
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
    keepAwake(); flush(); flushEvents(false);
    if (S.hiddenAt && Date.now() - S.hiddenAt > 90000) {
      $('rNote').textContent = 'Tracking was paused for ' + Math.round((Date.now() - S.hiddenAt) / 60000) + ' min while the screen was off. Keep this screen open. · स्क्रीन बंद होने पर लोकेशन रुक जाती है।';
    }
    S.hiddenAt = 0;
  });
  window.addEventListener('online', function () { flush(); flushEvents(false); if (S.trip) loadInfo(); });

  /* ---------- the trip screen: stops, passengers, rest (api ?action=trip) ---------- */
  function loadInfo() {
    if (!S.trip || !S.token || S.infoLoading) return;
    var trip = S.trip;
    S.infoLoading = true; S.infoAt = Date.now();
    api('GET', { action: 'trip', tripId: trip.tripId }).then(function (j) {
      S.infoLoading = false;
      if (!S.trip || S.trip.tripId !== trip.tripId) return;
      if (j.status === 401) { signedOut(); return; }
      if (j.status === 409) { tripGone('This trip is now running on another phone. · यह यात्रा दूसरे फोन पर चल रही है।'); return; }
      if (!j.ok || !j.data) return;
      lsSet(K.info, JSON.stringify({ tripId: trip.tripId, data: j.data }));
      applyInfo(j.data);
    }).catch(function () { S.infoLoading = false; });
  }
  function applyInfo(d) {
    S.info = d;
    if (d.now) S.skew = Date.now() - d.now * 1000;
    if (S.trip && d.startedTs && !S.trip.startedTs) { S.trip.startedTs = d.startedTs; lsSet(K.trip, JSON.stringify(S.trip)); }
    if (d.rest && !evPending('rest')) S.rest = d.rest;   // a rest tap not sent yet wins over the server's older answer
    renderInfo();
  }
  function renderActs() {
    // hidden only when the server says the buttons are off / not set up (unknown = shown)
    $('rActs').hidden = !!(S.cfg && S.cfg.actions === false);
    renderRestBtn();
  }
  function renderInfo() {
    var d = S.info || {}, nav = d.nav;
    $('stNext').hidden = !(nav && nav.next);
    if (nav && nav.next) $('rNext').textContent = nav.next;
    var maps = !!(nav && nav.mapsUrl);
    $('bMaps').hidden = !maps; $('mapsWhy').hidden = !maps;
    if (maps) $('bMaps').href = nav.mapsUrl;
    renderManifest(d.manifest);
    renderRestBtn();
  }
  function renderManifest(m) {
    var box = $('mfList');
    if (!m || !m.groups) { $('rMf').hidden = true; return; }
    $('rMf').hidden = false;
    var t = m.totals || { booked: 0, boarded: 0, left: 0 };
    $('mfTot').textContent = 'बुक ' + t.booked + ' · चढे ' + t.boarded + ' · बाँकी ' + t.left;
    box.textContent = '';
    if (!m.groups.length) { var e = el('p', 'No passengers booked on this bus yet. · अहिलेसम्म कुनै यात्रु छैन।'); e.className = 'mut'; box.appendChild(e); return; }
    m.groups.forEach(function (g) {
      box.appendChild(el('h3', g.stop + ' · ' + g.boarded + '/' + g.booked));
      g.pax.forEach(function (p) {
        var row = el('div'); row.className = 'pax';
        var s = el('b', p.seat); s.className = 'seat';
        var n = el('span', p.name || '—'); n.className = 'nm';
        if (p.pending) { var tg = el('span', 'pending'); tg.className = 'tag'; n.appendChild(tg); }
        var tk = el('span', p.boarded ? '✓ चढे' : 'बाँकी'); tk.className = p.boarded ? 'tick' : 'left';
        row.appendChild(s); row.appendChild(n); row.appendChild(tk);
        if (p.phone) {
          var a = el('a', '📞'); a.className = 'call'; a.href = 'tel:' + p.phone;
          a.setAttribute('aria-label', 'Call ' + (p.name || '') + ', seat ' + p.seat);
          row.appendChild(a);
        } else row.appendChild(el('span', ''));
        box.appendChild(row);
      });
    });
  }
  /* Google Maps from where the phone is now, through the stops ahead */
  $('bMaps').addEventListener('click', function (e) {
    var nav = S.info && S.info.nav;
    if (!nav || !nav.mapsUrl) { e.preventDefault(); return; }
    var url = nav.mapsUrl;
    try {
      if (S.fix && Date.now() - S.fix.t < 120000) {
        var u = new URL(url); u.searchParams.set('origin', S.fix.lat.toFixed(6) + ',' + S.fix.lng.toFixed(6)); url = u.toString();
      }
    } catch (x) {}
    this.href = url;
  });

  /* ---------- continuous driving, speed, battery ---------- */
  function hm(min) { min = Math.max(0, Math.floor(min)); return Math.floor(min / 60) + ' h ' + (min % 60 < 10 ? '0' : '') + (min % 60) + ' m'; }
  function driveFromMs() {
    var c = [];
    if (S.trip && S.trip.since) c.push(S.trip.since);
    else if (S.trip && S.trip.startedTs) c.push(S.trip.startedTs * 1000 + S.skew);
    if (S.rest && S.rest.lastEndTs) c.push(S.rest.lastEndTs * 1000 + S.skew);
    return c.length ? Math.max.apply(null, c) : null;
  }
  function renderDrive(now) {
    if (S.rest && S.rest.resting) {
      $('rDriveL').textContent = 'Resting · आराम';
      $('rDrive').textContent = S.rest.sinceTs ? hm((now - (S.rest.sinceTs * 1000 + S.skew)) / 60000) : '–';
      return;
    }
    $('rDriveL').textContent = 'Driving · लगातार ड्राइभ';
    var from = driveFromMs();
    $('rDrive').textContent = from ? hm((now - from) / 60000) : '–';
  }
  var lastBan = null;   // null = draw on the next call, even when there is nothing to show
  function banners(now) {
    var out = [], cfg = S.cfg || {};
    if (!(S.rest && S.rest.resting)) {
      var from = driveFromMs();
      if (from) {
        var min = (now - from) / 60000;
        if (min >= (cfg.restHardMin || 300)) {
          out.push(['bad', '⛔ ' + hm(min) + ' लगातार ड्राइभ — अब रोकेर आराम गर्नुहोस्। · Stop the bus and rest now.']);
          if (!S.buzzHard) { S.buzzHard = true; buzz(); }
        } else if (min >= (cfg.restSoftMin || 240)) {
          out.push(['warn', '☕ ' + hm(min) + ' ड्राइभ भयो — आराम गर्नुहोस्। · Please take a rest.']);
        } else S.buzzHard = false;
      }
    }
    if (S.speedHits >= 2 && S.fix && S.fix.kmh != null && now - S.fix.t < 60000) {   // a lost GPS leaves no stale "slow down"
      out.push(['bad', '🐢 गति कम गर्नुहोस् · Slow down: ' + Math.round(S.fix.kmh) + ' km/h (सीमा ' + (cfg.speedWarnKmh || 80) + ')']);
    }
    if (S.batt && !S.batt.charging && S.batt.pct < (cfg.batteryLowPct || 20)) {
      out.push(['warn', '🔋 फोन चार्ज गर्नुहोस् · Charge the phone (' + S.batt.pct + '%)']);
    }
    var key = out.map(function (b) { return b[0] + b[1]; }).join('|');
    if (key === lastBan) return;
    lastBan = key;
    var box = $('rBanners'); box.textContent = '';
    out.forEach(function (b) { var d = el('div', b[1]); d.className = 'ban ' + b[0]; box.appendChild(d); });
  }
  function buzz() { try { if (navigator.vibrate) navigator.vibrate([300, 150, 300]); } catch (e) {} }
  function initBattery() {
    if (S.battInit || !navigator.getBattery) return;
    S.battInit = true;
    navigator.getBattery().then(function (b) {
      function upd() { S.batt = { pct: Math.round(b.level * 100), charging: !!b.charging }; }
      upd(); b.addEventListener('levelchange', upd); b.addEventListener('chargingchange', upd);
    }).catch(function () {});
  }

  /* ---------- buttons: every tap is queued, sent with the phone's last position ---------- */
  function evQ() { try { var q = JSON.parse(lsGet(K.evq) || '[]'); return Array.isArray(q) ? q : []; } catch (e) { return []; } }
  function evSave(q) { lsSet(K.evq, q.length ? JSON.stringify(q) : null); }
  function evPending(prefix) { return evQ().some(function (e) { return S.trip && e.tripId === S.trip.tripId && String(e.kind).indexOf(prefix) === 0; }); }
  function report(kind, extra) {
    if (!S.trip) return;
    var ev = { tripId: S.trip.tripId, kind: kind, t: Date.now() };
    if (S.fix && Date.now() - S.fix.t < 300000) { ev.lat = +S.fix.lat.toFixed(7); ev.lng = +S.fix.lng.toFixed(7); }
    Object.keys(extra || {}).forEach(function (k) { ev[k] = extra[k]; });
    var q = evQ(); q.push(ev); evSave(q);
    flushEvents(true);
  }
  function isAlert(e) { return e.kind === 'sos' || e.kind === 'breakdown'; }
  function flushEvents(fromTap) {
    if (S.evSending || !S.token) return;
    var all = evQ(), q = all.filter(function (e) { return S.trip && e.tripId === S.trip.tripId; });
    if (q.length !== all.length) evSave(q);   // a finished trip's leftovers
    if (!q.length) return;
    /* SOS / breakdown first: a tap the server holds back (429) stays at the head of the
       queue, and an emergency must never wait behind it (the server gives alerts their own budget) */
    var ev = q.filter(isAlert)[0] || q[0];
    S.evSending = true;
    var body = { action: 'event' };
    Object.keys(ev).forEach(function (k) { body[k] = ev[k]; });
    api('POST', body).then(function (j) {
      S.evSending = false;
      if (j.status === 401) { signedOut(); return; }
      /* kept for a retry: too many taps (429) or a server hiccup; anything else is final */
      var keep = j.status === 429 || (j.status >= 500 && j.status !== 503);
      if (!keep) evSave(evQ().filter(function (x) { return !(x.t === ev.t && x.kind === ev.kind); }));
      if (j.ok) { onEventDone(ev, j.data || {}); flushEvents(false); return; }
      if (j.status === 409) { tripGone('This trip has ended or moved to another phone. · यात्रा अर्को फोनमा छ।'); return; }
      if (fromTap || !keep) toast(j.error || 'Not sent. · पठाउन सकिएन।', true);
    }).catch(function () {
      S.evSending = false;
      if (!fromTap) return;
      if (isAlert(ev)) sosOffline();
      else toast('📶 नेट आएपछि पठाइन्छ · Saved: it goes when the phone has signal.');
    });
  }
  function onEventDone(ev, d) {
    if (d.nav && S.info) { S.info.nav = d.nav; renderInfo(); }
    if (d.rest) { S.rest = d.rest; renderRestBtn(); }
    S.infoAt = 0;   // fresh stops / passengers on the next tick
    /* a re-sent SOS the server already had: its first answer was lost (the driver saw
       "No signal"), so this is the first word that it reached the office */
    if (isAlert(ev)) { alertSent(ev.kind); return; }
    if (d.repeat) return;
    var msg = { stop_reached: '✓ ' + (ev.stop || '') + ' — पुग्यो · reached',
                delay: '✓ ढिलो ' + ev.minutes + ' मिनेट — अफिसलाई थाहा भयो · Office told',
                rest_start: '☕ आराम सुरु · Rest started', rest_end: '▶ आराम सकियो · Rest over — safe driving' }[ev.kind];
    if (msg) toast(msg);
  }
  function callOffice(box) {
    var c = S.cfg || {};
    if (!c.officeTel) return;
    var a = el('a', '📞 अफिसलाई फोन · Call office ' + (c.officePhone || ''));
    a.className = 'btn call2'; a.href = 'tel:' + c.officeTel;
    box.appendChild(a);
  }
  function alertSent(kind) {
    sheet(kind === 'sos' ? '✓ SOS पठाइयो · SOS sent' : '✓ अफिसलाई खबर गरियो · Office told', function (b) {
      var p = el('p', 'Owner र अफिसलाई बस, ठाउँ र समय सहित खबर गयो। सम्भव भए फोन पनि गर्नुहोस्। · The owner and the office have the bus, the place and the time. Call them too if you can.');
      p.className = 'mut'; b.appendChild(p); callOffice(b);
    }, 'बन्द · Close');
  }
  function sosOffline() {
    sheet('📶 नेट छैन · No signal', function (b) {
      var p = el('p', 'खबर फोनमा सुरक्षित छ — नेट आउनासाथ आफैँ जान्छ। अहिले नै अफिसलाई फोन गर्नुहोस्। · Saved on the phone; it goes by itself when there is signal. Call the office now.');
      p.className = 'mut'; b.appendChild(p); callOffice(b);
    }, 'बन्द · Close');
  }
  function toast(msg, bad) {
    var t = $('toast'); t.textContent = msg; t.className = 'toast' + (bad ? ' bad' : ''); t.hidden = false;
    clearTimeout(S.toastT); S.toastT = setTimeout(function () { t.hidden = true; }, 4500);
  }

  /* one bottom sheet: title + body built by the caller */
  function sheet(title, build, closeText) {
    clearTimeout(S.sosT);
    S.sheetAt = Date.now();
    $('shT').textContent = title; $('shErr').textContent = '';
    $('shClose').textContent = closeText || 'रद्द · Cancel';
    $('toast').hidden = true;   // never over the sheet's buttons
    var b = $('shBody'); b.textContent = '';
    build(b);
    $('sheet').hidden = false;
    var f = b.querySelector('button, a') || $('shClose');
    try { f.focus(); } catch (e) {}
  }
  function hideSheet() { clearTimeout(S.sosT); $('sheet').hidden = true; }
  /* the confirm buttons wake up 0.7 s after their sheet opens: the second tap of a double tap
     (or a pocket press) on SOS / Bus bigriyo lands on the new sheet and must not confirm it */
  function armed() { return Date.now() - (S.sheetAt || 0) >= 700; }
  $('shClose').addEventListener('click', hideSheet);
  $('sheet').addEventListener('click', function (e) { if (e.target === this) hideSheet(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('sheet').hidden) hideSheet(); });
  function chip(text, onPick) {
    var c = el('button', text); c.type = 'button'; c.className = 'chip';
    c.addEventListener('click', function () { onPick(c); });
    return c;
  }
  function only(box, on) { box.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === on); }); }
  function textBox(ph) { var i = document.createElement('input'); i.type = 'text'; i.maxLength = 120; i.placeholder = ph; i.setAttribute('aria-label', ph); return i; }

  /* Stop pugyo: the route's stops, the next one marked */
  $('aStop').addEventListener('click', function () {
    var nav = S.info && S.info.nav;
    sheet('📍 कुन स्टप पुग्नुभयो? · Which stop?', function (b) {
      if (!nav || !nav.stops || !nav.stops.length) {
        var p = el('p', 'The stop list needs the internet once. · स्टप सूची आउन नेट चाहिन्छ।'); p.className = 'mut'; b.appendChild(p); return;
      }
      var nextIdx = nav.passedIdx + 1;
      nav.stops.forEach(function (s, i) {
        var x = el('button'); x.type = 'button';
        x.className = 'stopb' + (i <= nav.passedIdx ? ' past' : '') + (i === nextIdx ? ' next' : '');
        x.appendChild(el('b', s.name));
        x.appendChild(el('small', (s.type === 'drop' ? 'Drop · झर्ने' : 'Pickup · चढ्ने') + (s.time ? ' · ' + s.time : '') + (i === nextIdx ? ' · अर्को · next' : '')));
        x.addEventListener('click', function () {
          hideSheet();
          nav.passedIdx = i; nav.next = nav.stops[i + 1] ? nav.stops[i + 1].name : null;
          nav.stops.forEach(function (st, k) { st.passed = k <= i; });
          renderInfo();
          report('stop_reached', { stop: s.name });
        });
        b.appendChild(x);
      });
      var nx = b.querySelector('.next');
      if (nx) setTimeout(function () { try { nx.scrollIntoView({ block: 'center' }); nx.focus(); } catch (e) {} }, 30);
    });
  });

  /* Dhilo: minutes + why */
  $('aDelay').addEventListener('click', function () {
    var pick = { m: null, why: '' };
    sheet('⏱ कति ढिलो? · How late?', function (b) {
      b.appendChild(el('label', 'Minutes late · मिनेट'));
      var mins = el('div'); mins.className = 'chips';
      var inp = document.createElement('input'); inp.type = 'number'; inp.inputMode = 'numeric'; inp.min = '5'; inp.max = '600';
      inp.placeholder = 'अरू · other (5-600)'; inp.setAttribute('aria-label', 'Minutes late');
      [15, 30, 45, 60, 90, 120].forEach(function (m) { mins.appendChild(chip(m + ' मि', function (c) { pick.m = m; inp.value = ''; only(mins, c); })); });
      inp.addEventListener('input', function () { pick.m = parseInt(inp.value, 10) || null; only(mins, null); });
      b.appendChild(mins); b.appendChild(inp);
      b.appendChild(el('label', 'Why · किन'));
      var why = el('div'); why.className = 'chips';
      ['जाम · Traffic', 'बोर्डर · Border', 'यात्रु पर्खाइ · Waiting for passengers', 'बाटो खराब · Bad road', 'मौसम · Weather', 'खाना · Meal halt']
        .forEach(function (r) { why.appendChild(chip(r, function (c) { pick.why = pick.why === r ? '' : r; only(why, pick.why ? c : null); })); });
      b.appendChild(why);
      var note = textBox('छोटो कुरा · short note (optional)'); b.appendChild(note);
      var go = el('button', 'अफिसलाई पठाउनुहोस् · Send'); go.type = 'button'; go.className = 'btn';
      go.addEventListener('click', function () {
        var m = pick.m;
        if (!m || m < 5 || m > 600) { $('shErr').textContent = '5 देखि 600 मिनेट छान्नुहोस् · Pick 5 to 600 minutes.'; return; }
        hideSheet();
        report('delay', { minutes: m, note: [pick.why, note.value.trim()].filter(Boolean).join(' — ').slice(0, 200) });
      });
      b.appendChild(go);
    });
  });

  /* Bus bigriyo: what broke, then the office is told at once */
  $('aBreak').addEventListener('click', function () {
    var pick = '';
    sheet('🛠 बस बिग्रियो · Bus breakdown', function (b) {
      var p = el('p', 'Owner र अफिसलाई तुरुन्त खबर जान्छ, ठाउँ (GPS) सहित। · The owner and the office are told at once, with the place.'); p.className = 'mut'; b.appendChild(p);
      var what = el('div'); what.className = 'chips';
      ['टायर पन्चर · Puncture', 'इन्जिन · Engine', 'ब्रेक · Brakes', 'AC', 'दुर्घटना · Accident', 'अरू · Other']
        .forEach(function (r) { what.appendChild(chip(r, function (c) { pick = pick === r ? '' : r; only(what, pick ? c : null); })); });
      b.appendChild(what);
      var note = textBox('के भयो? · what happened (optional)'); b.appendChild(note);
      var go = el('button', 'खबर पठाउनुहोस् · Tell the office'); go.type = 'button'; go.className = 'btn warnb';
      go.addEventListener('click', function () {
        if (!armed()) return;
        hideSheet();
        report('breakdown', { note: [pick, note.value.trim()].filter(Boolean).join(' — ').slice(0, 200) });
      });
      b.appendChild(go);
      callOffice(b);
    });
  });

  /* SOS: a second, deliberate tap - a pocket or a bump on the dashboard never sends it */
  $('aSos').addEventListener('click', function () {
    sheet('🆘 SOS पठाउने? · Send SOS?', function (b) {
      var p = el('p', 'Owner र अफिसलाई तुरुन्त खबर जान्छ — बस, ठाउँ र समय सहित। गल्तीले थिच्नुभयो भने रद्द थिच्नुहोस्। · The owner and the office are alerted at once with the bus, the place and the time. Tapped by mistake? Press Cancel.');
      p.className = 'mut'; b.appendChild(p);
      var go = el('button', 'हो, SOS पठाउनुहोस् · YES, SEND SOS'); go.type = 'button'; go.className = 'btn sos';
      go.style.fontSize = '20px';
      go.addEventListener('click', function () { if (!armed()) return; hideSheet(); report('sos'); });
      b.appendChild(go);
      callOffice(b);
    });
    $('shClose').focus();   // Cancel first: Enter on a stray keyboard cancels, never sends
    S.sosT = setTimeout(hideSheet, 15000);
  });

  /* Aaram suru / Aaram sakiyo */
  function renderRestBtn() {
    var r = !!(S.rest && S.rest.resting), b = $('aRest');
    b.textContent = ''; b.appendChild(document.createTextNode(r ? '▶ आराम सकियो' : '☕ आराम सुरु')); b.appendChild(el('small', r ? 'End rest' : 'Start rest'));
    b.classList.toggle('on', r);
    b.setAttribute('aria-pressed', r ? 'true' : 'false');
  }
  $('aRest').addEventListener('click', function () {
    var resting = !!(S.rest && S.rest.resting), nowS = Math.round((Date.now() - S.skew) / 1000);
    S.rest = resting ? { resting: false, sinceTs: null, lastEndTs: nowS } : { resting: true, sinceTs: nowS, lastEndTs: S.rest ? S.rest.lastEndTs : null };
    renderRestBtn(); renderDrive(Date.now()); lastBan = null; banners(Date.now());
    report(resting ? 'rest_end' : 'rest_start');
  });

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
      /* the fixes AND the button taps still on the phone go first (10 s at most): after END TRIP
         the taps are wiped, so an SOS saved in a dead zone must not be lost behind it */
      flush(); flushEvents(false);
      if ((S.sending || S.evSending || evPending('')) && tries++ < 20) { setTimeout(sendRest, 500); return; }
      api('POST', { action: 'end', tripId: trip.tripId }).then(function (j) {
        S.ending = false;
        if (j.ok || j.status === 409) { stopGps(); S.trip = null; lsSet(K.trip, null); qDrop(-1, 0); clearTripData(); $('eRun').textContent = ''; loadMe(); }
        else $('eRun').textContent = j.error || 'Could not end the trip.';
      }).catch(function () { S.ending = false; $('eRun').textContent = 'No internet. The trip ends when you have signal; tracking stays on. · इंटरनेट आने पर दोबारा दबाएं।'; });
    })();
  }

  /* ---------- boot ---------- */
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/driver/sw.php', { scope: '/driver/' }).catch(function () {});
  }
  /* Opened from the member portal (/me/ → Start bus location) when this browser refused to
     store the sign-in: the token comes once in the #fragment (never sent to the server, never
     in a Referer) and is wiped from the address bar at once. */
  var hm0 = /^#t=([a-f0-9]{48})$/.exec(location.hash || '');
  if (hm0) {
    lsSet(K.token, hm0[1]); S.token = hm0[1];
    try { history.replaceState(null, '', location.pathname); } catch (e) {}
  }
  S.token = S.token || lsGet(K.token);
  try { S.cfg = JSON.parse(lsGet(K.cfg) || '{}') || {}; } catch (e) { S.cfg = {}; }
  roleText();
  try { S.trip = JSON.parse(lsGet(K.trip) || 'null'); } catch (e) { S.trip = null; }
  if (!S.token) { show('vLogin'); return; }
  if (S.trip) run();       // resume at once, even with no network
  loadMe();
})();
</script>
</body>
</html>
