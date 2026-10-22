/* =====================================================================
   SHARI NAV · assets/nav/nav-live.js  (22 Oct 2026, Phase 2 "live")

   Add-on for navigate.html, through window.SHGNAV_X (the page's bridge).
     A · Live trip share  - "share trip progress": a 128-bit follow link,
                            the phone posts its position every 3 s while
                            navigating; ?follow=<token> = the live view
     B · S Hari coaches   - every coach on the road now (the real coach
                            photo), next stop + minutes, Book / Track
     C · Road reports     - accident, police, jam, landslide, border queue
                            ...: one tap or by voice, 👍 / 👎, shown within
                            30 km, spoken 1 km ahead on the route
   Push: wss://<host>/live/ (live/shg-live.php); when a network blocks
   WebSockets, /api/nav-live.php long-poll. Without the page's hook()
   (older pages) A and the route alerts are off, B and C still show.
===================================================================== */
(function () {
'use strict';

var X = null;
var QS = new URLSearchParams(location.search);

/* ---------------------------------------------------------------------
   TEXT (follows the page language: ne | hi | en)
--------------------------------------------------------------------- */
var TX = {
  liveH: { ne: 'लाइभ', hi: 'लाइव', en: 'Live' },
  shareTrip: { ne: 'लाइभ यात्रा सेयर गर्नुस्', hi: 'लाइव यात्रा शेयर करें', en: 'Share live trip' },
  sharing: { ne: 'यात्रा लाइभ सेयर हुँदैछ', hi: 'यात्रा लाइव शेयर हो रही है', en: 'Sharing your trip live' },
  shareOn: { ne: 'यो link भएका जो कोहीले तपाईं कहाँ पुग्नुभयो र कति बजे पुग्नुहुन्छ देख्न सक्छन्। पुगेपछि वा ४ घण्टामा आफैँ बन्द हुन्छ।',
             hi: 'इस link वाला कोई भी देख सकता है कि आप कहाँ पहुँचे और कब पहुँचेंगे। पहुँचने पर या 4 घंटे में अपने-आप बंद।',
             en: 'Anyone with this link sees where you are and when you arrive. It stops by itself on arrival or after 4 hours.' },
  shareMsg: { ne: 'मेरो यात्रा लाइभ हेर्नुहोस् → {d} · पुग्ने समय {e}', hi: 'मेरी यात्रा लाइव देखें → {d} · पहुँचने का समय {e}', en: 'Follow my trip live → {d} · arriving {e}' },
  wa: { ne: 'WhatsApp', hi: 'WhatsApp', en: 'WhatsApp' },
  more: { ne: 'अरू एप', hi: 'अन्य ऐप', en: 'More apps' },
  copy: { ne: 'Copy', hi: 'Copy', en: 'Copy' },
  copied: { ne: 'Link copy भयो', hi: 'Link copy हो गया', en: 'Link copied' },
  stop: { ne: 'सेयर बन्द', hi: 'शेयर बंद करें', en: 'Stop sharing' },
  stopped: { ne: 'लाइभ सेयर बन्द भयो', hi: 'लाइव शेयर बंद हुआ', en: 'Live sharing stopped' },
  needNav: { ne: 'पहिले नेभिगेसन सुरु गर्नुस्', hi: 'पहले नेविगेशन शुरू करें', en: 'Start navigation first' },
  failed: { ne: 'अहिले सकिएन — फेरि प्रयास गर्नुस्', hi: 'अभी नहीं हो पाया — फिर कोशिश करें', en: 'Could not do that now — try again' },
  fLive: { ne: 'लाइभ यात्रा', hi: 'लाइव यात्रा', en: 'Live trip' },
  fArr: { ne: 'पुग्ने', hi: 'पहुँचेंगे', en: 'arrives' },
  fLeft: { ne: 'बाँकी', hi: 'बाकी', en: 'left' },
  fAgo: { ne: '{n} अघि अपडेट', hi: '{n} पहले अपडेट', en: 'updated {n} ago' },
  fWeak: { ne: 'सिग्नल कमजोर — अन्तिम थाहा भएको ठाउँ', hi: 'सिग्नल कमज़ोर — आख़िरी पता जगह', en: 'Weak signal — last known place' },
  fDone: { ne: '{d} मा पुग्नुभयो ✓', hi: '{d} पहुँच गए ✓', en: 'Arrived at {d} ✓' },
  fEnd: { ne: 'यो यात्रा अब सेयर भइरहेको छैन', hi: 'यह यात्रा अब शेयर नहीं हो रही', en: 'This trip is no longer shared' },
  fWait: { ne: 'यात्रा खोज्दैछु…', hi: 'यात्रा खोज रहा हूँ…', en: 'Finding the trip…' },
  fCenter: { ne: 'केन्द्रमा', hi: 'बीच में', en: 'Re-center' },
  fOpen: { ne: 'SHARI NAV खोल्नुस्', hi: 'SHARI NAV खोलें', en: 'Open SHARI NAV' },
  sec: { ne: 'सेकेन्ड', hi: 'सेकंड', en: 's' }, min: { ne: 'मिनेट', hi: 'मिनट', en: 'min' },
  busL: { ne: 'S Hari बस (लाइभ)', hi: 'S Hari बस (लाइव)', en: 'S Hari coaches (live)' },
  repL: { ne: 'बाटोका रिपोर्ट', hi: 'रास्ते की रिपोर्ट', en: 'Road reports' },
  busNext: { ne: 'अर्को स्टप', hi: 'अगला स्टॉप', en: 'Next stop' },
  busBook: { ne: 'सिट बुक गर्नुस्', hi: 'सीट बुक करें', en: 'Book a seat' },
  busTrack: { ne: 'मेरो बस ट्र्याक', hi: 'मेरी बस ट्रैक', en: 'Track my bus' },
  busAge: { ne: '{n} अघिको स्थान', hi: '{n} पहले की जगह', en: 'position {n} old' },
  report: { ne: 'बाटोको रिपोर्ट', hi: 'रास्ते की रिपोर्ट', en: 'Report on the road' },
  repQ: { ne: 'बाटोमा के छ?', hi: 'रास्ते में क्या है?', en: 'What is on the road?' },
  repVoice: { ne: 'बोलेर भन्नुस्', hi: 'बोलकर बताएं', en: 'Say it' },
  repThanks: { ne: 'धन्यवाद! अरू यात्रुले पनि देख्नेछन्', hi: 'धन्यवाद! दूसरे यात्री भी देखेंगे', en: 'Thanks! Other riders will see it' },
  repLoc: { ne: 'रिपोर्ट गर्न तपाईंको लोकेसन चाहिन्छ', hi: 'रिपोर्ट के लिए आपकी लोकेशन चाहिए', en: 'Your location is needed to report' },
  repMany: { ne: 'एक घण्टामा १० रिपोर्ट मात्र', hi: 'एक घंटे में केवल 10 रिपोर्ट', en: 'Only 10 reports an hour' },
  repStill: { ne: 'अझै छ', hi: 'अभी भी है', en: 'Still there' },
  repGone: { ne: 'छैन', hi: 'नहीं है', en: 'Not there' },
  repVoted: { ne: 'जवाफको लागि धन्यवाद', hi: 'जवाब के लिए धन्यवाद', en: 'Thanks for answering' },
  repAgo: { ne: '{n} अघि रिपोर्ट', hi: '{n} पहले रिपोर्ट', en: 'reported {n} ago' },
  repSure: { ne: '{k} रिपोर्ट गर्ने?', hi: '{k} रिपोर्ट करें?', en: 'Report {k}?' },
  yes: { ne: 'हो, पठाउनुस्', hi: 'हाँ, भेजें', en: 'Yes, send' },
  noHeard: { ne: 'बुझिएन — तल छान्नुस्', hi: 'समझ नहीं आया — नीचे चुनें', en: 'Did not catch that — pick below' },
  listen: { ne: 'सुन्दैछु…', hi: 'सुन रहा हूँ…', en: 'Listening…' },
  ahead: { ne: 'सावधान! करिब {d} अगाडि {k} रिपोर्ट गरिएको छ', hi: 'सावधान! लगभग {d} आगे {k} की रिपोर्ट है', en: 'Caution: {k} reported about {d} ahead' },
  km1: { ne: 'एक किलोमिटर', hi: 'एक किलोमीटर', en: '1 km' }, mtr: { ne: 'मिटर', hi: 'मीटर', en: 'm' },
  close: { ne: 'बन्द', hi: 'बंद', en: 'Close' }
};
/* the owner's list; minutes each lives = NavLive::KINDS on the server */
var KINDS = [
  ['accident', '💥', { ne: 'दुर्घटना', hi: 'दुर्घटना', en: 'Accident' }, /दुर्घटना|एक्सिडेन्ट|एक्सीडेंट|accident|ठोकिय|टक्कर/i],
  ['police', '👮', { ne: 'प्रहरी जाँच', hi: 'पुलिस चेकिंग', en: 'Police' }, /प्रहरी|पुलिस|police|चेकिङ|चेकिंग|checking/i],
  ['jam', '🚗', { ne: 'जाम', hi: 'जाम', en: 'Traffic jam' }, /जाम|ट्राफिक|ट्रैफिक|jam|traffic/i],
  ['roadwork', '🚧', { ne: 'सडक निर्माण', hi: 'सड़क निर्माण', en: 'Road work' }, /निर्माण|मर्मत|काम भइरहेको|road ?work|construction/i],
  ['closed', '⛔', { ne: 'बाटो बन्द', hi: 'रास्ता बंद', en: 'Road closed' }, /बाटो बन्द|सडक बन्द|रास्ता बंद|सड़क बंद|closed|बन्द छ|बंद है/i],
  ['flood', '🌊', { ne: 'बाढी', hi: 'बाढ़', en: 'Flood' }, /बाढी|बाढ़|पानी भरि|flood/i],
  ['landslide', '⛰️', { ne: 'पहिरो', hi: 'भूस्खलन', en: 'Landslide' }, /पहिरो|भूस्खलन|landslide|पहाड़ गिर/i],
  ['pothole', '🕳️', { ne: 'खाल्डो', hi: 'गड्ढा', en: 'Pothole' }, /खाल्डो|खाडल|गड्ढा|pothole/i],
  ['animal', '🐄', { ne: 'बाटोमा जनावर', hi: 'सड़क पर जानवर', en: 'Animal on road' }, /जनावर|गाई|गोरु|भैंसी|जानवर|गाय|animal|cow/i],
  ['fog', '🌫️', { ne: 'हुस्सु', hi: 'कोहरा', en: 'Fog' }, /हुस्सु|कुहिरो|कोहरा|धुंध|fog/i],
  ['border', '🛂', { ne: 'नाकामा लाइन', hi: 'बॉर्डर पर लाइन', en: 'Border queue' }, /नाका|बोर्डर|बॉर्डर|border|भन्सार/i]
];
var KIND = {}; KINDS.forEach(function (k) { KIND[k[0]] = k; });

/* ---------------------------------------------------------------------
   HELPERS
--------------------------------------------------------------------- */
function $(s, r) { return (r || document).querySelector(s); }
function lang() { var l = X && X.S.lang; return l === 'hi' || l === 'en' ? l : 'ne'; }
function L(o) { return o ? (o[lang()] || o.en || '') : ''; }
function t(k, v) {
  var s = L(TX[k]) || k;
  if (v) Object.keys(v).forEach(function (n) { s = s.split('{' + n + '}').join(v[n]); });
  return s;
}
function esc(s) { return X.esc(s); }
var LSg = {
  get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set: function (k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
};
function here() {
  var S = X.S;
  if (S.me) return [S.me.lng, S.me.lat];
  if (S.map) { var c = S.map.getCenter(); return [c.lng, c.lat]; }
  return null;
}
function rand32() {
  var a = new Uint8Array(16), s = '';
  try { crypto.getRandomValues(a); } catch (e) { for (var j = 0; j < 16; j++) a[j] = Math.floor(Math.random() * 256); }
  for (var i = 0; i < 16; i++) s += ('0' + a[i].toString(16)).slice(-2);
  return s;
}
function dev() {
  var d = LSg.get('shg:nl:dev', '');
  if (!/^[a-f0-9]{32}$/.test(d)) { d = rand32(); LSg.set('shg:nl:dev', d); }
  return d;
}
function api(url, body) {
  var o = body ? { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-SHG-Dev': dev() }, body: JSON.stringify(body) }
               : { credentials: 'same-origin', cache: 'no-store', headers: { 'X-SHG-Dev': dev() } };
  return X.fetchT(url, o, 12000).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!j || !j.ok) { var e = new Error((j && (j.message || j.error)) || 'http ' + r.status); e.status = r.status; throw e; }
      return j.data;
    });
  });
}
function ago(ms) {
  var s = Math.max(0, Math.round((Date.now() - ms) / 1000));
  if (s < 60) return s + ' ' + t('sec');
  if (s < 3600) return Math.round(s / 60) + ' ' + t('min');
  return Math.round(s / 360) / 10 + ' h';
}
function clock(ms) { var d = new Date(ms); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function slow() { return !!(X.S.saver || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)); }
/* Google encoded polyline, precision 5 - at most ~900 points */
function enc(c) {
  var step = Math.max(1, Math.ceil(c.length / 900)), out = '', pl = 0, pg = 0;
  function num(v) { v = v < 0 ? ~(v << 1) : v << 1; var s = ''; while (v >= 0x20) { s += String.fromCharCode((0x20 | (v & 0x1f)) + 63); v >>= 5; } return s + String.fromCharCode(v + 63); }
  function add(p) { var la = Math.round(p[1] * 1e5), lg = Math.round(p[0] * 1e5); out += num(la - pl) + num(lg - pg); pl = la; pg = lg; }
  for (var i = 0; i < c.length; i += step) add(c[i]);
  if ((c.length - 1) % step) add(c[c.length - 1]);
  return out;
}
function dec(s) {
  var i = 0, la = 0, lg = 0, out = [];
  function rd() { var b, sh = 0, r = 0; do { b = s.charCodeAt(i++) - 63; r |= (b & 0x1f) << sh; sh += 5; } while (b >= 0x20 && i <= s.length); return (r & 1) ? ~(r >> 1) : (r >> 1); }
  while (i < s.length) { la += rd(); lg += rd(); out.push([lg / 1e5, la / 1e5]); }
  return out;
}
/* metres from p to segment a-b (flat earth: fine at road scale) */
function segDist(p, a, b) {
  var k = Math.cos(p[1] * Math.PI / 180) * 111320, ky = 110540;
  var ax = (a[0] - p[0]) * k, ay = (a[1] - p[1]) * ky, bx = (b[0] - p[0]) * k, by = (b[1] - p[1]) * ky;
  var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy, u = l2 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / l2)) : 0;
  var x = ax + u * dx, y = ay + u * dy;
  return { d: Math.sqrt(x * x + y * y), u: u };
}
function wrapM(html, cls) { var w = document.createElement('div'), i = document.createElement('div'); i.className = cls; i.innerHTML = html; w.appendChild(i); return w; }

/* ---------------------------------------------------------------------
   LIVE CONNECTION  (WebSocket, long-poll when the network blocks it)
--------------------------------------------------------------------- */
var LC = { ws: null, h: {}, tries: 0, poll: false, since: 0 };
function chans() { return Object.keys(LC.h); }
function on(ch, fn) { LC.h[ch] = fn; resub(); }
function off(ch) { delete LC.h[ch]; resub(); }
function resub() {
  if (LC.ws && LC.ws.readyState === 1) { try { LC.ws.send(JSON.stringify({ sub: chans() })); } catch (e) {} }
  else if (!LC.ws && !LC.poll && chans().length) connect();
}
function deliver(m) { var f = m && LC.h[m.ch]; if (f) { try { f(m.d, m.t); } catch (e) { if (QS.has('debug')) console.warn('nav-live', e); } } }
function connect() {
  if (!chans().length) return;
  if (!('WebSocket' in window) || LC.tries >= 3) { startPoll(); return; }
  var ws, opened = false;
  try { ws = new WebSocket((location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host + '/live/?ch=' + encodeURIComponent(chans().join(','))); }
  catch (e) { LC.tries++; startPoll(); return; }
  LC.ws = ws;
  ws.onopen = function () { opened = true; LC.tries = 0; resub(); };
  ws.onmessage = function (e) { try { deliver(JSON.parse(e.data)); } catch (x) {} };
  ws.onerror = function () {};
  ws.onclose = function () {
    if (LC.ws === ws) LC.ws = null;
    if (!opened) LC.tries++;
    if (chans().length) setTimeout(connect, opened ? 2000 : 3000 * LC.tries);
  };
}
function startPoll() { if (LC.poll) return; LC.poll = true; pollOnce(); }
function pollOnce() {
  if (!chans().length) { LC.poll = false; return; }
  var h = here(), u = '/api/nav-live.php?ch=' + encodeURIComponent(chans().join(',')) + '&since=' + LC.since +
    (h ? '&lat=' + h[1].toFixed(3) + '&lng=' + h[0].toFixed(3) : '');
  api(u).then(function (d) { LC.since = d.now; (d.ev || []).forEach(deliver); })
    .catch(function () {})
    .then(function () { setTimeout(pollOnce, document.hidden ? 15000 : 1200); });
}

/* ---------------------------------------------------------------------
   SHEET (one small bottom sheet; Android back closes it first)
--------------------------------------------------------------------- */
var SHT = { open: false, hist: false, backing: false };
function css() {
  var st = document.createElement('style');
  st.id = 'nlCss';
  st.textContent = [
    '.nl-wrap{position:fixed;inset:0;z-index:62;pointer-events:none}',
    '.nl-scrim{position:absolute;inset:0;background:rgba(3,7,16,.4);pointer-events:auto}',
    '.nl-sheet{position:absolute;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px);max-width:520px;margin:0 auto;max-height:80vh;overflow-y:auto;overscroll-behavior:contain;',
    '  border-radius:22px;pointer-events:auto;padding:14px 14px 12px;background:rgba(14,24,44,.95);-webkit-backdrop-filter:blur(20px) saturate(1.4);backdrop-filter:blur(20px) saturate(1.4);',
    '  border:1px solid rgba(255,255,255,.09);box-shadow:0 18px 60px rgba(0,0,0,.55);color:var(--text,#EEF2FA);font:14px/1.5 Inter,system-ui,sans-serif}',
    '.nl-sheet h3{margin:0 0 6px;font:600 16px Poppins,Inter,system-ui,sans-serif;display:flex;align-items:center;gap:8px}',
    '.nl-sheet p{margin:4px 0 10px;color:var(--text2,#A8B4CC);font-size:13.5px}',
    '.nl-x{position:absolute;top:8px;right:8px;width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.06);color:var(--text2,#A8B4CC);font-size:20px;cursor:pointer}',
    '.nl-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}',
    '.nl-b{min-height:44px;padding:0 16px;border-radius:22px;border:1px solid rgba(255,255,255,.14);background:transparent;color:var(--text,#EEF2FA);font:500 14px Inter,system-ui,sans-serif;display:inline-flex;align-items:center;gap:8px;cursor:pointer;text-decoration:none}',
    '.nl-b.cta{background:var(--cta,#E8652E);border-color:var(--cta,#E8652E);color:#fff}.nl-b.wa{background:#1FAF54;border-color:#1FAF54;color:#fff}.nl-b.red{color:#FF7A70;border-color:rgba(255,122,112,.4)}',
    '.nl-url{width:100%;height:44px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);padding:0 12px;font:13px Inter,system-ui,sans-serif}',
    '.nl-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}',
    '.nl-k{min-height:76px;border-radius:16px;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.04);color:var(--text,#EEF2FA);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font:500 12.5px Inter,system-ui,sans-serif;cursor:pointer;text-align:center;padding:6px}',
    '.nl-k span{font-size:26px;line-height:1}',
    '.nl-fab{position:relative}.nl-fab svg{width:22px;height:22px}',
    '.nl-livepill{background:#E5352B!important;color:#fff!important;border:none!important;font:700 11px Inter,system-ui,sans-serif!important;letter-spacing:.06em}',
    '.nl-bus{cursor:pointer}.nl-bi{display:flex;align-items:center;gap:6px;padding:3px 9px 3px 3px;border-radius:22px;background:rgba(14,24,44,.92);border:2px solid #D4A843;box-shadow:0 4px 12px rgba(0,0,0,.45);color:#EEF2FA;font:600 11.5px Inter,system-ui,sans-serif;white-space:nowrap}',
    '.nl-bi img{width:34px;height:34px;border-radius:50%;object-fit:cover;background:#fff}',
    '.nl-rep{cursor:pointer}.nl-ri{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-size:19px;background:#fff;border:2px solid #E8652E;box-shadow:0 3px 10px rgba(0,0,0,.4)}',
    '.nl-puck{width:26px;height:26px;border-radius:50%;background:#2F80ED;border:3px solid #fff;box-shadow:0 0 0 8px rgba(47,128,237,.25),0 3px 10px rgba(0,0,0,.4);position:relative}',
    '.nl-puck i{position:absolute;left:50%;top:-11px;margin-left:-6px;border:6px solid transparent;border-bottom:9px solid #2F80ED;transform-origin:6px 24px}',
    '.nl-dest{width:18px;height:18px;border-radius:50%;background:#E8652E;border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,.4)}',
    '.nl-fcard{position:fixed;z-index:41;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px);max-width:520px;margin:0 auto;border-radius:22px;padding:14px 16px;',
    '  background:rgba(14,24,44,.95);-webkit-backdrop-filter:blur(20px);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.09);box-shadow:0 14px 40px rgba(0,0,0,.5);color:var(--text,#EEF2FA);font:14px/1.45 Inter,system-ui,sans-serif}',
    '.nl-fh{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2,#A8B4CC)}.nl-fh b{color:#FF5A4F;letter-spacing:.06em}',
    '.nl-dot{width:9px;height:9px;border-radius:50%;background:#FF5A4F;animation:nlp 1.4s infinite}.nl-fcard.done .nl-dot{background:#3DDC84;animation:none}.nl-fcard.done .nl-fh b{color:#3DDC84}',
    '@keyframes nlp{50%{opacity:.25}}',
    '.nl-fbig{font:600 24px Poppins,Inter,system-ui,sans-serif;margin:4px 0 0}.nl-fsub{color:var(--text2,#A8B4CC);font-size:13.5px}',
    'body.saver .nl-dot{animation:none}',
    '@media (prefers-reduced-motion:reduce){.nl-dot{animation:none}}'
  ].join('\n');
  document.head.appendChild(st);
}
function sheet(html) {
  var w = $('#nlWrap');
  if (!w) {
    w = document.createElement('div'); w.className = 'nl-wrap'; w.id = 'nlWrap'; w.hidden = true;
    w.innerHTML = '<div class="nl-scrim" id="nlScrim"></div><section class="nl-sheet" role="dialog" aria-modal="true" id="nlSheet"></section>';
    document.body.appendChild(w);
    $('#nlScrim').onclick = function () { sheetClose(); };
    addEventListener('popstate', function () { SHT.backing = false; if (SHT.open && SHT.hist) { SHT.hist = false; sheetClose(true); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && SHT.open) { e.stopImmediatePropagation(); sheetClose(); } }, true);
  }
  $('#nlSheet').innerHTML = '<button class="nl-x" type="button" data-nlx aria-label="' + esc(t('close')) + '">×</button>' + html;
  $('#nlSheet [data-nlx]').onclick = function () { sheetClose(); };
  if (!SHT.open) {
    SHT.open = true; w.hidden = false;
    try { history.pushState({ lvl: X.S.level, d: X.S.depth, nl: 1 }, ''); SHT.hist = true; } catch (e) { SHT.hist = false; }
  }
  return $('#nlSheet');
}
function sheetClose(fromPop) {
  if (!SHT.open) return;
  SHT.open = false; $('#nlWrap').hidden = true;
  if (!fromPop && SHT.hist) { SHT.hist = false; SHT.backing = true; try { history.back(); } catch (e) { SHT.backing = false; } }
}

/* ---------------------------------------------------------------------
   A · LIVE TRIP SHARE
--------------------------------------------------------------------- */
var SH = LSg.get('shg:nl:share', null);       /* {token, secret, url, exp, to:{lat,lng}, dest} */
if (SH && !(SH.exp > Date.now())) SH = null;
var SHx = { N: null, route: null, last: 0, busy: false };
function leftM(N) { return N && N.route && N.route.L ? Math.max(0, N.route.L.len - (N.along || 0)) : null; }
function etaMs(N) {
  var r = N && N.route, left = leftM(N);
  if (!r || left == null || !r.dist) return null;
  return Date.now() + r.dur * (left / r.dist) * 1000;
}
function shareSave() { LSg.set('shg:nl:share', SH); pill(); }
function shareStart() {
  var S = X.S, N = S.nav;
  if (!N || N.preview || !S.to || (N.sim && !QS.has('debug'))) { X.toast(t('needNav')); return; }
  if (SH) { shareSheet(); return; }
  api('/api/nav-share.php', {
    action: 'start', dest: { name: S.to.name || '', lat: S.to.lat, lng: S.to.lng }, mode: S.mode,
    route: enc(N.route.coords), left: leftM(N), eta: etaMs(N)
  }).then(function (d) {
    /* the follow link opens the page that shared it (the beta shares beta links) */
    var url = /^\/navigate(-beta)?\.html$/.test(location.pathname) ? location.origin + location.pathname + '?follow=' + d.token : d.url;
    SH = { token: d.token, secret: d.secret, url: url, exp: d.expires, to: { lat: S.to.lat, lng: S.to.lng }, dest: S.to.name || '' };
    SHx.N = N; SHx.route = N.route; SHx.last = 0;
    shareSave(); X.vib(20);
    sharePos(N, S.me ? { lat: S.me.lat, lng: S.me.lng, speed: S.me.speed, heading: S.me.heading } : null, false);
    shareSheet();
  }).catch(function () { X.toast(t('failed')); });
}
function shareText() { var N = X.S.nav, e = etaMs(N); return t('shareMsg', { d: SH.dest || '', e: e ? clock(e) : '—' }); }
function shareSheet() {
  if (!SH) return;
  var el = sheet('<h3><span class="nl-dot"></span>' + esc(t('sharing')) + '</h3><p>' + esc(t('shareOn')) + '</p>' +
    '<input class="nl-url" readonly value="' + esc(SH.url) + '" aria-label="link">' +
    '<div class="nl-acts"><a class="nl-b wa" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(shareText() + '\n' + SH.url) + '">' + esc(t('wa')) + '</a>' +
    (navigator.share ? '<button class="nl-b" type="button" data-s="more">' + esc(t('more')) + '</button>' : '') +
    '<button class="nl-b" type="button" data-s="copy">' + esc(t('copy')) + '</button>' +
    '<button class="nl-b red" type="button" data-s="stop">' + esc(t('stop')) + '</button></div>');
  el.querySelectorAll('[data-s]').forEach(function (b) {
    b.onclick = function () {
      var k = b.dataset.s;
      if (k === 'more') navigator.share({ title: 'SHARI NAV', text: shareText(), url: SH.url }).catch(function () {});
      else if (k === 'copy') { try { navigator.clipboard.writeText(SH.url).then(function () { X.toast(t('copied')); }); } catch (e) { el.querySelector('.nl-url').select(); } }
      else if (k === 'stop') { shareEnd('stop'); sheetClose(); }
    };
  });
}
function sharePos(N, f, arrived) {
  if (!SH || (SHx.busy && !arrived)) return;          /* the arrival is never skipped */
  var now = Date.now();
  if (!arrived && now - SHx.last < 3000) return;
  if (!f) return;
  SHx.last = now; SHx.busy = true;
  var body = { action: 'pos', token: SH.token, secret: SH.secret, lat: f.lat, lng: f.lng,
               kmh: f.speed > 0 ? f.speed * 3.6 : 0, hd: f.heading != null && !isNaN(f.heading) ? f.heading : null,
               left: arrived ? 0 : leftM(N), eta: arrived ? now : etaMs(N), arrived: !!arrived };
  if (N && N.route && N.route !== SHx.route) { body.route = enc(N.route.coords); SHx.route = N.route; }   /* rerouted */
  api('/api/nav-share.php', body).then(function (d) {
    if (d && d.status !== 'live') { SH = null; shareSave(); }
  }).catch(function (e) { if (e.status === 404) { SH = null; shareSave(); } })
    .then(function () { SHx.busy = false; });
}
function shareEnd(kind) {
  if (!SH) return;
  var s = SH; SH = null; shareSave();
  if (kind === 'stop') { api('/api/nav-share.php', { action: 'stop', token: s.token, secret: s.secret }).catch(function () {}); X.toast(t('stopped')); }
}
function pill() {
  var b = $('#nlLive');
  if (b) b.hidden = !SH;
}

/* the follow page: navigate.html?follow=<token> */
var FW = null;
function follow(token) {
  FW = { token: token, st: null, route: null, puck: null, dest: null, fit: false, hold: false, anim: null };
  document.body.classList.add('nl-follow');
  ['#nlRep', '#nlLive'].forEach(function (s) { var b = $(s); if (b) b.style.display = 'none'; });   /* a viewer, not a driver */
  var c = document.createElement('div'); c.className = 'nl-fcard'; c.id = 'nlFcard';
  c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b><span>' + esc(t('fWait')) + '</span></div>';
  document.body.appendChild(c);
  c.addEventListener('click', function (e) {
    var b = e.target.closest('[data-f]'); if (!b) return;
    if (b.dataset.f === 'center') { FW.hold = false; followCam(true); }
  });
  api('/api/nav-share.php?token=' + token).then(followApply).catch(function () { followCard('gone'); });
  on('share:' + token, followApply);
  var m = X.S.map;
  if (m) m.on('dragstart', function () { if (FW) FW.hold = true; });
  setInterval(function () { if (FW && FW.st) followCard(); }, 5000);
}
function followApply(d) {
  if (!d || !FW) return;
  if (d.route) { FW.route = dec(d.route); followLayers(); }
  FW.st = Object.assign({}, FW.st || {}, d);
  var m = X.S.map, st = FW.st;
  if (m && window.maplibregl) {
    if (!FW.dest) FW.dest = new maplibregl.Marker({ element: wrapM('', 'nl-dest') }).setLngLat([st.dest.lng, st.dest.lat]).addTo(m);
    if (st.pos) {
      var to = [st.pos.lng, st.pos.lat];
      if (!FW.puck) { FW.puck = new maplibregl.Marker({ element: wrapM('<i></i>', 'nl-puck') }).setLngLat(to).addTo(m); }
      else moveMarker(FW.puck, to);
      var i = FW.puck.getElement().querySelector('i');
      if (i) { i.style.display = st.pos.hd == null ? 'none' : ''; i.style.transform = 'rotate(' + (st.pos.hd || 0) + 'deg)'; }
    }
    followCam(!FW.fit);
  }
  followCard();
  if (st.status !== 'live') setTimeout(function () { off('share:' + FW.token); }, 3000);
}
function followCam(first) {
  var m = X.S.map, st = FW && FW.st; if (!m || !st) return;
  if (first && st.pos) {
    FW.fit = true;
    var b = new maplibregl.LngLatBounds([st.pos.lng, st.pos.lat], [st.pos.lng, st.pos.lat]); b.extend([st.dest.lng, st.dest.lat]);
    m.fitBounds(b, { padding: { top: 90, bottom: 200, left: 50, right: 50 }, maxZoom: 15, duration: slow() ? 0 : 800 });
  } else if (st.pos && !FW.hold) m.easeTo({ center: [st.pos.lng, st.pos.lat], duration: slow() ? 0 : 900 });
}
function followLayers() {
  var m = X.S.map; if (!m || !FW || !FW.route) return;
  if (!m.getStyle() || !m.getStyle().sources) { m.once('idle', followLayers); return; }
  var data = { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: FW.route } };
  if (m.getSource('nl-fr')) { m.getSource('nl-fr').setData(data); return; }
  m.addSource('nl-fr', { type: 'geojson', data: data });
  m.addLayer({ id: 'nl-fr-case', type: 'line', source: 'nl-fr', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#0B3D91', 'line-width': 9 } });
  m.addLayer({ id: 'nl-fr', type: 'line', source: 'nl-fr', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#4C8DFF', 'line-width': 5 } });
}
function followCard(gone) {
  var c = $('#nlFcard'); if (!c) return;
  var st = FW && FW.st;
  if (gone || !st || st.status === 'ended' || st.status === 'stopped') {
    c.classList.remove('done');
    c.innerHTML = '<div class="nl-fh"><b>SHARI NAV</b></div><div class="nl-fbig" style="font-size:18px">' + esc(t('fEnd')) + '</div>' +
      '<div class="nl-acts"><a class="nl-b" href="/navigate.html">' + esc(t('fOpen')) + '</a></div>';
    return;
  }
  if (st.status === 'arrived') {
    c.classList.add('done');
    c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b></div><div class="nl-fbig">' + esc(t('fDone', { d: st.dest.name || '' })) + '</div>' +
      '<div class="nl-fsub">' + esc(clock(st.updated)) + '</div>';
    return;
  }
  var stale = Date.now() - (st.updated || 0) > 120000;
  c.innerHTML = '<div class="nl-fh"><span class="nl-dot"></span><b>LIVE</b><span>' + esc(t('fLive')) + ' → ' + esc(st.dest.name || '') + '</span></div>' +
    '<div class="nl-fbig">' + (st.eta ? esc(clock(st.eta)) + ' <span style="font-size:15px;font-weight:500">' + esc(t('fArr')) + '</span>' : '—') + '</div>' +
    '<div class="nl-fsub">' + (st.left != null ? esc(X.fmtDist(st.left)) + ' ' + esc(t('fLeft')) + ' · ' : '') +
      esc(stale ? t('fWeak') : t('fAgo', { n: ago(st.updated) })) + '</div>' +
    '<div class="nl-acts"><button class="nl-b" type="button" data-f="center">' + esc(t('fCenter')) + '</button>' +
      '<a class="nl-b" href="/navigate.html">' + esc(t('fOpen')) + '</a></div>';
}
function moveMarker(mk, to) {
  if (slow()) { mk.setLngLat(to); return; }
  var from = mk.getLngLat(), t0 = performance.now(), dur = 1200;
  (function step(n) {
    var k = Math.min(1, (n - t0) / dur);
    mk.setLngLat([from.lng + (to[0] - from.lng) * k, from.lat + (to[1] - from.lat) * k]);
    if (k < 1) requestAnimationFrame(step);
  })(t0);
}

/* ---------------------------------------------------------------------
   B · S HARI COACHES ON THE ROAD
--------------------------------------------------------------------- */
var BUS = { on: LSg.get('shg:nl:bus', true) !== false, m: {}, by: {} };
function busStart() {
  if (!BUS.on || FW) return;
  api('/api/nav-buses.php').then(busApply).catch(function () {});
  on('bus', busApply);
}
function busStop() { off('bus'); Object.keys(BUS.m).forEach(function (id) { BUS.m[id].remove(); }); BUS.m = {}; BUS.by = {}; }
function busApply(snap) {
  var m = X.S.map; if (!m || !window.maplibregl || !BUS.on) return;
  var seen = {};
  (snap && snap.buses || []).forEach(function (b) {
    seen[b.id] = true; BUS.by[b.id] = b;
    if (BUS.m[b.id]) { moveMarker(BUS.m[b.id], [b.lng, b.lat]); BUS.m[b.id].getElement().querySelector('span').textContent = '→ ' + b.to; return; }
    var el = wrapM('<img src="/assets/img/bus-shg-sm.webp" alt="S Hari Global" loading="lazy"><span>→ ' + esc(b.to) + '</span>', 'nl-bi');
    el.className = 'nl-bus';
    el.addEventListener('click', function (e) { e.stopPropagation(); busSheet(b.id); });
    BUS.m[b.id] = new maplibregl.Marker({ element: el, anchor: 'left' }).setLngLat([b.lng, b.lat]).addTo(m);
  });
  Object.keys(BUS.m).forEach(function (id) { if (!seen[id]) { BUS.m[id].remove(); delete BUS.m[id]; delete BUS.by[id]; } });
}
function busSheet(id) {
  var b = BUS.by[id]; if (!b) return;
  sheet('<h3><img src="/assets/img/bus-shg-sm.webp" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;background:#fff">S Hari Global</h3>' +
    '<p style="color:var(--text,#EEF2FA);font-size:15px;margin-bottom:2px">' + esc(b.from) + ' → ' + esc(b.to) + ' · ' + esc(b.dep) + '</p>' +
    (b.next ? '<p>' + esc(t('busNext')) + ': <b style="color:var(--gold,#D4A843)">' + esc(b.next.name) + '</b> · ' + esc(String(b.next.eta)) + ' ' + esc(t('min')) + ' · ' + esc(String(b.next.km)) + ' km</p>' : '') +
    (b.age != null ? '<p style="font-size:12.5px">' + esc(t('busAge', { n: ago(Date.now() - b.age * 1000) })) + (b.kmh != null ? ' · ' + b.kmh + ' km/h' : '') + '</p>' : '') +
    '<div class="nl-acts"><a class="nl-b cta" href="/">' + esc(t('busBook')) + '</a><a class="nl-b" href="/#/track">' + esc(t('busTrack')) + '</a></div>');
}

/* ---------------------------------------------------------------------
   C · ROAD REPORTS
--------------------------------------------------------------------- */
var REP = { on: LSg.get('shg:nl:rep', true) !== false, m: {}, by: {}, at: null, atT: 0, said: {} };
function repLoad() {
  var h = here(); if (!h || !REP.on || FW) return;
  REP.at = h; REP.atT = Date.now();
  api('/api/nav-reports.php?lat=' + h[1].toFixed(4) + '&lng=' + h[0].toFixed(4)).then(function (d) {
    var keep = {};
    (d.reports || []).forEach(function (r) { keep[r.id] = true; repPut(r); });
    Object.keys(REP.by).forEach(function (id) { if (!keep[id]) repDel(+id); });
  }).catch(function () {});
}
function repPut(r) {
  if (r.status !== 'active') { repDel(r.id); return; }
  var m = X.S.map, k = KIND[r.kind]; if (!m || !k || !window.maplibregl) return;
  REP.by[r.id] = r;
  if (REP.m[r.id]) return;
  var el = wrapM('<span>' + k[1] + '</span>', 'nl-ri');
  el.className = 'nl-rep';
  el.addEventListener('click', function (e) { e.stopPropagation(); repSheet(r.id); });
  REP.m[r.id] = new maplibregl.Marker({ element: el }).setLngLat([r.lng, r.lat]).addTo(m);
}
function repDel(id) { if (REP.m[id]) REP.m[id].remove(); delete REP.m[id]; delete REP.by[id]; }
function repLive(d) {
  if (!d || !d.r) return;
  if (d.op === 'del') { repDel(d.r.id); return; }
  var h = REP.at || here();
  if (h && X.hav(h, [d.r.lng, d.r.lat]) < 40000) repPut(d.r);
}
function repStop() { off('rep'); Object.keys(REP.m).forEach(function (id) { repDel(+id); }); }
function repSheet(id) {
  var r = REP.by[id], k = r && KIND[r.kind]; if (!k) return;
  var el = sheet('<h3><span style="font-size:24px">' + k[1] + '</span>' + esc(L(k[2])) + '</h3>' +
    '<p>' + esc(t('repAgo', { n: ago(r.at) })) + (r.note ? ' · ' + esc(r.note) : '') + ' · 👍 ' + r.up + ' · 👎 ' + r.down + '</p>' +
    '<div class="nl-acts"><button class="nl-b cta" type="button" data-v="up">👍 ' + esc(t('repStill')) + '</button>' +
    '<button class="nl-b" type="button" data-v="down">👎 ' + esc(t('repGone')) + '</button></div>');
  el.querySelectorAll('[data-v]').forEach(function (b) {
    b.onclick = function () {
      sheetClose();
      api('/api/nav-reports.php', { action: 'vote', id: r.id, v: b.dataset.v }).then(function (d) { repPut(d.report); X.toast(t('repVoted')); })
        .catch(function (e) { X.toast(e.status === 409 ? t('repVoted') : t('failed')); });
    };
  });
}
function repPick() {
  var el = sheet('<h3>⚠️ ' + esc(t('repQ')) + '</h3>' +
    (SR ? '<div class="nl-acts" style="margin:0 0 4px"><button class="nl-b" type="button" data-k="_voice">🎙️ ' + esc(t('repVoice')) + '</button></div>' : '') +
    '<div class="nl-grid">' + KINDS.map(function (k) {
      return '<button class="nl-k" type="button" data-k="' + k[0] + '"><span>' + k[1] + '</span>' + esc(L(k[2])) + '</button>';
    }).join('') + '</div>');
  el.querySelectorAll('[data-k]').forEach(function (b) {
    b.onclick = function () { if (b.dataset.k === '_voice') repVoice(b); else { sheetClose(); repAdd(b.dataset.k); } };
  });
}
function repAdd(kind) {
  var S = X.S, p = S.me ? [S.me.lng, S.me.lat] : (QS.has('debug') ? here() : null);
  if (!p) { X.toast(t('repLoc'), 3500); X.startGeo(); return; }
  api('/api/nav-reports.php', { action: 'add', kind: kind, lat: p[1], lng: p[0], hd: S.me && S.me.heading != null ? S.me.heading : null })
    .then(function (d) { repPut(d.report); REP.said[d.report.id] = true; X.toast(KIND[kind][1] + ' ' + t('repThanks'), 3500); X.vib(25); })
    .catch(function (e) { X.toast(e.status === 429 ? t('repMany') : t('failed')); });
}
var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function repVoice(btn) {
  var rec; try { rec = new SR(); } catch (e) { return; }
  var tried = false;
  rec.lang = lang() === 'en' ? 'en-IN' : lang() === 'hi' ? 'hi-IN' : 'ne-NP';
  rec.interimResults = false; rec.maxAlternatives = 3;
  btn.textContent = '🎙️ ' + t('listen');
  rec.onresult = function (e) {
    var said = ''; for (var i = 0; i < e.results[0].length; i++) said += ' ' + e.results[0][i].transcript;
    var k = KINDS.filter(function (x) { return x[3].test(said); })[0];
    if (!k) { btn.textContent = '🎙️ ' + t('noHeard'); return; }
    var el = sheet('<h3><span style="font-size:24px">' + k[1] + '</span>' + esc(t('repSure', { k: L(k[2]) })) + '</h3><p>“' + esc(said.trim()) + '”</p>' +
      '<div class="nl-acts"><button class="nl-b cta" type="button" data-y>' + esc(t('yes')) + '</button></div>');
    el.querySelector('[data-y]').onclick = function () { sheetClose(); repAdd(k[0]); };
  };
  rec.onerror = function (e) { if (e.error === 'language-not-supported' && !tried) { tried = true; rec.lang = 'hi-IN'; try { rec.start(); } catch (x) {} } };
  try { rec.start(); X.voUnlock(); } catch (e) {}
}
/* on the route, 1 km ahead: say it once */
function repAhead(N) {
  if (!REP.on || !N || N.preview || N.arrived || !N.route || !N.route.L) return;
  var ids = Object.keys(REP.by); if (!ids.length) return;
  var Lr = N.route.L, c = Lr.c, cum = Lr.cum, a = N.along || 0, i0 = Math.max(0, (N.i || 0) - 1);
  ids.forEach(function (id) {
    var r = REP.by[id]; if (REP.said[id]) return;
    var p = [r.lng, r.lat];
    if (X.hav(p, c[Math.min(i0, Lr.n - 1)]) > 3000) return;            /* cheap reject */
    for (var j = i0; j < Lr.n - 1 && cum[j] - a < 1100; j++) {
      var q = segDist(p, c[j], c[j + 1]);
      if (q.d < 60) {
        var ah = cum[j] + q.u * (cum[j + 1] - cum[j]) - a;
        if (ah > 120 && ah <= 1100) { REP.said[id] = true; repSay(r, ah); }
        else if (ah <= 120) REP.said[id] = true;                        /* already on it: no late warning */
        return;
      }
    }
  });
}
function repSay(r, ah) {
  var k = KIND[r.kind], d = ah >= 850 ? t('km1') : Math.round(ah / 100) * 100 + ' ' + t('mtr');
  var s = t('ahead', { d: d, k: L(k[2]) });
  X.toast(k[1] + ' ' + s, 6000); X.vib([60, 80, 60]);
  if (X.S.sound !== 'off') { try { X.sayText(s, lang()); } catch (e) {} }
}

/* ---------------------------------------------------------------------
   PAGE WIRING
--------------------------------------------------------------------- */
function toggle(k) {
  if (k === 'bus') { BUS.on = !BUS.on; LSg.set('shg:nl:bus', BUS.on); if (BUS.on) busStart(); else busStop(); }
  if (k === 'rep') { REP.on = !REP.on; LSg.set('shg:nl:rep', REP.on); if (REP.on) { on('rep', repLive); repLoad(); } else repStop(); }
}
function tg(k, onv, label) {
  return '<button class="tg" type="button" data-nl="' + k + '" style="width:100%;text-align:left"><span>' + esc(label) + '</span><i class="sw' + (onv ? ' on' : '') + '"></i></button>';
}
function hooks() {
  var H = X.hook; if (!H) return;
  H('navFix', function (f, N) {
    if (SH && !SHx.N) {                                     /* a share from before a reload: same destination? */
      var to = X.S.to; if (to && SH.to && X.hav([to.lng, to.lat], [SH.to.lng, SH.to.lat]) < 300) { SHx.N = N; SHx.route = null; } else shareEnd('stop');
    }
    if (SH && SHx.N === N) sharePos(N, f, false);
    repAhead(N);
  });
  H('arrive', function (N) {
    if (SH && SHx.N === N) { var me = X.S.me; sharePos(N, me ? { lat: me.lat, lng: me.lng, speed: 0, heading: null } : { lat: SH.to.lat, lng: SH.to.lng }, true); SH = null; shareSave(); }
  });
  H('navStart', function (N) { REP.said = {}; if (SH && SHx.N && SHx.N !== N) shareEnd('stop'); });
  H('navStop', function (N) { if (SH && SHx.N === N && !N.arrived) shareEnd('stop'); SHx.N = null; });
  H('navMoreHtml', function (N) {
    if (!N || N.preview) return '';
    return '<h3 style="margin-top:20px">' + esc(t('liveH')) + '</h3>' +
      '<button class="btn ghost" type="button" id="nlShareB" style="width:100%;height:52px">' + (SH ? '🔴 ' + esc(t('sharing')) : '📍 ' + esc(t('shareTrip'))) + '</button>' +
      '<button class="btn ghost" type="button" id="nlRepB" style="width:100%;height:52px;margin-top:8px">⚠️ ' + esc(t('report')) + '</button>';
  });
  H('navMoreBind', function () {
    var a = $('#nlShareB'), b = $('#nlRepB');
    if (a) a.onclick = function () { if (X.closeModal) X.closeModal(); if (SH) shareSheet(); else shareStart(); };
    if (b) b.onclick = function () { if (X.closeModal) X.closeModal(); repPick(); };
  });
  H('layersHtml', function () { return '<h3 style="margin-top:20px">' + esc(t('liveH')) + '</h3>' + tg('bus', BUS.on, t('busL')) + tg('rep', REP.on, t('repL')); });
  H('layersBind', function (rerender) {
    document.querySelectorAll('#modalCard [data-nl]').forEach(function (b) { b.onclick = function () { toggle(b.dataset.nl); if (rerender) rerender(); }; });
  });
  H('styleLoad', function () { if (FW) setTimeout(followLayers, 0); });
}
function build() {
  css();
  var ctr = $('#ctrls');
  if (ctr) {
    var rb = document.createElement('button');
    rb.type = 'button'; rb.className = 'fab nl-fab'; rb.id = 'nlRep'; rb.setAttribute('aria-label', 'Report on the road');
    rb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5L2.8 19.5h18.4z"/><path d="M12 9.5v4.5M12 16.8v.2"/></svg>';
    rb.onclick = repPick;
    var lp = document.createElement('button');
    lp.type = 'button'; lp.className = 'fab nl-livepill'; lp.id = 'nlLive'; lp.textContent = 'LIVE'; lp.hidden = true;
    lp.setAttribute('aria-label', 'Live trip share');
    lp.onclick = shareSheet;
    var after = $('#sgFab');
    ctr.insertBefore(rb, after ? after.nextSibling : ctr.firstChild);
    ctr.insertBefore(lp, rb);
    if (FW) rb.hidden = true;
  }
  pill();
}
function start() {
  if (X || !window.SHGNAV_X) return;
  X = window.SHGNAV_X;
  var ft = QS.get('follow');
  build();
  hooks();
  var ready = function () {
    if (ft && /^[a-f0-9]{32}$/.test(ft)) follow(ft);
    busStart();
    if (REP.on && !FW) { on('rep', repLive); repLoad(); }
  };
  (function waitMap(n) { if (X.S.map || n > 60) ready(); else setTimeout(function () { waitMap(n + 1); }, 300); })(0);   /* DOM markers need the map, not its style */
  /* reports follow the rider: a new list after 10 km or 5 minutes */
  setInterval(function () {
    if (!REP.on || FW) return;
    var h = here();
    if (h && (!REP.at || X.hav(h, REP.at) > 10000 || Date.now() - REP.atT > 300000)) repLoad();
  }, 30000);
  if (QS.has('debug')) window.SHGLIVE = { LC: LC, SH: function () { return SH; }, BUS: BUS, REP: REP, FW: function () { return FW; }, repAdd: repAdd, shareStart: shareStart, enc: enc, dec: dec };
}
if (window.SHGNAV_X) start(); else document.addEventListener('shgnav:ready', start, { once: true });
})();
