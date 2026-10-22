/* =====================================================================
   SHARI NAV · assets/nav/nav-more.js  (22 Oct 2026, Phase 3)

   Google Maps features, rebuilt on our own stack (talks to the page only
   through window.SHGNAV_X and its hook()):
     · Leave at / Arrive by    "leave by 6:40 to arrive 9:00"
     · Tolls                   how often the route gets on a toll road, km,
                               approx FASTag cost; two-wheelers pay none
     · Road check              narrow / single-lane / unpaved stretches from
                               our engine (/api/nav-attrs.php)
     · Rain on route           MET Norway at points along the way, at the
                               hour you will be there
     · Ask Maps chips          under the search box: petrol in the next
                               50 km, veg food on the way, EV / CNG / LPG ...
     · Speed camera / school   spoken ~400 m before (OSM, our extract)
     · Mini navigation (PiP)   the turn keeps showing over other apps
     · Explore                 our own travel guide + sights near a place
   Air quality is left out on purpose: the free Open-Meteo API is for
   non-commercial use only.
===================================================================== */
(function () {
'use strict';

var X = null;
var QS = new URLSearchParams(location.search);

var TX = {
  now: { ne: 'अहिले निस्कने', hi: 'अभी निकलें', en: 'Leave now' },
  dep: { ne: 'यति बेला निस्कने', hi: 'इस समय निकलें', en: 'Depart at' },
  arr: { ne: 'यति बेला पुग्ने', hi: 'इस समय पहुँचें', en: 'Arrive by' },
  lNow: { ne: 'अहिले निस्कँदा {a} मा पुगिन्छ', hi: 'अभी निकलें तो {a} तक पहुँचेंगे', en: 'Leave now, arrive {a}' },
  lDep: { ne: '{d} मा निस्कँदा {a} मा पुगिन्छ', hi: '{d} पर निकलें तो {a} तक पहुँचेंगे', en: 'Leave {d}, arrive {a}' },
  lArr: { ne: '{a} मा पुग्न {d} मा निस्कनुस्', hi: '{a} तक पहुँचने के लिए {d} पर निकलें', en: 'Leave by {d} to arrive {a}' },
  late: { ne: 'त्यो समयमा पुग्न अब ढिला भयो', hi: 'उस समय तक पहुँचने में अब देर हो गई', en: 'Too late to arrive by then' },
  when: { ne: 'कहिले जाने?', hi: 'कब जाना है?', en: 'When?' },
  ok: { ne: 'ठीक छ', hi: 'ठीक है', en: 'Done' },
  tmw: { ne: 'भोलि', hi: 'कल', en: 'tomorrow' },
  toll: { ne: 'टोल: {n} पटक · {k} km · करिब {c} (FASTag, अनुमानित)', hi: 'टोल: {n} बार · {k} km · लगभग {c} (FASTag, अनुमानित)', en: 'Tolls: {n}× · {k} km · about {c} (FASTag, approx.)' },
  tollNo: { ne: 'FASTag नभए नगदमा दोब्बर लाग्छ', hi: 'FASTag न हो तो नकद में दोगुना', en: 'Without FASTag cash is charged double' },
  tollMoto: { ne: 'दुईपाङ्ग्रेलाई टोल लाग्दैन', hi: 'दोपहिया पर टोल नहीं लगता', en: 'Two-wheelers pay no toll' },
  check: { ne: 'बाटो जाँच (साँघुरो / कच्ची)', hi: 'रास्ता जाँचें (संकरा / कच्चा)', en: 'Road check (narrow / unpaved)' },
  checking: { ne: 'बाटो जाँच्दैछु…', hi: 'रास्ता जाँच रहा हूँ…', en: 'Checking the road…' },
  narrow: { ne: 'साँघुरो बाटो ~{k} km', hi: 'संकरा रास्ता ~{k} km', en: 'Narrow road ~{k} km' },
  rough: { ne: 'कच्ची बाटो ~{k} km', hi: 'कच्चा रास्ता ~{k} km', en: 'Unpaved ~{k} km' },
  roadOk: { ne: 'बाटो ठीक छ — साँघुरो वा कच्ची खण्ड भेटिएन', hi: 'रास्ता ठीक है — संकरा या कच्चा हिस्सा नहीं मिला', en: 'Road looks fine — no narrow or unpaved stretch found' },
  coach: { ne: '(बसको लागि सावधानी)', hi: '(बस के लिए सावधानी)', en: '(take care with a coach)' },
  rain: { ne: 'बाटोमा वर्षा: {p} नजिक ~{t}', hi: 'रास्ते में बारिश: {p} के पास ~{t}', en: 'Rain on the way: near {p} ~{t}' },
  dry: { ne: 'बाटोभरि वर्षा देखिँदैन', hi: 'पूरे रास्ते बारिश नहीं दिखती', en: 'No rain expected on the way' },
  explore: { ne: 'नजिकै घुम्ने ठाउँ', hi: 'पास में घूमने की जगहें', en: 'Explore nearby' },
  exploreAt: { ne: '{p} नजिक घुम्ने ठाउँ', hi: '{p} के पास घूमने की जगहें', en: 'Explore near {p}' },
  ourGuide: { ne: 'S Hari यात्रा गाइड', hi: 'S Hari यात्रा गाइड', en: 'S Hari travel guide' },
  season: { ne: 'उत्तम समय', hi: 'सबसे अच्छा समय', en: 'Best time' },
  none: { ne: 'केही भेटिएन', hi: 'कुछ नहीं मिला', en: 'Nothing found' },
  ahead: { ne: '{k} अगाडि', hi: '{k} आगे', en: '{k} ahead' },
  off: { ne: 'बाटोबाट {m}', hi: 'रास्ते से {m}', en: '{m} off the route' },
  near: { ne: 'नजिकै', hi: 'पास में', en: 'nearby' },
  askAI: { ne: 'AI लाई सोध्नुस्', hi: 'AI से पूछें', en: 'Ask AI' },
  cam: { ne: 'अगाडि स्पीड क्यामेरा{s}', hi: 'आगे स्पीड कैमरा{s}', en: 'Speed camera ahead{s}' },
  camLim: { ne: ', गति सीमा {v}', hi: ', गति सीमा {v}', en: ', limit {v}' },
  school: { ne: 'अगाडि विद्यालय क्षेत्र — बिस्तारै चलाउनुस्', hi: 'आगे स्कूल क्षेत्र — धीरे चलाएं', en: 'School zone ahead — slow down' },
  pip: { ne: 'सानो झ्याल (PiP) मा नेभिगेसन', hi: 'छोटी विंडो (PiP) में नेविगेशन', en: 'Mini navigation (picture-in-picture)' },
  pipNo: { ne: 'यो ब्राउजरमा सानो झ्याल चल्दैन', hi: 'इस ब्राउज़र में छोटी विंडो नहीं चलती', en: 'Picture-in-picture is not available here' },
  pipHint: { ne: 'अब अर्को एप खोल्दा पनि मोड देखिइरहन्छ', hi: 'अब दूसरा ऐप खोलने पर भी मोड़ दिखता रहेगा', en: 'The next turn stays on screen over other apps' },
  failed: { ne: 'अहिले सकिएन', hi: 'अभी नहीं हो पाया', en: 'Could not do that now' },
  usual: { ne: 'तपाईंको सामान्य बाटो{v} — प्रायः {u} लाग्छ, आज अनुमान {n}', hi: 'आपका आम रास्ता{v} — आमतौर पर {u}, आज अनुमान {n}', en: 'Your usual route{v} — usually {u}, today about {n}' },
  usualVia: { ne: ' (via {r})', hi: ' (via {r})', en: ' (via {r})' },
  slower: { ne: '{m} मिनेट ढिलो', hi: '{m} मिनट धीमा', en: '{m} min slower' },
  faster: { ne: '{m} मिनेट छिटो', hi: '{m} मिनट तेज़', en: '{m} min faster' },
  otherWay: { ne: 'आजको छिटो बाटो फरक छ (via {r})', hi: 'आज का तेज़ रास्ता अलग है (via {r})', en: 'Today\'s fastest way differs (via {r})' },
  visitsT: { ne: 'मेरा भ्रमण', hi: 'मेरी विज़िट', en: 'My visits' },
  visitsNote: { ne: 'तपाईं पुगेका ठाउँ — यो फोनमा मात्र (sync गरेमा तपाईंका आफ्नै device मा)', hi: 'आप जहाँ पहुँचे — केवल इस फ़ोन में (sync करने पर आपके अपने device में)', en: 'Places you arrived at — this phone only (your own devices if synced)' },
  noVisits: { ne: 'अहिलेसम्म कुनै भ्रमण छैन', hi: 'अभी तक कोई विज़िट नहीं', en: 'No visits yet' },
  delAll: { ne: 'सबै मेटाउनुस्', hi: 'सब मिटाएं', en: 'Delete all' },
  delSure: { ne: 'फेरि थिच्नुस् — सबै मेटिन्छ', hi: 'फिर दबाएं — सब मिट जाएगा', en: 'Tap again — everything goes' },
  today: { ne: 'आज', hi: 'आज', en: 'Today' }, yday: { ne: 'हिजो', hi: 'कल', en: 'Yesterday' },
  askQ: { ne: 'AI लाई सोध्नुस्: “{q}”', hi: 'AI से पूछें: “{q}”', en: 'Ask AI: “{q}”' },
  steep: { ne: 'ठाडो उकालो: ~{g}% ({m}) · {k} मा', hi: 'खड़ी चढ़ाई: ~{g}% ({m}) · {k} पर', en: 'Steep climb: ~{g}% ({m}) at {k}' },
  steepSay: { ne: 'अगाडि ठाडो उकालो', hi: 'आगे खड़ी चढ़ाई', en: 'Steep climb ahead' },
  flat: { ne: 'ठाडो उकालो छैन', hi: 'खड़ी चढ़ाई नहीं', en: 'No steep climbs' },
  hike: { ne: '🥾 पैदल ट्रेल', hi: '🥾 पैदल ट्रेल', en: '🥾 Hiking trails' },
  close: { ne: 'बन्द', hi: 'बंद', en: 'Close' }
};
/* Ask Maps chips: what to look for, and where */
var ASK = [
  { id: 'fuel50', e: '⛽', l: { ne: 'पेट्रोल अर्को ५० km मा', hi: 'अगले 50 km में पेट्रोल', en: 'Petrol in the next 50 km' }, q: 'petrol', max: 50000 },
  { id: 'veg', e: '🥗', l: { ne: 'शाकाहारी खाना बाटोमा', hi: 'रास्ते में शाकाहारी खाना', en: 'Veg food on the way' }, q: 'restaurant', veg: true },
  { id: 'ev', e: '⚡', l: { ne: 'EV चार्जिङ', hi: 'EV चार्जिंग', en: 'EV charging' }, f: 'ev' },
  { id: 'cng', e: '🟢', l: { ne: 'CNG स्टेशन', hi: 'CNG स्टेशन', en: 'CNG station' }, f: 'cng' },
  { id: 'lpg', e: '🔵', l: { ne: 'LPG (अटो ग्यास)', hi: 'LPG (ऑटो गैस)', en: 'LPG (auto gas)' }, f: 'lpg' },
  { id: 'atm', e: '🏧', l: { ne: 'ATM बाटोमा', hi: 'रास्ते में ATM', en: 'ATM on the way' }, q: 'atm' },
  { id: 'wc', e: '🚻', l: { ne: 'शौचालय', hi: 'शौचालय', en: 'Toilets' }, q: 'toilet' },
  { id: 'temple', e: '🛕', l: { ne: 'बाटोमा मन्दिर', hi: 'रास्ते में मंदिर', en: 'Temples on the way' }, q: 'temple' },
  { id: 'ai', e: '🙏', l: TX.askAI }
];
var VEG = /veg|vaishno|शाकाहारी|शुद्ध|pure|jain|saatvik|satvik|udupi|bhojanalay|भोजनालय|thali/i;
var KIND_E = { 'tourism:attraction': '⭐', 'tourism:viewpoint': '🏞️', 'tourism:museum': '🏛️', 'historic:fort': '🏰', 'historic:castle': '🏰',
               'historic:monument': '🗿', 'tourism:zoo': '🦁', 'tourism:theme_park': '🎡', 'leisure:nature_reserve': '🌳', 'natural:waterfall': '💧', 'waterway:waterfall': '💧' };

/* ---------------------------------------------------------------------
   HELPERS
--------------------------------------------------------------------- */
function $(s, r) { return (r || document).querySelector(s); }
function lang() { var l = X && X.S.lang; return l === 'hi' || l === 'en' ? l : 'ne'; }
function L(o) { return o ? (o[lang()] || o.en || '') : ''; }
function t(k, v) { var s = L(TX[k]) || k; if (v) Object.keys(v).forEach(function (n) { s = s.split('{' + n + '}').join(v[n]); }); return s; }
function esc(s) { return X.esc(s); }
function here() { var S = X.S; if (S.me) return [S.me.lng, S.me.lat]; if (S.map) { var c = S.map.getCenter(); return [c.lng, c.lat]; } return null; }
function getJ(url, opt, ms) {
  return X.fetchT(url, opt || { credentials: 'same-origin' }, ms || 12000).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!j || j.ok === false) { var e = new Error('http ' + r.status); e.status = r.status; throw e; }
      return j.data !== undefined ? j.data : j;
    });
  });
}
function clock(ms) { var d = new Date(ms); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
function dayClock(ms) { var d = new Date(ms), n = new Date(); return (d.toDateString() !== n.toDateString() ? (d.getDate() === new Date(Date.now() + 864e5).getDate() ? t('tmw') : d.toLocaleDateString()) + ' ' : '') + clock(ms); }
function money(v) { return Math.round(v / 10) * 10 >= 1000 ? (Math.round(v / 10) * 10).toLocaleString('en-IN') : String(Math.round(v / 10) * 10); }
function segDist(p, a, b) {
  var k = Math.cos(p[1] * Math.PI / 180) * 111320, ky = 110540;
  var ax = (a[0] - p[0]) * k, ay = (a[1] - p[1]) * ky, bx = (b[0] - p[0]) * k, by = (b[1] - p[1]) * ky;
  var dx = bx - ax, dy = by - ay, l2 = dx * dx + dy * dy, u = l2 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / l2)) : 0;
  var x = ax + u * dx, y = ay + u * dy;
  return { d: Math.sqrt(x * x + y * y), u: u };
}
/* encoded polyline, precision 6 (what Valhalla reads) */
function enc6(c) {
  var out = '', pl = 0, pg = 0, step = Math.max(1, Math.ceil(c.length / 8000));
  function num(v) { v = v < 0 ? ~(v << 1) : v << 1; var s = ''; while (v >= 0x20) { s += String.fromCharCode((0x20 | (v & 0x1f)) + 63); v >>>= 5; } return s + String.fromCharCode(v + 63); }
  function add(p) { var la = Math.round(p[1] * 1e6), lg = Math.round(p[0] * 1e6); out += num(la - pl) + num(lg - pg); pl = la; pg = lg; }
  for (var i = 0; i < c.length; i += step) add(c[i]);
  if ((c.length - 1) % step) add(c[c.length - 1]);
  return out;
}
/* the route in use: the one being driven, else the one picked on the directions sheet */
function curRoute() {
  var S = X.S;
  if (S.nav && S.nav.route) return { r: S.nav.route, a: S.nav.along || 0, i: S.nav.i || 0 };
  if (S.cur && S.cur.routes && S.cur.routes[S.sel]) { var r = S.cur.routes[S.sel]; if (!r.L && X.prepRoute) X.prepRoute(r); return r.L ? { r: r, a: 0, i: 0 } : null; }
  return null;
}

/* ---------------------------------------------------------------------
   SHEET
--------------------------------------------------------------------- */
var SHT = { open: false, hist: false };
function css() {
  var st = document.createElement('style');
  st.id = 'nmCss';
  st.textContent = [
    '#nmBox{display:grid;gap:6px;margin:8px 0 0}',
    '.nm-row{display:flex;align-items:center;gap:8px;min-height:44px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);color:var(--text,#EEF2FA);font:13.5px/1.4 Inter,system-ui,sans-serif;text-align:left;width:100%;cursor:pointer}',
    '.nm-row small{display:block;color:var(--text3,#6F7D99);font-size:12px}.nm-row b{font-weight:600}',
    '.nm-row.warn{background:rgba(232,101,46,.1);border-color:rgba(232,101,46,.3)}.nm-row.rain{background:rgba(76,141,255,.1);border-color:rgba(76,141,255,.3)}',
    '.nm-ask{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;padding:8px 12px 4px;touch-action:pan-x}.nm-ask::-webkit-scrollbar{display:none}',
    '.nm-chip{flex:none;min-height:40px;padding:0 13px;border-radius:20px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);font:500 13px Inter,system-ui,sans-serif;display:inline-flex;align-items:center;gap:6px;cursor:pointer;position:relative}',
    '.nm-chip::after{content:"";position:absolute;left:0;right:0;top:-3px;bottom:-3px}',
    '.nm-wrap{position:fixed;inset:0;z-index:62;pointer-events:none}.nm-scrim{position:absolute;inset:0;background:rgba(3,7,16,.4);pointer-events:auto}',
    '.nm-sheet{position:absolute;left:calc(var(--sl,0px) + 8px);right:calc(var(--sr,0px) + 8px);bottom:calc(var(--sb,0px) + 8px);max-width:520px;margin:0 auto;max-height:80vh;overflow-y:auto;overscroll-behavior:contain;',
    '  border-radius:22px;pointer-events:auto;padding:14px 14px 12px;background:rgba(14,24,44,.96);-webkit-backdrop-filter:blur(20px);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.09);box-shadow:0 18px 60px rgba(0,0,0,.55);color:var(--text,#EEF2FA);font:14px/1.5 Inter,system-ui,sans-serif}',
    '.nm-sheet h3{margin:0 44px 8px 0;font:600 16px Poppins,Inter,system-ui,sans-serif}',
    '.nm-x{position:absolute;top:8px;right:8px;width:44px;height:44px;border-radius:50%;border:none;background:rgba(255,255,255,.06);color:var(--text2,#A8B4CC);font-size:20px;cursor:pointer}',
    '.nm-seg{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 10px}.nm-seg button{min-height:40px;padding:0 14px;border-radius:20px;border:1px solid rgba(255,255,255,.14);background:transparent;color:var(--text2,#A8B4CC);font:500 13.5px Inter,system-ui,sans-serif;cursor:pointer}',
    '.nm-seg button.on{background:var(--gold,#D4A843);border-color:var(--gold,#D4A843);color:#1A1405}',
    '.nm-time{width:100%;height:46px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:var(--text,#EEF2FA);font:16px Inter,system-ui,sans-serif;padding:0 12px;color-scheme:dark}',
    '.nm-b{min-height:44px;padding:0 18px;border-radius:22px;border:none;background:var(--cta,#E8652E);color:#fff;font:600 14px Inter,system-ui,sans-serif;cursor:pointer;margin-top:10px}',
    '.nm-list{display:grid;gap:6px}.nm-li{display:flex;align-items:center;gap:10px;min-height:52px;padding:8px 10px;border-radius:12px;background:rgba(255,255,255,.04);border:none;color:var(--text,#EEF2FA);text-align:left;width:100%;cursor:pointer;font:14px/1.35 Inter,system-ui,sans-serif}',
    '.nm-li .e{font-size:22px;width:30px;text-align:center;flex:none}.nm-li small{display:block;color:var(--text3,#6F7D99);font-size:12px}',
    '.nm-card{display:flex;gap:10px;padding:8px;border-radius:14px;background:rgba(212,168,67,.08);border:1px solid rgba(212,168,67,.25);cursor:pointer;text-align:left;width:100%;color:var(--text,#EEF2FA);font:13px/1.4 Inter,system-ui,sans-serif}',
    '.nm-card img{width:64px;height:64px;border-radius:10px;object-fit:cover;flex:none;background:#0E1930}.nm-card b{display:block;font-size:14px}',
    '.nm-card p{margin:2px 0 0;color:var(--text2,#A8B4CC);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.nm-h{margin:10px 2px 6px;font:600 13px Inter,system-ui,sans-serif;color:var(--text2,#A8B4CC);letter-spacing:.02em}',
    '#nmExplore{margin-top:10px}'
  ].join('\n') + '\n' + GLASS;
  document.head.appendChild(st);
}
function sheet(html) {
  var w = $('#nmWrap');
  if (!w) {
    w = document.createElement('div'); w.className = 'nm-wrap'; w.id = 'nmWrap'; w.hidden = true;
    w.innerHTML = '<div class="nm-scrim" id="nmScrim"></div><section class="nm-sheet" role="dialog" aria-modal="true" id="nmSheet"></section>';
    document.body.appendChild(w);
    $('#nmScrim').onclick = function () { sheetClose(); };
    addEventListener('popstate', function () { if (SHT.open && SHT.hist) { SHT.hist = false; sheetClose(true); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && SHT.open) { e.stopImmediatePropagation(); sheetClose(); } }, true);
  }
  $('#nmSheet').innerHTML = '<button class="nm-x" type="button" data-nmx aria-label="' + esc(t('close')) + '">×</button>' + html;
  $('#nmSheet [data-nmx]').onclick = function () { sheetClose(); };
  if (!SHT.open) {
    SHT.open = true; w.hidden = false;
    try { history.pushState({ lvl: X.S.level, d: X.S.depth, nm: 1 }, ''); SHT.hist = true; } catch (e) { SHT.hist = false; }
  }
  return $('#nmSheet');
}
function sheetClose(fromPop, then) {
  if (!SHT.open) { if (then) then(); return; }
  SHT.open = false; $('#nmWrap').hidden = true;
  if (!fromPop && SHT.hist) {
    SHT.hist = false;
    if (then) { var f = function () { removeEventListener('popstate', f); setTimeout(then, 0); }; addEventListener('popstate', f); }
    try { history.back(); } catch (e) { if (then) then(); }
  } else if (then) then();
}

/* ---------------------------------------------------------------------
   DIRECTIONS SHEET: when, tolls, road check, rain, explore
--------------------------------------------------------------------- */
var WHEN = { type: 'now', t: 0 };
var WX = {};          /* "lat,lng" rounded -> weather answer */
var RC = {};          /* route -> road check answer */
function nmBox() {
  var b = $('#nmBox');
  if (!b) {
    var cb = $('#costBox'); if (!cb) return null;
    b = document.createElement('div'); b.id = 'nmBox';
    cb.parentNode.insertBefore(b, cb.nextSibling);
  }
  return b;
}
function dirSheet(r) {
  var b = nmBox(); if (!b || !r) return;
  var S = X.S, h = '';
  /* when */
  var dur = r.dur * 1000, line;
  if (WHEN.type === 'arrive' && WHEN.t) {
    var leave = WHEN.t - dur;
    line = leave < Date.now() - 60e3 ? t('late') : t('lArr', { a: dayClock(WHEN.t), d: dayClock(leave) });
  } else if (WHEN.type === 'depart' && WHEN.t) line = t('lDep', { d: dayClock(WHEN.t), a: dayClock(WHEN.t + dur) });
  else line = t('lNow', { a: dayClock(Date.now() + dur) });
  h += '<button class="nm-row" type="button" data-nm="when"><span>🕐</span><span><b>' + esc(line) + '</b><small>' + esc(t('when')) + '</small></span></button>';
  h += usualRow(r);
  /* tolls */
  if (S.mode === 'moto' && r.tolls) h += '<div class="nm-row"><span>🛵</span><span>' + esc(t('tollMoto')) + '</span></div>';
  else if ((S.mode === 'car' || S.mode === 'bus') && r.tollKm > 0) {
    var rate = S.mode === 'bus' ? 7 : 2.2;             /* ₹/km on Indian NH plazas: car ~2.2, bus ~7 (NHAI 2025-26 order of size) */
    h += '<div class="nm-row"><span>🛣️</span><span>' + esc(t('toll', { n: Math.max(1, r.tollN || 1), k: r.tollKm, c: '₹' + money(r.tollKm * rate) })) +
      '<small>' + esc(t('tollNo')) + '</small></span></div>';
  }
  /* road check */
  if (S.mode === 'car' || S.mode === 'bus' || S.mode === 'moto') {
    var rc = RC.r === r ? RC.v : null;
    if (rc === 'busy') h += '<div class="nm-row"><span>🔍</span><span>' + esc(t('checking')) + '</span></div>';
    else if (rc && rc.narrow) h += rcHtml(rc);
    else if (r.src === 'own' && r.dist < 400000) h += '<button class="nm-row" type="button" data-nm="check"><span>🔍</span><span>' + esc(t('check')) + '</span></button>';
  }
  /* rain */
  var rw = WX.r === r ? WX.v : null;
  if (rw && rw.wet) h += '<div class="nm-row rain"><span>🌧️</span><span>' + esc(t('rain', { p: rw.wet.p || t('near'), t: clock(rw.wet.t) })) + (rw.wet.mm ? '<small>' + rw.wet.mm + ' mm</small>' : '') + '</span></div>';
  else if (rw && rw.n >= 2) h += '<div class="nm-row"><span>☀️</span><span>' + esc(t('dry')) + '</span></div>';
  h += steepRow(r);
  hikePaint();
  /* explore at the destination */
  if (S.to && !S.to.me) h += '<button class="nm-row" type="button" data-nm="explore"><span>🧭</span><span>' + esc(t('exploreAt', { p: S.to.name || '' })) + '</span></button>';
  b.innerHTML = h;
  b.querySelectorAll('[data-nm]').forEach(function (el) {
    el.onclick = function () {
      var k = el.dataset.nm;
      if (k === 'when') whenSheet();
      else if (k === 'check') roadCheck(r);
      else if (k === 'explore') exploreSheet(X.S.to);
      else if (k === 'spot') { var p = { name: el.dataset.n || '', lat: +el.dataset.lat, lng: +el.dataset.lng }; X.S.map.flyTo({ center: [p.lng, p.lat], zoom: 16 }); }
    };
  });
  if (WX.r !== r) rainOnRoute(r);
  if (S.mode === 'bus' && r.src === 'own' && r.dist < 400000 && RC.r !== r) roadCheck(r);   /* a coach always gets the check */
}
function rcHtml(rc) {
  if (!rc.narrow.km && !rc.rough.km) return '<div class="nm-row"><span>✅</span><span>' + esc(t('roadOk')) + '</span></div>';
  var bits = [];
  if (rc.narrow.km) bits.push(t('narrow', { k: rc.narrow.km }));
  if (rc.rough.km) bits.push(t('rough', { k: rc.rough.km }));
  var at = rc.narrow.at && rc.narrow.at[0];
  return '<button class="nm-row warn" type="button" data-nm="spot"' + (at ? ' data-lat="' + at.lat + '" data-lng="' + at.lng + '" data-n="' + esc(at.name) + '"' : '') + '><span>⚠️</span><span><b>' +
    esc(bits.join(' · ')) + '</b>' + (X.S.mode === 'bus' ? '<small>' + esc(t('coach')) + (at && at.name ? ' · ' + esc(at.name) : '') + '</small>' : (at && at.name ? '<small>' + esc(at.name) + '</small>' : '')) + '</span></button>';
}
function redraw() { var c = X.S.cur; if (X.S.level === 'dir' && c && c.routes && c.routes[X.S.sel]) dirSheet(c.routes[X.S.sel]); }
function roadCheck(r) {
  RC = { r: r, v: 'busy' }; redraw();
  var costing = X.S.mode === 'bus' ? 'bus' : X.S.mode === 'moto' ? 'motorcycle' : 'auto';
  getJ('/api/nav-attrs.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ shape: enc6(r.coords), costing: costing }) }, 25000)
    .then(function (d) { if (RC.r === r) { RC.v = d; redraw(); } })
    .catch(function () { if (RC.r === r) { RC.v = null; redraw(); X.toast(t('failed')); } });
}
var AREA = {};
function areaName(p) {
  var k = p[1].toFixed(2) + ',' + p[0].toFixed(2);
  if (AREA[k] !== undefined) return Promise.resolve(AREA[k]);
  return getJ('/api/nav-search.php?city=' + p[1].toFixed(4) + ',' + p[0].toFixed(4), null, 6000)
    .then(function (j) { AREA[k] = (j && j.name) || ''; return AREA[k]; }).catch(function () { return ''; });
}
/* the hour you will be at each point: MET Norway (free incl. commercial use) through our cached proxy */
function rainOnRoute(r) {
  WX = { r: r, v: null };
  if (!r.L && X.prepRoute) X.prepRoute(r);
  var Lr = r.L; if (!Lr || r.dist < 15000) return;
  var start = WHEN.type === 'depart' && WHEN.t ? WHEN.t : WHEN.type === 'arrive' && WHEN.t ? WHEN.t - r.dur * 1000 : Date.now();
  var n = Math.min(6, Math.max(2, Math.round(r.dist / 40000))), pts = [];
  for (var i = 1; i <= n; i++) {
    var a = Lr.len * i / n, p = X.at(Lr, a).p, eta = start + r.dur * 1000 * (a / Lr.len);
    if (eta - Date.now() < 11.5 * 3600e3) pts.push({ p: p, t: eta });
  }
  var out = { n: 0, wet: null };
  pts.reduce(function (chain, pt) {
    return chain.then(function () {
      if (out.wet || WX.r !== r) return null;
      var key = pt.p[1].toFixed(2) + ',' + pt.p[0].toFixed(2);
      var got = WX[key] ? Promise.resolve(WX[key]) : getJ('/api/nav-weather.php?lat=' + pt.p[1].toFixed(4) + '&lng=' + pt.p[0].toFixed(4), null, 9000).then(function (j) { WX[key] = j; return j; });
      return got.then(function (j) {
        out.n++;
        var hrs = (j && j.hours) || [], best = null;
        hrs.forEach(function (h) { if (!best || Math.abs(h.at * 1000 - pt.t) < Math.abs(best.at * 1000 - pt.t)) best = h; });
        if (best && Math.abs(best.at * 1000 - pt.t) < 5400e3 && (best.mm >= 0.3 || /rain|thunder|sleet|snow/.test(best.sym))) {
          return areaName(pt.p).then(function (nm) { out.wet = { p: nm, t: pt.t, mm: best.mm }; });
        }
      }).catch(function () {});
    });
  }, Promise.resolve()).then(function () { if (WX.r === r) { WX.v = out; redraw(); } });
}
function whenSheet() {
  var pad = function (n) { return ('0' + n).slice(-2); };
  var d = new Date(WHEN.t || Date.now() + 3600e3);
  var val = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  var el = sheet('<h3>🕐 ' + esc(t('when')) + '</h3><div class="nm-seg">' +
    [['now', t('now')], ['depart', t('dep')], ['arrive', t('arr')]].map(function (x) { return '<button type="button" data-w="' + x[0] + '" class="' + (WHEN.type === x[0] ? 'on' : '') + '">' + esc(x[1]) + '</button>'; }).join('') +
    '</div><input class="nm-time" id="nmTime" type="datetime-local" value="' + val + '"' + (WHEN.type === 'now' ? ' hidden' : '') + '><button class="nm-b" type="button" data-ok>' + esc(t('ok')) + '</button>');
  var type = WHEN.type;
  el.querySelectorAll('[data-w]').forEach(function (b) {
    b.onclick = function () { type = b.dataset.w; el.querySelectorAll('[data-w]').forEach(function (x) { x.classList.toggle('on', x === b); }); $('#nmTime').hidden = type === 'now'; };
  });
  el.querySelector('[data-ok]').onclick = function () {
    var v = $('#nmTime').value, ms = v ? new Date(v).getTime() : 0;
    WHEN = type === 'now' || !isFinite(ms) || !ms ? { type: 'now', t: 0 } : { type: type, t: ms };
    WX = {};                                            /* rain follows the new times */
    sheetClose(false, redraw);
  };
}

/* ---------------------------------------------------------------------
   ASK MAPS CHIPS (under the search box) + EV / CNG / LPG
--------------------------------------------------------------------- */
function askChips() {
  var s = $('#search'), body = $('#sBody'); if (!s || !body || $('#nmAsk')) return;
  var row = document.createElement('div'); row.className = 'nm-ask'; row.id = 'nmAsk';
  row.innerHTML = ASK.map(function (a) { return '<button class="nm-chip" type="button" data-ask="' + a.id + '">' + a.e + ' ' + esc(L(a.l)) + '</button>'; }).join('');
  s.insertBefore(row, body);
  row.onclick = function (e) {
    var b = e.target.closest('[data-ask]'); if (!b) return;
    var a = ASK.filter(function (x) { return x.id === b.dataset.ask; })[0]; if (!a) return;
    if (a.id === 'ai') { if (X.aiOpen) X.aiOpen(); return; }
    askRun(a);
  };
}
function askLabels() { var row = $('#nmAsk'); if (row) { row.remove(); askChips(); } }
function askRun(a) {
  var cr = curRoute();
  var el = sheet('<h3>' + a.e + ' ' + esc(L(a.l)) + '</h3><div class="nm-list"><div class="nm-li" style="opacity:.6">…</div></div>');
  var done = function (items) {
    if (!SHT.open || !el.isConnected) return;
    var list = el.querySelector('.nm-list');
    if (!items.length) { list.innerHTML = '<div class="nm-li">' + esc(t('none')) + '</div>'; return; }
    list.innerHTML = items.slice(0, 15).map(function (x, i) {
      var sub = x.ahead != null ? t('ahead', { k: X.fmtDist(x.ahead) }) + (x.off > 150 ? ' · ' + t('off', { m: X.fmtDist(x.off) }) : '') : X.fmtDist(x.d || 0);
      return '<button class="nm-li" type="button" data-i="' + i + '"><span class="e">' + (x.e || a.e) + '</span><span><b>' + esc(x.name || L(a.l)) + '</b><small>' + esc(sub + (x.x ? ' · ' + x.x : '')) + '</small></span></button>';
    }).join('');
    list.querySelectorAll('[data-i]').forEach(function (b) {
      b.onclick = function () { var p = items[+b.dataset.i]; sheetClose(false, function () { X.openPlace({ name: p.name || L(a.l), sub: p.sub || '', lat: p.lat, lng: p.lng }); }); };
    });
  };
  var job = cr ? alongRoute(a, cr) : nearby(a);
  job.then(done).catch(function () { done([]); });
}
function nearby(a) {
  var h = here(); if (!h) return Promise.resolve([]);
  if (a.f) {
    var d = 0.25;
    return getJ('/api/nav-features.php?kinds=' + a.f + '&bbox=' + [h[0] - d, h[1] - d, h[0] + d, h[1] + d].map(function (v) { return v.toFixed(4); }).join(','))
      .then(function (j) { return oneSite((j.features || []).map(function (f) { return { name: f.n || L(a.l), lat: f.lat, lng: f.lng, x: f.x || '', d: X.hav(h, [f.lng, f.lat]) }; })).sort(function (x, y) { return x.d - y.d; }); });
  }
  return getJ('/api/nav-search.php?limit=12&q=' + encodeURIComponent(a.q) + '&lat=' + h[1].toFixed(4) + '&lng=' + h[0].toFixed(4), null, 9000)
    .then(function (j) { return vegSort(a, ((j && j.results) || []).map(function (x) { return { name: x.shortName, sub: x.displayName || '', lat: +x.lat, lng: +x.lng, d: X.hav(h, [+x.lng, +x.lat]) }; }).sort(function (x, y) { return x.d - y.d; })); });
}
/* OSM often maps each charger of one site as its own point: one row per site (~100 m) */
function oneSite(items) {
  var seen = {};
  return items.filter(function (x) { var k = x.name + '|' + x.lat.toFixed(3) + '|' + x.lng.toFixed(3); if (seen[k]) return false; seen[k] = 1; return true; });
}
function vegSort(a, items) {
  if (!a.veg) return items;
  return items.filter(function (x) { return VEG.test(x.name + ' ' + (x.sub || '')); }).map(function (x) { x.e = '🥗'; return x; })
    .concat(items.filter(function (x) { return !VEG.test(x.name + ' ' + (x.sub || '')); }));
}
/* along the route ahead: probe the place index at a few points, keep what is
   within 1.5 km of the line, sort by how far ahead it is */
function alongRoute(a, cr) {
  var Lr = cr.r.L, a0 = cr.a, rem = Lr.len - a0, max = Math.min(rem, a.max || 80000);
  var ahead = function (x) {
    var q = X.nearest(Lr, [x.lng, x.lat], Math.max(0, cr.i), Lr.n - 2);
    return q.d < (a.f ? 2000 : 1500) && q.along > a0 + 50 && q.along - a0 <= max ? { ahead: q.along - a0, off: q.d } : null;
  };
  if (a.f) {
    var lo = 999, la = 999, LO = -999, LA = -999;
    for (var i = Math.max(0, cr.i); i < Lr.n && Lr.cum[i] - a0 <= max; i++) { var c = Lr.c[i]; lo = Math.min(lo, c[0]); la = Math.min(la, c[1]); LO = Math.max(LO, c[0]); LA = Math.max(LA, c[1]); }
    LO = Math.min(LO, lo + 2.4); LA = Math.min(LA, la + 2.4);
    return getJ('/api/nav-features.php?kinds=' + a.f + '&bbox=' + [lo - 0.02, la - 0.02, LO + 0.02, LA + 0.02].map(function (v) { return v.toFixed(4); }).join(','))
      .then(function (j) {
        return oneSite((j.features || []).map(function (f) { var q = ahead(f); return q ? { name: f.n || L(a.l), lat: f.lat, lng: f.lng, x: f.x || '', ahead: q.ahead, off: q.off } : null; })
          .filter(Boolean)).sort(function (x, y) { return x.ahead - y.ahead; });
      });
  }
  var marks = [1500, 8000, 20000, 40000, 65000].filter(function (m) { return m < max + 1500; }).map(function (m) { return Math.min(a0 + m, Lr.len); });
  if (!marks.length) marks = [Math.min(a0 + 500, Lr.len)];
  return Promise.all(marks.map(function (m) {
    var p = X.at(Lr, m).p;
    return getJ('/api/nav-search.php?limit=10&q=' + encodeURIComponent(a.q) + '&lat=' + p[1].toFixed(4) + '&lng=' + p[0].toFixed(4), null, 9000)
      .then(function (j) { return (j && j.results) || []; }).catch(function () { return []; });
  })).then(function (lists) {
    var seen = {}, out = [];
    lists.forEach(function (lst) {
      lst.forEach(function (x) {
        var key = x.shortName + '|' + (+x.lat).toFixed(3) + '|' + (+x.lng).toFixed(3);
        if (seen[key]) return; seen[key] = 1;
        var q = ahead({ lat: +x.lat, lng: +x.lng });
        if (q) out.push({ name: x.shortName, sub: x.displayName || '', lat: +x.lat, lng: +x.lng, ahead: q.ahead, off: q.off });
      });
    });
    return vegSort(a, out.sort(function (x, y) { return x.ahead - y.ahead; }));
  });
}

/* ---------------------------------------------------------------------
   SPEED CAMERAS + SCHOOL ZONES (spoken ~400 m before)
--------------------------------------------------------------------- */
var AL = { N: null, list: [], said: {}, upto: 0, busy: false, lastSchool: -1e9 };
function alStart(N) { AL = { N: N, list: [], said: {}, upto: 0, busy: false, lastSchool: -1e9 }; alLoad(N); }
function alLoad(N) {
  var Lr = N.route && N.route.L; if (!Lr || AL.busy || AL.upto >= Lr.len) return;
  var a0 = Math.max(N.along || 0, AL.upto), a1 = Math.min(Lr.len, a0 + 40000);
  var i0 = X.at(Lr, a0).i, lo = 999, la = 999, LO = -999, LA = -999;
  for (var i = i0; i < Lr.n && Lr.cum[i] <= a1; i++) { var c = Lr.c[i]; lo = Math.min(lo, c[0]); la = Math.min(la, c[1]); LO = Math.max(LO, c[0]); LA = Math.max(LA, c[1]); }
  if (lo > LO) return;
  LO = Math.min(LO, lo + 2.4); LA = Math.min(LA, la + 2.4);
  AL.busy = true;
  getJ('/api/nav-features.php?kinds=speed_camera,school&bbox=' + [lo - 0.01, la - 0.01, LO + 0.01, LA + 0.01].map(function (v) { return v.toFixed(4); }).join(','))
    .then(function (j) {
      if (AL.N !== N) return;
      (j.features || []).forEach(function (f) {
        var q = X.nearest(Lr, [f.lng, f.lat], Math.max(0, i0 - 1), Lr.n - 2), lim = f.k === 'school' ? 120 : 60;
        if (q.d < lim && q.along >= a0 - 200 && q.along <= a1 + 200) AL.list.push({ k: f.k, ms: f.ms || 0, along: q.along, id: f.k + f.lat + f.lng });
      });
      AL.list.sort(function (x, y) { return x.along - y.along; });
      AL.upto = a1;
    }).catch(function () { AL.upto = a1; }).then(function () { AL.busy = false; });
}
function alTick(N) {
  if (!N || N !== AL.N || N.preview || N.arrived) return;
  var a = N.along || 0, Lr = N.route.L;
  if (Lr && AL.upto < Lr.len && AL.upto - a < 15000) alLoad(N);
  var hr = new Date().getHours(), kmh = (N.v || 0) * 3.6;
  for (var i = 0; i < AL.list.length; i++) {
    var f = AL.list[i], ah = f.along - a;
    if (ah < 0) continue;
    if (ah > 600) break;
    if (AL.said[f.id]) continue;
    if (f.k === 'speed_camera' && ah <= 450 && ah >= 80) { AL.said[f.id] = 1; say('📷', t('cam', { s: f.ms ? t('camLim', { v: f.ms }) : '' })); }
    else if (f.k === 'school' && ah <= 300 && ah >= 60) {
      AL.said[f.id] = 1;
      /* school hours, and only when moving fast enough for a warning to matter: town traffic is slow anyway */
      if (hr >= 7 && hr < 18 && kmh > 30 && a - AL.lastSchool > 1500) { AL.lastSchool = a; say('🏫', t('school')); }
    }
  }
}
function say(e, s) {
  X.toast(e + ' ' + s, 5000); X.vib([50, 60, 50]);
  if (X.S.sound !== 'off') { try { X.sayText(s, lang()); } catch (x) {} }
}

/* ---------------------------------------------------------------------
   MINI NAVIGATION (picture-in-picture): Document PiP where the browser has
   it (Chrome desktop 116+), else a canvas drawn into a video (Android Chrome)
--------------------------------------------------------------------- */
var PIP = { win: null, video: null, timer: 0 };
function pipData() {
  var g = function (id) { var el = document.getElementById(id); return el ? el.textContent.trim() : ''; };
  var N = X.S.nav, st = N && N.route && N.route.steps[N.k + 1], mod = st ? (st.mod || '') : '';
  var arrow = !st ? '⬆' : st.type === 'arrive' ? '🏁' : /uturn/.test(mod) ? '⤺' : /sharp left|^left/.test(mod) ? '⬅' : /slight left/.test(mod) ? '↖' : /sharp right|^right/.test(mod) ? '➡' : /slight right/.test(mod) ? '↗' : '⬆';
  return { arrow: arrow, dist: g('banDist'), txt: g('banTxt'), eta: g('nbTime') };
}
function pipStart() {
  if (!X.S.nav) return;
  if (window.documentPictureInPicture && documentPictureInPicture.requestWindow) {
    documentPictureInPicture.requestWindow({ width: 340, height: 170 }).then(function (w) {
      PIP.win = w;
      w.document.body.style.cssText = 'margin:0;background:#0B4D2C;color:#fff;font:600 16px Inter,system-ui,sans-serif;display:flex;align-items:center;gap:14px;padding:14px;height:100vh;box-sizing:border-box';
      w.document.body.innerHTML = '<div id="a" style="font-size:56px;line-height:1"></div><div><div id="d" style="font-size:30px"></div><div id="t" style="font-size:15px;opacity:.92"></div><div id="e" style="font-size:13px;opacity:.75;margin-top:4px"></div></div>';
      var up = function () { var d = pipData(); w.document.getElementById('a').textContent = d.arrow; w.document.getElementById('d').textContent = d.dist; w.document.getElementById('t').textContent = d.txt; w.document.getElementById('e').textContent = d.eta; };
      up(); clearInterval(PIP.timer); PIP.timer = setInterval(function () { if (PIP.win && !PIP.win.closed) up(); else pipStop(); }, 1000);
      w.addEventListener('pagehide', pipStop);
      X.toast(t('pipHint'), 3500);
    }).catch(function () { pipVideo(); });
    return;
  }
  pipVideo();
}
function pipVideo() {
  if (!document.pictureInPictureEnabled) { X.toast(t('pipNo')); return; }
  var cv = document.createElement('canvas'); cv.width = 480; cv.height = 240;
  var cx = cv.getContext('2d');
  var draw = function () {
    var d = pipData();
    cx.fillStyle = '#0B4D2C'; cx.fillRect(0, 0, 480, 240);
    cx.fillStyle = '#fff'; cx.font = '96px sans-serif'; cx.textBaseline = 'middle'; cx.fillText(d.arrow, 22, 110);
    cx.font = '600 52px sans-serif'; cx.fillText(d.dist, 150, 78);
    cx.font = '26px sans-serif'; var words = d.txt.split(' '), line = '', y = 140;
    words.forEach(function (wd) { if (cx.measureText(line + wd).width > 310) { cx.fillText(line, 150, y); line = ''; y += 32; } line += wd + ' '; });
    if (y < 210) cx.fillText(line, 150, y);
    cx.font = '22px sans-serif'; cx.globalAlpha = 0.8; cx.fillText(d.eta, 22, 212); cx.globalAlpha = 1;
  };
  draw();
  var v = PIP.video || document.createElement('video');
  v.muted = true; v.playsInline = true; v.srcObject = cv.captureStream(2);
  PIP.video = v;
  v.play().then(function () { return v.requestPictureInPicture(); }).then(function () {
    clearInterval(PIP.timer); PIP.timer = setInterval(function () { if (document.pictureInPictureElement === v) draw(); else pipStop(); }, 1000);
    X.toast(t('pipHint'), 3500);
  }).catch(function () { X.toast(t('pipNo')); });
}
function pipStop() {
  clearInterval(PIP.timer); PIP.timer = 0;
  try { if (PIP.win && !PIP.win.closed) PIP.win.close(); } catch (e) {}
  PIP.win = null;
  try { if (document.pictureInPictureElement) document.exitPictureInPicture(); } catch (e) {}
}

/* ---------------------------------------------------------------------
   EXPLORE: our own travel guide + sights near a place
--------------------------------------------------------------------- */
var EXC = {};
function exploreData(p) {
  var k = (+p.lat).toFixed(3) + ',' + (+p.lng).toFixed(3);
  if (EXC[k]) return Promise.resolve(EXC[k]);
  return getJ('/api/nav-explore.php?lat=' + (+p.lat).toFixed(4) + '&lng=' + (+p.lng).toFixed(4)).then(function (d) { EXC[k] = d; return d; });
}
function exploreHtml(d) {
  var h = '';
  if (d.ours && d.ours.length) {
    h += '<div class="nm-h">' + esc(t('ourGuide')) + '</div><div class="nm-list">' + d.ours.map(function (o, i) {
      return '<button class="nm-card" type="button" data-xo="' + i + '">' + (o.img ? '<img src="' + esc(o.img) + '" alt="" loading="lazy">' : '') +
        '<span><b>' + esc(L(o.name)) + (o.unesco ? ' · UNESCO' : '') + '</b><small style="color:var(--text3,#6F7D99)">' + esc(X.fmtDist(o.km * 1000)) + (L(o.season) ? ' · ' + esc(t('season')) + ': ' + esc(L(o.season)) : '') + '</small>' +
        '<p>' + esc(L(o.blurb)) + '</p></span></button>';
    }).join('') + '</div>';
  }
  if (d.spots && d.spots.length) {
    h += '<div class="nm-h">' + esc(t('explore')) + '</div><div class="nm-list">' + d.spots.map(function (s, i) {
      return '<button class="nm-li" type="button" data-xs="' + i + '"><span class="e">' + (KIND_E[s.kind] || '📍') + '</span><span><b>' + esc(lang() === 'en' && s.en ? s.en : s.name) + '</b><small>' + esc(X.fmtDist(s.km * 1000)) + '</small></span></button>';
    }).join('') + '</div>';
  }
  return h;
}
function exploreBind(root, d, inSheet) {
  var open = function (p) {
    if (inSheet) sheetClose(false, function () { X.openPlace(p); });
    else X.openPlace(p);
  };
  root.querySelectorAll('[data-xo]').forEach(function (b) { b.onclick = function () { var o = d.ours[+b.dataset.xo]; open({ name: L(o.name), sub: L(o.blurb).slice(0, 80), lat: o.lat, lng: o.lng }); }; });
  root.querySelectorAll('[data-xs]').forEach(function (b) { b.onclick = function () { var s = d.spots[+b.dataset.xs]; open({ name: lang() === 'en' && s.en ? s.en : s.name, sub: '', lat: s.lat, lng: s.lng }); }; });
}
function exploreSheet(p) {
  if (!p) return;
  var el = sheet('<h3>🧭 ' + esc(t('exploreAt', { p: p.name || '' })) + '</h3><div id="nmXs"><div class="nm-li" style="opacity:.6">…</div></div>');
  exploreData(p).then(function (d) {
    var box = el.querySelector('#nmXs'); if (!box) return;
    var h = exploreHtml(d);
    box.innerHTML = h || '<div class="nm-li">' + esc(t('none')) + '</div>';
    exploreBind(box, d, true);
  }).catch(function () { var box = el.querySelector('#nmXs'); if (box) box.innerHTML = '<div class="nm-li">' + esc(t('failed')) + '</div>'; });
}
function placeCard(p) {
  if (!p || p.me) return;
  var px = $('#placeX'); if (!px) return;
  var box = $('#nmExplore');
  if (!box) { box = document.createElement('div'); box.id = 'nmExplore'; px.parentNode.insertBefore(box, px.nextSibling); }
  box.innerHTML = '';
  var key = p.lat + ',' + p.lng; box.dataset.k = key;
  exploreData(p).then(function (d) {
    if (box.dataset.k !== key || !box.isConnected) return;
    var h = exploreHtml({ ours: (d.ours || []).slice(0, 3), spots: (d.spots || []).slice(0, 5) });
    box.innerHTML = h;
    exploreBind(box, d, false);
  }).catch(function () {});
}

/* =====================================================================
   PHASE 4 (22 Oct 2026): Apple Maps iOS 26 ideas, rebuilt
===================================================================== */

/* ---------- Preferred routes: the trips this phone repeats ---------- */
var LSx = {
  get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
};
var TRIP = { N: null, a: null, t0: 0 };
function tripStart(N) {
  var S = X.S;
  if (!N || N.preview || (N.sim && !QS.has('debug')) || !S.to || S.to.me) { TRIP.N = null; return; }
  var a = S.me ? [S.me.lng, S.me.lat] : S.from && !S.from.me && S.from.lat != null ? [+S.from.lng, +S.from.lat] : N.route.coords[0];
  TRIP = { N: N, a: a, t0: Date.now() };
}
function tripArrive(N) {
  var S = X.S; if (!TRIP.N || TRIP.N !== N || !S.to) return;
  var s = Math.round((Date.now() - TRIP.t0) / 1000);
  if (s < 120) return;
  var list = LSx.get('shg:nx:trips', []);
  list.unshift({ t: TRIP.t0, a: [+TRIP.a[0].toFixed(5), +TRIP.a[1].toFixed(5)], b: [+(+S.to.lng).toFixed(5), +(+S.to.lat).toFixed(5)],
                 bn: S.to.name || '', m: S.mode, s: s, via: N.route.via || '' });
  LSx.set('shg:nx:trips', list.slice(0, 80));
  TRIP.N = null;
}
/* trips to the same place (300 m) from the same start (800 m), at least three: a habit */
function usualFor(a, b, mode) {
  var list = LSx.get('shg:nx:trips', []).filter(function (x) {
    return x.m === mode && X.hav(x.b, b) < 300 && (!a || X.hav(x.a, a) < 800);
  });
  if (list.length < 3) return null;
  var secs = list.map(function (x) { return x.s; }).sort(function (p, q) { return p - q; });
  var vias = {}; list.forEach(function (x) { if (x.via) vias[x.via] = (vias[x.via] || 0) + 1; });
  var via = Object.keys(vias).sort(function (p, q) { return vias[q] - vias[p]; })[0] || '';
  return { n: list.length, s: secs[Math.floor(secs.length / 2)], via: via, bn: list[0].bn, b: list[0].b, a: list[0].a };
}
function minsText(s) { var m = Math.round(s / 60); return m < 60 ? m + ' ' + (lang() === 'en' ? 'min' : lang() === 'hi' ? 'मिनट' : 'मिनेट') : Math.floor(m / 60) + 'h ' + (m % 60) + 'm'; }
function usualRow(r) {
  var S = X.S; if (!S.to || S.to.me) return '';
  var a = S.from && !S.from.me && S.from.lat != null ? [+S.from.lng, +S.from.lat] : S.me ? [S.me.lng, S.me.lat] : null;
  var u = usualFor(a, [+S.to.lng, +S.to.lat], S.mode); if (!u) return '';
  var d = Math.round((r.dur - u.s) / 60);
  var txt = t('usual', { v: u.via ? t('usualVia', { r: u.via }) : '', u: minsText(u.s), n: minsText(r.dur) });
  var sub = Math.abs(d) >= 2 ? (d > 0 ? t('slower', { m: d }) : t('faster', { m: -d })) : '';
  if (u.via && r.via && u.via !== r.via) sub = (sub ? sub + ' · ' : '') + t('otherWay', { r: r.via });
  return '<div class="nm-row' + (d >= 5 ? ' warn' : '') + '"><span>🔁</span><span><b>' + esc(txt) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span></div>';
}
/* home: the habit that starts here, one tap away (like Apple's "usual" suggestion) */
function usualChip() {
  var old = $('#nmUsual'); if (old) old.remove();
  var S = X.S, hw = $('#hwChips'); if (!hw || !S.me) return;
  var me = [S.me.lng, S.me.lat], best = null, seen = {};
  LSx.get('shg:nx:trips', []).forEach(function (x) {
    var k = x.m + '|' + x.b[0].toFixed(3) + ',' + x.b[1].toFixed(3); if (seen[k]) return; seen[k] = 1;
    if (X.hav(x.a, me) > 800 || X.hav(x.b, me) < 500) return;
    var u = usualFor(me, x.b, x.m); if (u && (!best || u.n > best.n)) best = Object.assign({ m: x.m }, u);
  });
  if (!best) return;
  var c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.id = 'nmUsual';
  c.textContent = '🔁 ' + (best.bn || '') + ' · ~' + minsText(best.s);
  c.onclick = function (e) { e.stopPropagation(); X.go({ name: best.bn, lat: best.b[1], lng: best.b[0] }, { mode: best.m }); };
  hw.parentNode.insertBefore(c, hw);
}

/* ---------- Visits screen (Apple "Visited places"), with delete-all ---------- */
function openVisits() {
  var v = LSx.get('shg:ng:visits', []), arm = false;
  var day = function (ms) { var d = new Date(ms), n = new Date(); if (d.toDateString() === n.toDateString()) return t('today'); n.setDate(n.getDate() - 1); return d.toDateString() === n.toDateString() ? t('yday') : d.toLocaleDateString(); };
  var render = function () {
    v = LSx.get('shg:ng:visits', []);
    var h = '<h3>📍 ' + esc(t('visitsT')) + ' (' + v.length + ')</h3><p style="margin:0 0 8px;color:var(--text3,#6F7D99);font-size:12.5px">' + esc(t('visitsNote')) + '</p>';
    if (!v.length) h += '<div class="nm-li">' + esc(t('noVisits')) + '</div>';
    var last = '';
    v.forEach(function (x, i) {
      var g = day(x.t); if (g !== last) { h += '<div class="nm-h">' + esc(g) + '</div>'; last = g; }
      h += '<div style="display:flex;gap:6px;margin-bottom:6px"><button class="nm-li" type="button" data-vo="' + i + '" style="flex:1"><span class="e">📍</span><span><b>' + esc(x.name || '') + '</b><small>' + esc(clock(x.t)) + '</small></span></button>' +
        '<button class="nm-x" type="button" data-vd="' + i + '" aria-label="Delete" style="position:static;flex:none">×</button></div>';
    });
    if (v.length) h += '<button class="nm-b" type="button" data-vall style="background:#B42318">' + esc(arm ? t('delSure') : t('delAll')) + '</button>';
    var el = sheet(h);
    el.querySelectorAll('[data-vo]').forEach(function (b) { b.onclick = function () { var x = v[+b.dataset.vo]; sheetClose(false, function () { X.openPlace({ name: x.name, lat: x.lat, lng: x.lng }); }); }; });
    el.querySelectorAll('[data-vd]').forEach(function (b) { b.onclick = function () { v.splice(+b.dataset.vd, 1); LSx.set('shg:ng:visits', v); render(); }; });
    var all = el.querySelector('[data-vall]');
    if (all) all.onclick = function () { if (!arm) { arm = true; render(); return; } LSx.set('shg:ng:visits', []); arm = false; render(); };
  };
  render();
}

/* ---------- Natural-language search: a question goes to the AI guide ---------- */
var QWORD = /\?|？|कसरी|कहाँ|किन|कति|कुन|के छ|सबैभन्दा|राम्रो|how |where |what |which |why |best |kasari|kaha |kati |कैसे|कहां|क्या|कौन|सबसे|अच्छा/i;
function nlSearch() {
  var inp = $('#sInput'), s = $('#search'), body = $('#sBody'); if (!inp || !s || !body) return;
  var row = document.createElement('button'); row.type = 'button'; row.className = 'nm-row'; row.id = 'nmQ'; row.hidden = true;
  row.style.cssText = 'margin:4px 12px 0;width:auto';
  s.insertBefore(row, body);
  var upd = function () {
    var q = inp.value.trim(), show = !!(X.aiAsk && q.length >= 6 && QWORD.test(q + ' '));
    row.hidden = !show;
    if (show) row.innerHTML = '<span>🙏</span><span><b>' + esc(t('askQ', { q: q.slice(0, 60) })) + '</b></span>';
  };
  inp.addEventListener('input', upd);
  row.onclick = function () { var q = inp.value.trim(); if (q && X.aiAsk) X.aiAsk(q); };
}

/* ---------- Junction view: the lanes, big, ~300 m before a complex exit ---------- */
var JV = { el: null, k: -1 };
var LANE_ROT = { 'sharp left': -135, 'left': -90, 'slight left': -45, 'straight': 0, 'none': 0, 'slight right': 45, 'right': 90, 'sharp right': 135, 'uturn': 180 };
function laneSvg(lane, on) {
  var ind = (lane.indications || ['straight'])[0] || 'straight', rot = LANE_ROT[ind] != null ? LANE_ROT[ind] : 0;
  return '<span class="nm-lane' + (on ? ' on' : '') + '"><svg viewBox="0 0 24 24" style="transform:rotate(' + rot + 'deg)" aria-hidden="true"><path d="M12 21V5M5.5 11.5L12 5l6.5 6.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
}
function jvTick(N) {
  var show = false, st = null, d = 0;
  if (N && !N.preview && !N.arrived && !N.offRoute && N.route && N.route.steps) {
    st = N.route.steps[N.k + 1];
    if (st && st.type !== 'arrive') {
      d = (st.along || 0) - (N.along || 0);
      var complex = (st.lanes && st.lanes.length >= 3) || /fork|ramp|exit/.test(st.type || '');
      show = complex && d > 25 && d <= 300;
    }
  }
  if (!show) { if (JV.el && !JV.el.hidden) { JV.el.hidden = true; document.body.classList.remove('nm-jv-on'); } return; }
  if (!JV.el) { JV.el = document.createElement('div'); JV.el.className = 'nm-jv'; JV.el.setAttribute('role', 'img'); document.body.appendChild(JV.el); }
  var top = $('#navTop'); if (top) JV.el.style.top = Math.round(top.getBoundingClientRect().bottom + 8) + 'px';
  var lanes = st.lanes || [];
  var sign = st.ref || st.dest || st.name || '';
  JV.el.innerHTML = (lanes.length ? '<div class="nm-lanes">' + lanes.map(function (l) { return laneSvg(l, l.valid !== false); }).join('') + '</div>' : '') +
    '<div class="nm-sign"><b>' + esc(X.fmtDist(Math.max(0, d))) + '</b>' + (sign ? '<span>' + esc(sign) + '</span>' : '') + '</div>';
  JV.el.setAttribute('aria-label', (sign || '') + ' ' + X.fmtDist(d));
  JV.el.hidden = false; document.body.classList.add('nm-jv-on');
}

/* ---------- Steep climbs for walking / cycling (our SRTM heights) ---------- */
var STEEP = { r: null, v: null, said: {} };
function steepCheck(r) {
  STEEP = { r: r, v: 'busy', said: {} };
  if (!r.L && X.prepRoute) X.prepRoute(r);
  var Lr = r.L; if (!Lr || Lr.len < 300) { STEEP.v = null; return; }
  var n = Math.max(20, Math.min(200, Math.round(Lr.len / 50))), pts = [], al = [];
  for (var i = 0; i < n; i++) { var a = Lr.len * i / (n - 1), p = X.at(Lr, a).p; pts.push([+p[1].toFixed(5), +p[0].toFixed(5)]); al.push(a); }
  getJ('/api/nav-elevation.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ shape: pts }) }, 12000)
    .then(function (j) {
      var h = (j && (j.h || j)) || [];
      if (STEEP.r !== r || !h.length) return;
      var segs = [], win = Math.max(1, Math.round(100 / (Lr.len / (n - 1))));     /* grade over ~100 m */
      for (var k = 0; k + win < h.length; k++) {
        if (h[k] == null || h[k + win] == null) continue;
        var g = (h[k + win] - h[k]) / Math.max(1, al[k + win] - al[k]) * 100;
        if (g >= 8) segs.push({ a0: al[k], a1: al[k + win], g: g });
      }
      var merged = [];
      segs.forEach(function (s) { var m = merged[merged.length - 1]; if (m && s.a0 <= m.a1 + 20) { m.a1 = s.a1; m.g = Math.max(m.g, s.g); } else merged.push({ a0: s.a0, a1: s.a1, g: s.g }); });
      STEEP.v = merged; redraw();
    }).catch(function () { if (STEEP.r === r) STEEP.v = null; });
}
function steepRow(r) {
  var m = X.S.mode; if (m !== 'walk' && m !== 'bike') return '';
  if (STEEP.r !== r) { steepCheck(r); return ''; }
  var v = STEEP.v; if (!v || v === 'busy') return '';
  if (!v.length) return '<div class="nm-row"><span>⛰️</span><span>' + esc(t('flat')) + '</span></div>';
  var top = v.slice().sort(function (p, q) { return q.g - p.g; })[0];
  return '<div class="nm-row warn"><span>⛰️</span><span><b>' + esc(t('steep', { g: Math.round(top.g), m: X.fmtDist(top.a1 - top.a0), k: X.fmtDist(top.a0) })) + '</b></span></div>';
}
function steepTick(N) {
  if (!N || N.preview || STEEP.r !== N.route || !STEEP.v || STEEP.v === 'busy') return;
  var a = N.along || 0;
  STEEP.v.forEach(function (s, i) {
    var ah = s.a0 - a;
    if (!STEEP.said[i] && s.g >= 10 && ah > 20 && ah <= 150) { STEEP.said[i] = 1; say('⛰️', t('steepSay') + ' (~' + Math.round(s.g) + '%)'); }
  });
}

/* ---------- Hiking trails toggle (walk mode) ---------- */
function hikeChip() {
  var opts = $('#dirOpts'); if (!opts || $('#nmHike')) return;
  var b = document.createElement('button'); b.className = 'mini'; b.type = 'button'; b.id = 'nmHike';
  b.onclick = function () {
    X.S.hike = !X.S.hike; LSx.set('shg:nm:hike', X.S.hike);
    b.classList.toggle('on', X.S.hike);
    if (X.recompute) X.recompute();
  };
  opts.appendChild(b);
}
function hikePaint() {
  var b = $('#nmHike'); if (!b) return;
  b.textContent = t('hike'); b.hidden = X.S.mode !== 'walk'; b.classList.toggle('on', !!X.S.hike);
}

/* ---------- Liquid glass: one token set for every floating surface ---------- */
var GLASS = [
  ':root{--g-bg:rgba(17,29,51,.85);--g-blur:blur(20px) saturate(160%);--g-line:rgba(255,255,255,.10);--g-hi:inset 0 1px 0 rgba(255,255,255,.07)}',
  '.glass,.modal-card,.sg-panel,.nl-sheet,.nm-sheet,.nl-fcard,.ai-card,.fab:not(.sg-fab):not(.nl-livepill),.chip:not(.on):not(.sg-chip){',
  '  background-color:var(--g-bg);-webkit-backdrop-filter:var(--g-blur);backdrop-filter:var(--g-blur);border-color:var(--g-line);box-shadow:var(--g-hi),0 10px 30px rgba(0,0,0,.32)}',
  'body.saver .glass,body.saver .modal-card,body.saver .sg-panel,body.saver .nl-sheet,body.saver .nm-sheet,body.saver .nl-fcard,body.saver .fab,body.saver .chip{-webkit-backdrop-filter:none!important;backdrop-filter:none!important;background-color:var(--surface)}',
  '@media (prefers-contrast:more){.modal-card,.sg-panel,.nl-sheet,.nm-sheet,.nl-fcard,.fab,.chip{-webkit-backdrop-filter:none;backdrop-filter:none;background-color:var(--surface)}}',
  '@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){:root{--g-bg:rgba(17,29,51,.97)}}',
  '.nm-jv{position:fixed;z-index:32;left:50%;transform:translateX(-50%);max-width:min(94vw,440px);padding:10px 12px;border-radius:18px;background:#0B4D2C;color:#fff;',
  '  box-shadow:0 10px 30px rgba(0,0,0,.45);display:grid;gap:8px;justify-items:center;pointer-events:none}',
  '.nm-lanes{display:flex;gap:6px}.nm-lane{width:46px;height:52px;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.08);color:rgba(255,255,255,.35)}',
  '.nm-lane.on{background:rgba(255,255,255,.2);color:#fff}.nm-lane svg{width:32px;height:32px}',
  '.nm-sign{display:flex;gap:10px;align-items:baseline;font:600 15px Inter,system-ui,sans-serif}.nm-sign b{font:700 22px Poppins,Inter,sans-serif}',
  '.nm-sign span{background:#1A5FB4;border:2px solid #fff;border-radius:6px;padding:1px 8px;font-size:14px}',
  'body.nm-jv-on .sg-lm{display:none!important}'
].join('\n');

/* ---------------------------------------------------------------------
   WIRING
--------------------------------------------------------------------- */
function start() {
  if (X || !window.SHGNAV_X || !window.SHGNAV_X.hook) return;
  X = window.SHGNAV_X;
  var H = X.hook;
  css();
  askChips();
  nlSearch();
  hikeChip();
  H('dirSheet', function (r) { try { dirSheet(r); } catch (e) { if (QS.has('debug')) console.warn('nav-more', e); } });
  H('placeCard', function (p) { try { placeCard(p); } catch (e) {} });
  H('navStart', function (N) { if (N && !N.preview) { alStart(N); tripStart(N); } });
  H('navFix', function (f, N) { alTick(N); jvTick(N); steepTick(N); });
  H('navStop', function () { AL.N = null; pipStop(); jvTick(null); });
  H('arrive', function (N) { pipStop(); jvTick(null); tripArrive(N); });
  H('show', function (lvl) { if (lvl === 'home') setTimeout(usualChip, 0); });
  H('lang', function () { askLabels(); redraw(); hikePaint(); });
  setTimeout(usualChip, 2500);                            /* once GPS has had a moment */
  window.SHGNAV_MORE = { openVisits: openVisits };
  H('navMoreHtml', function (N) {
    if (!N || N.preview) return '';
    return '<button class="btn ghost" type="button" id="nmPipB" style="width:100%;height:52px;margin-top:8px">📺 ' + esc(t('pip')) + '</button>';
  });
  H('navMoreBind', function () {
    var b = $('#nmPipB'); if (b) b.onclick = function () { if (X.closeModal) X.closeModal(); pipStart(); };
  });
  if (QS.has('debug')) window.SHGMORE = { WHEN: function () { return WHEN; }, RC: function () { return RC; }, WX: function () { return WX; }, AL: function () { return AL; },
    askRun: function (id) { askRun(ASK.filter(function (a) { return a.id === id; })[0]); }, exploreSheet: exploreSheet, pipData: pipData, enc6: enc6, roadCheck: roadCheck };
}
if (window.SHGNAV_X) start(); else document.addEventListener('shgnav:ready', start, { once: true });
})();
