/* =====================================================================
   SHARI NAV · assets/nav/nav-yatra.js  (22 Oct 2026, Phase 5 "only we have this")

   · Tirtha yatra planner   pick dhams -> best order -> our own engine's real
                            road times -> days (drive hours + darshan time),
                            where to sleep (town + hotels), fuel on the way,
                            festival crowd on those dates; AI tips on request
   · Festival crowd         Shivaratri at Pashupati, Vivah Panchami at Janakpur,
                            Chhath, Ram Navami ... warn on a place card / route
   · Monsoon mode           June-Sept (or by hand): landslide / flood / closed
                            reports on the route, a safer alternative
   · Low-data mode          2G / 3G / data saver: no 3D, no blur, 1x pixels,
                            the add-ons skip pictures and extra lookups
   · SHG seats from the map an S Hari pickup shows the next coach and how
                            many seats are free, Book opens the booking app
   Festival dates were checked on 22 Oct 2026 (Drik Panchang and others).
   Panchangs differ by a day in places, so each window covers both days.
===================================================================== */
(function () {
'use strict';

var X = null;
var QS = new URLSearchParams(location.search);

/* ---------------------------------------------------------------------
   FESTIVAL CROWD CALENDAR  (dham ids = nav-guide.js DHAMS)
--------------------------------------------------------------------- */
var FEST = [
  { id: 'chhath26', from: '2026-11-13', to: '2026-11-16', at: ['janaki'], wide: 'terai',
    n: { ne: 'छठ पर्व', hi: 'छठ पूजा', en: 'Chhath Puja' },
    tip: { ne: 'तराई र बिहारका सडक, घाट र नाकामा भारी भीड। बिहान ३ – ७ र साँझ घाटतिर नजानुस्।', hi: 'तराई और बिहार की सड़कों, घाटों और सीमा पर भारी भीड़। सुबह 3–7 और शाम घाट की ओर न जाएं।', en: 'Heavy crowds on Terai / Bihar roads, ghats and border posts. Avoid ghats at dawn and dusk.' } },
  { id: 'kartik26', from: '2026-11-23', to: '2026-11-24', at: ['kashi', 'somnath'],
    n: { ne: 'कार्तिक पूर्णिमा / देव दीपावली', hi: 'कार्तिक पूर्णिमा / देव दीपावली', en: 'Kartik Purnima / Dev Deepawali' },
    tip: { ne: 'काशीका घाट र सोमनाथमा ठूलो भीड। दिउँसो नै पुग्नुस्, साँझ सवारी घाटसम्म जाँदैन।', hi: 'काशी के घाटों और सोमनाथ में भारी भीड़। दोपहर तक पहुँचें, शाम को वाहन घाट तक नहीं जाते।', en: 'Huge crowds at the Kashi ghats and Somnath. Arrive by midday; vehicles stop well short of the ghats in the evening.' } },
  { id: 'vivah26', from: '2026-12-13', to: '2026-12-14', at: ['janaki', 'ayodhya'],
    n: { ne: 'विवाह पञ्चमी', hi: 'विवाह पंचमी', en: 'Vivah Panchami' },
    tip: { ne: 'जनकपुर र अयोध्यामा राम–सीता विवाह उत्सव — होटल अघि नै बुक गर्नुस्, शोभायात्राको बाटो बन्द हुन सक्छ।', hi: 'जनकपुर और अयोध्या में राम–सीता विवाह उत्सव — होटल पहले बुक करें, शोभायात्रा के रास्ते बंद हो सकते हैं।', en: 'Ram–Sita wedding festival in Janakpur and Ayodhya — book a room early, procession routes may close.' } },
  { id: 'sankranti27', from: '2027-01-14', to: '2027-01-15', at: ['gorakhnath'],
    n: { ne: 'मकर संक्रान्ति (खिचडी मेला)', hi: 'मकर संक्रांति (खिचड़ी मेला)', en: 'Makar Sankranti (Khichdi Mela)' },
    tip: { ne: 'गोरखनाथ मन्दिरमा लाखौं भक्त — बिहान सबेरै वा अर्को दिन जानुस्।', hi: 'गोरखनाथ मंदिर में लाखों श्रद्धालु — सुबह जल्दी या अगले दिन जाएं।', en: 'Lakhs of devotees at Gorakhnath — go very early or the day after.' } },
  { id: 'shivaratri27', from: '2027-03-05', to: '2027-03-06', at: ['pashupati', 'kashi', 'somnath'],
    n: { ne: 'महाशिवरात्रि', hi: 'महाशिवरात्रि', en: 'Maha Shivaratri' },
    tip: { ne: 'पशुपतिनाथमा लाखौं भक्त र साधु; मन्दिर वरिपरिका बाटो बन्द। अघिल्लो दिन वा बिहान ४ बजेअघि पुग्नुस्।', hi: 'पशुपतिनाथ में लाखों श्रद्धालु और साधु; मंदिर के आसपास रास्ते बंद। एक दिन पहले या सुबह 4 बजे से पहले पहुँचें।', en: 'Lakhs of devotees and sadhus at Pashupati; roads around the temple close. Arrive the day before or before 4 am.' } },
  { id: 'navratri27', from: '2027-04-07', to: '2027-04-15', at: ['devipatan', 'ambaji', 'pavagadh', 'vaishno', 'manakamana', 'dakshinkali', 'bahuchar'],
    n: { ne: 'चैते दशैं / चैत्र नवरात्रि', hi: 'चैत्र नवरात्रि', en: 'Chaitra Navratri' },
    tip: { ne: 'शक्तिपीठहरूमा मेला र लामो लाइन — हप्ताको बीचमा, बिहान सबेरै जानुस्।', hi: 'शक्तिपीठों में मेला और लंबी कतार — सप्ताह के बीच, सुबह जल्दी जाएं।', en: 'Fairs and long queues at the Shaktipeeths — go midweek, early morning.' } },
  { id: 'ramnavami27', from: '2027-04-14', to: '2027-04-15', at: ['ayodhya', 'janaki'],
    n: { ne: 'राम नवमी', hi: 'राम नवमी', en: 'Ram Navami' },
    tip: { ne: 'अयोध्या र जनकपुरमा ठूलो भीड; अयोध्यामा सवारी शहरबाहिर रोकिन्छ।', hi: 'अयोध्या और जनकपुर में भारी भीड़; अयोध्या में वाहन शहर के बाहर रोके जाते हैं।', en: 'Big crowds in Ayodhya and Janakpur; Ayodhya stops vehicles outside town.' } },
  { id: 'janmashtami27', from: '2027-08-25', to: '2027-08-25', at: ['dwarka'],
    n: { ne: 'कृष्ण जन्माष्टमी', hi: 'कृष्ण जन्माष्टमी', en: 'Krishna Janmashtami' },
    tip: { ne: 'द्वारकाधीशमा मध्यरातसम्म भीड — होटल र पार्किङ अघि नै मिलाउनुस्।', hi: 'द्वारकाधीश में आधी रात तक भीड़ — होटल और पार्किंग पहले तय करें।', en: 'Crowds at Dwarkadhish till midnight — sort out a room and parking in advance.' } }
];
var VISIT_H = { pashupati: 2, muktinath: 3, janaki: 1.5, manakamana: 2.5, swayambhu: 1.5, dakshinkali: 1.5, lumbini: 3, kashi: 3, ayodhya: 3,
                gorakhnath: 1.5, devipatan: 1.5, somnath: 2, dwarka: 2, ambaji: 2, bahuchar: 1.5, modhera: 1.5, akshardham: 2.5, pavagadh: 3,
                kedarnath: 9, badrinath: 2, vaishno: 9 };

var TX = {
  plan: { ne: 'तीर्थयात्रा योजना', hi: 'तीर्थयात्रा योजना', en: 'Pilgrimage planner' },
  pick: { ne: 'धाम छान्नुस् (६ सम्म)', hi: 'धाम चुनें (6 तक)', en: 'Pick the dhams (up to 6)' },
  hours: { ne: 'दिनमा कति घण्टा गाडी चलाउने', hi: 'दिन में कितने घंटे गाड़ी', en: 'Driving hours a day' },
  startOn: { ne: 'यात्रा सुरु', hi: 'यात्रा शुरू', en: 'Start on' },
  make: { ne: 'योजना बनाउनुस्', hi: 'योजना बनाएं', en: 'Make the plan' },
  making: { ne: 'हाम्रो नक्सा इन्जिनले बाटो र समय हिसाब गर्दैछ…', hi: 'हमारा नक्शा इंजन रास्ता और समय गिन रहा है…', en: 'Our map engine is working out the roads and times…' },
  need: { ne: 'कम्तीमा एउटा धाम छान्नुस्', hi: 'कम से कम एक धाम चुनें', en: 'Pick at least one dham' },
  sum: { ne: '{d} दिन · {k} km · {n} धाम', hi: '{d} दिन · {k} km · {n} धाम', en: '{d} days · {k} km · {n} dhams' },
  day: { ne: 'दिन {n}', hi: 'दिन {n}', en: 'Day {n}' },
  drive: { ne: 'यात्रा {t} · {k} km', hi: 'यात्रा {t} · {k} km', en: 'Drive {t} · {k} km' },
  darshan: { ne: 'दर्शन ~{h} घण्टा', hi: 'दर्शन ~{h} घंटे', en: 'Darshan ~{h} h' },
  night: { ne: 'बास: {p}', hi: 'रात्रि विश्राम: {p}', en: 'Overnight: {p}' },
  fuel: { ne: 'इन्धन: {p}', hi: 'ईंधन: {p}', en: 'Fuel: {p}' },
  go: { ne: '▶ यो दिनको बाटो', hi: '▶ इस दिन का रास्ता', en: '▶ This day\'s route' },
  ai: { ne: '🙏 AI बाट सुझाव', hi: '🙏 AI से सुझाव', en: '🙏 Tips from AI' },
  aiQ: { ne: 'यो तीर्थयात्रा योजनामा दर्शन समय, खाना, बस्ने ठाउँ र सावधानीबारे छोटो सुझाव दिनुस्:\n', hi: 'इस तीर्थयात्रा योजना पर दर्शन समय, भोजन, ठहरने और सावधानी पर छोटे सुझाव दें:\n', en: 'Give short tips on darshan times, food, stays and cautions for this pilgrimage plan:\n' },
  failed: { ne: 'अहिले योजना बन्न सकेन — फेरि प्रयास गर्नुस्', hi: 'अभी योजना नहीं बन पाई — फिर कोशिश करें', en: 'Could not make the plan now — try again' },
  fest: { ne: 'पर्व-भीड क्यालेन्डर', hi: 'त्योहार-भीड़ कैलेंडर', en: 'Festival crowd calendar' },
  festNote: { ne: 'मिति पञ्चाङ्ग अनुसार एक दिन फरक पर्न सक्छ।', hi: 'तिथि पंचांग के अनुसार एक दिन आगे-पीछे हो सकती है।', en: 'Dates can differ by a day between panchangs.' },
  festOn: { ne: '{f}: {d} — {p} मा ठूलो भीड', hi: '{f}: {d} — {p} में भारी भीड़', en: '{f}: {d} — big crowds at {p}' },
  inDays: { ne: '{n} दिनमा', hi: '{n} दिन में', en: 'in {n} days' },
  today: { ne: 'आज', hi: 'आज', en: 'today' },
  monsoon: { ne: 'मनसुन मोड', hi: 'मानसून मोड', en: 'Monsoon mode' },
  monsoonRow: { ne: 'मनसुन: बाटोमा {n} पहिरो / बाढी / बन्द रिपोर्ट', hi: 'मानसून: रास्ते में {n} भूस्खलन / बाढ़ / बंद की रिपोर्ट', en: 'Monsoon: {n} landslide / flood / closure reports on this route' },
  monsoonHill: { ne: 'मनसुनमा पहाडी बाटो: दिउँसो मात्र यात्रा गर्नुस्, बिहान सडक विभागको सूचना हेर्नुस्।', hi: 'मानसून में पहाड़ी रास्ता: केवल दिन में यात्रा करें, सुबह सड़क विभाग की सूचना देखें।', en: 'Hill road in the monsoon: travel by daylight only, check the road department notice in the morning.' },
  safer: { ne: 'सुरक्षित बाटो (via {r}) — रिपोर्ट {n}', hi: 'सुरक्षित रास्ता (via {r}) — रिपोर्ट {n}', en: 'Safer route (via {r}) — {n} reports' },
  lowdata: { ne: 'कम डाटा मोड', hi: 'कम डेटा मोड', en: 'Low-data mode' },
  lowOn: { ne: '📶 कम डाटा मोड: 3D, फोटो र थप जानकारी बन्द', hi: '📶 कम डेटा मोड: 3D, फोटो और अतिरिक्त जानकारी बंद', en: '📶 Low-data mode: no 3D, pictures or extras' },
  auto: { ne: 'आफैँ', hi: 'स्वतः', en: 'Auto' }, on: { ne: 'चालु', hi: 'चालू', en: 'On' }, off: { ne: 'बन्द', hi: 'बंद', en: 'Off' },
  seats: { ne: '{r} · {w} {t} · {n} सिट खाली', hi: '{r} · {w} {t} · {n} सीट खाली', en: '{r} · {w} {t} · {n} seats free' },
  full: { ne: '{r} · {w} {t} · सिट भरियो', hi: '{r} · {w} {t} · सीटें भर गईं', en: '{r} · {w} {t} · full' },
  todayW: { ne: 'आज', hi: 'आज', en: 'today' }, tmrW: { ne: 'भोलि', hi: 'कल', en: 'tomorrow' },
  book: { ne: 'सिट बुक गर्नुस्', hi: 'सीट बुक करें', en: 'Book a seat' },
  close: { ne: 'बन्द', hi: 'बंद', en: 'Close' }
};

/* ---------------------------------------------------------------------
   HELPERS
--------------------------------------------------------------------- */
function $(s, r) { return (r || document).querySelector(s); }
function lang() { var l = X && X.S.lang; return l === 'hi' || l === 'en' ? l : 'ne'; }
function L(o) { return o ? (o[lang()] || o.en || '') : ''; }
function t(k, v) { var s = L(TX[k]) || k; if (v) Object.keys(v).forEach(function (n) { s = s.split('{' + n + '}').join(v[n]); }); return s; }
function esc(s) { return X.esc(s); }
var LSy = {
  get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
};
function here() { var S = X.S; if (S.me) return [S.me.lng, S.me.lat]; if (S.map) { var c = S.map.getCenter(); return [c.lng, c.lat]; } return null; }
function getJ(url, opt, ms) {
  return X.fetchT(url, opt || { credentials: 'same-origin' }, ms || 15000).then(function (r) {
    return r.json().catch(function () { return null; }).then(function (j) {
      if (!j || j.ok === false) throw new Error('http ' + r.status);
      return j.data !== undefined ? j.data : j;
    });
  });
}
function dham() { return (window.SHGNAV_GUIDE && window.SHGNAV_GUIDE.dhams) || []; }
function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
function niceDate(s) { var d = new Date(s + 'T12:00:00'); return d.toLocaleDateString(lang() === 'en' ? 'en-IN' : lang() === 'hi' ? 'hi-IN' : 'ne-NP', { day: 'numeric', month: 'short', weekday: 'short' }); }
function hm(sec) { var m = Math.round(sec / 60); return Math.floor(m / 60) + 'h ' + ('0' + (m % 60)).slice(-2) + 'm'; }
function lowData() { return !!window.SHGNAV_LOWDATA; }

/* ---------------------------------------------------------------------
   SHEET
--------------------------------------------------------------------- */
var SHT = { open: false, hist: false };
function sheet(html) {
  var w = $('#nyWrap');
  if (!w) {
    w = document.createElement('div'); w.className = 'nm-wrap'; w.id = 'nyWrap'; w.hidden = true;
    w.innerHTML = '<div class="nm-scrim" id="nyScrim"></div><section class="nm-sheet" role="dialog" aria-modal="true" id="nySheet"></section>';
    document.body.appendChild(w);
    $('#nyScrim').onclick = function () { sheetClose(); };
    addEventListener('popstate', function () { if (SHT.open && SHT.hist) { SHT.hist = false; sheetClose(true); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && SHT.open) { e.stopImmediatePropagation(); sheetClose(); } }, true);
  }
  $('#nySheet').innerHTML = '<button class="nm-x" type="button" data-nyx aria-label="' + esc(t('close')) + '">×</button>' + html;
  $('#nySheet [data-nyx]').onclick = function () { sheetClose(); };
  if (!SHT.open) {
    SHT.open = true; w.hidden = false;
    try { history.pushState({ lvl: X.S.level, d: X.S.depth, ny: 1 }, ''); SHT.hist = true; } catch (e) { SHT.hist = false; }
  }
  return $('#nySheet');
}
function sheetClose(fromPop, then) {
  if (!SHT.open) { if (then) then(); return; }
  SHT.open = false; $('#nyWrap').hidden = true;
  if (!fromPop && SHT.hist) {
    SHT.hist = false;
    if (then) { var f = function () { removeEventListener('popstate', f); setTimeout(then, 0); }; addEventListener('popstate', f); }
    try { history.back(); } catch (e) { if (then) then(); }
  } else if (then) then();
}

/* ---------------------------------------------------------------------
   FESTIVALS
--------------------------------------------------------------------- */
/* festivals at a place (3 km of a dham) from today for `days` */
function festAt(lng, lat, days, onDate) {
  var D = dham(), now = onDate ? new Date(onDate + 'T00:00:00') : new Date(), end = new Date(now.getTime() + (days || 0) * 864e5);
  var today = ymd(now), last = ymd(end), out = [];
  FEST.forEach(function (f) {
    if (f.to < today || f.from > last) return;
    var hit = f.at.map(function (id) { return D.filter(function (d) { return d.id === id; })[0]; })
      .filter(function (d) { return d && X.hav([d.lng, d.lat], [lng, lat]) < 3000; })[0];
    if (hit) out.push({ f: f, d: hit });
  });
  return out;
}
function festLine(x) {
  var f = x.f, days = Math.round((new Date(f.from + 'T00:00:00') - new Date(ymd(new Date()) + 'T00:00:00')) / 864e5);
  var when = f.from === f.to ? niceDate(f.from) : niceDate(f.from) + ' – ' + niceDate(f.to);
  return t('festOn', { f: L(f.n), d: when + (days > 0 ? ' (' + t('inDays', { n: days }) + ')' : days === 0 ? ' (' + t('today') + ')' : ''), p: L(x.d.n) });
}
function festRow(lng, lat) {
  var xs = festAt(lng, lat, 21); if (!xs.length) return '';
  return xs.map(function (x) { return '<div class="nm-row warn"><span>🎉</span><span><b>' + esc(festLine(x)) + '</b><small>' + esc(L(x.f.tip)) + '</small></span></div>'; }).join('');
}
function openFest() {
  var today = ymd(new Date()), D = dham();
  var list = FEST.filter(function (f) { return f.to >= today; });
  var h = '<h3>📅 ' + esc(t('fest')) + '</h3><p style="margin:0 0 8px;color:var(--text3,#6F7D99);font-size:12.5px">' + esc(t('festNote')) + '</p><div class="nm-list">';
  list.forEach(function (f, i) {
    var places = f.at.map(function (id) { var d = D.filter(function (x) { return x.id === id; })[0]; return d ? L(d.n) : ''; }).filter(Boolean).join(', ');
    h += '<button class="nm-li" type="button" data-fi="' + i + '"><span class="e">🎉</span><span><b>' + esc(L(f.n)) + '</b><small>' +
      esc((f.from === f.to ? niceDate(f.from) : niceDate(f.from) + ' – ' + niceDate(f.to)) + ' · ' + places) + '</small><small>' + esc(L(f.tip)) + '</small></span></button>';
  });
  var el = sheet(h + '</div>');
  el.querySelectorAll('[data-fi]').forEach(function (b) {
    b.onclick = function () {
      var f = list[+b.dataset.fi], d = D.filter(function (x) { return x.id === f.at[0]; })[0]; if (!d) return;
      sheetClose(false, function () { X.openPlace({ name: L(d.n), sub: L(d.city), lat: d.lat, lng: d.lng, kind: 'temple' }); });
    };
  });
}

/* ---------------------------------------------------------------------
   TIRTHA YATRA PLANNER
--------------------------------------------------------------------- */
function openPlanner() {
  var D = dham(), me = here(), prev = LSy.get('shg:ny:pick', { ids: [], mode: 'car', hrs: 6 });
  var list = D.slice().sort(function (a, b) { return me ? X.hav(me, [a.lng, a.lat]) - X.hav(me, [b.lng, b.lat]) : 0; });
  var tm = new Date(Date.now() + 864e5);
  var el = sheet('<h3>🗺️ ' + esc(t('plan')) + '</h3><div class="nm-h">' + esc(t('pick')) + '</div><div class="nm-list" style="max-height:34vh;overflow-y:auto">' +
    list.map(function (d) {
      return '<label class="nm-li" style="cursor:pointer"><input type="checkbox" value="' + d.id + '"' + (prev.ids.indexOf(d.id) >= 0 ? ' checked' : '') + ' style="width:22px;height:22px;flex:none">' +
        '<span><b>' + esc(L(d.n)) + '</b><small>' + esc(L(d.city)) + (me ? ' · ' + esc(X.fmtDist(X.hav(me, [d.lng, d.lat]))) : '') + '</small></span></label>';
    }).join('') + '</div>' +
    '<div class="nm-h">🚗</div><div class="nm-seg" data-g="mode">' + [['car', '🚗 Car'], ['moto', '🛵 Scooty'], ['bus', '🚌 Bus']].map(function (x) { return '<button type="button" data-v="' + x[0] + '" class="' + (prev.mode === x[0] ? 'on' : '') + '">' + x[1] + '</button>'; }).join('') + '</div>' +
    '<div class="nm-h">' + esc(t('hours')) + '</div><div class="nm-seg" data-g="hrs">' + [4, 6, 8, 10].map(function (h) { return '<button type="button" data-v="' + h + '" class="' + (prev.hrs === h ? 'on' : '') + '">' + h + 'h</button>'; }).join('') + '</div>' +
    '<div class="nm-h">' + esc(t('startOn')) + '</div><input class="nm-time" id="nyDate" type="date" value="' + ymd(tm) + '">' +
    '<button class="nm-b" type="button" data-make>' + esc(t('make')) + '</button>');
  el.querySelectorAll('.nm-seg').forEach(function (g) {
    g.onclick = function (e) { var b = e.target.closest('[data-v]'); if (!b) return; g.querySelectorAll('[data-v]').forEach(function (x) { x.classList.toggle('on', x === b); }); };
  });
  el.querySelector('[data-make]').onclick = function () {
    var ids = Array.prototype.map.call(el.querySelectorAll('input[type=checkbox]:checked'), function (c) { return c.value; }).slice(0, 6);
    if (!ids.length) { X.toast(t('need')); return; }
    var mode = (el.querySelector('[data-g=mode] .on') || {}).dataset.v || 'car', hrs = +((el.querySelector('[data-g=hrs] .on') || {}).dataset.v || 6);
    var date = el.querySelector('#nyDate').value || ymd(tm);
    LSy.set('shg:ny:pick', { ids: ids, mode: mode, hrs: hrs });
    makePlan(ids, mode, hrs, date, me);
  };
}
/* nearest neighbour, then 2-opt on straight-line distance (6 stops at most) */
function order(start, pts) {
  var left = pts.slice(), out = [], cur = start;
  while (left.length) {
    var bi = 0; left.forEach(function (p, i) { if (X.hav(cur, [p.lng, p.lat]) < X.hav(cur, [left[bi].lng, left[bi].lat])) bi = i; });
    cur = [left[bi].lng, left[bi].lat]; out.push(left.splice(bi, 1)[0]);
  }
  var len = function (r) { var s = 0, c = start; r.forEach(function (p) { s += X.hav(c, [p.lng, p.lat]); c = [p.lng, p.lat]; }); return s; };
  for (var imp = true, n = 0; imp && n < 20; n++) {
    imp = false;
    for (var i = 0; i < out.length - 1; i++) for (var j = i + 1; j < out.length; j++) {
      var r = out.slice(0, i).concat(out.slice(i, j + 1).reverse(), out.slice(j + 1));
      if (len(r) < len(out) - 1) { out = r; imp = true; }
    }
  }
  return out;
}
function makePlan(ids, mode, hrs, date, me) {
  var D = dham(), pts = ids.map(function (id) { return D.filter(function (d) { return d.id === id; })[0]; }).filter(Boolean);
  if (!me) return;
  var seq = order(me, pts);
  var el = sheet('<h3>🗺️ ' + esc(t('plan')) + '</h3><div class="nm-li" style="opacity:.7">' + esc(t('making')) + '</div>');
  var body = { locations: [{ lat: me[1], lon: me[0] }].concat(seq.map(function (d) { return { lat: d.lat, lon: d.lng }; })),
               costing: mode === 'bus' ? 'bus' : mode === 'moto' ? 'motorcycle' : 'auto', directions_options: { units: 'kilometers', language: 'en-US' },
               format: 'osrm', shape_format: 'geojson' };
  getJ('/api/nav-route.php?json=' + encodeURIComponent(JSON.stringify(body)), null, 30000).then(function (j) {
    var r = j && j.routes && j.routes[0];
    if (!r || !r.legs || r.legs.length !== seq.length) throw new Error('no route');
    var line = { coords: r.geometry.coordinates, steps: [], dist: r.distance, dur: r.duration };
    X.prepRoute(line);
    return planDays(seq, r, line.L, mode, hrs, date);
  }).then(function (plan) {
    LSy.set('shg:ny:plan', plan);
    if (SHT.open) showPlan(plan);
    enrich(plan);
  }).catch(function () { if (SHT.open && el.isConnected) el.querySelector('.nm-li').textContent = t('failed'); });
}
/* days: drive at most `hrs` a day, never after 20:00; darshan time at each dham;
   after a dham visit that ends after 17:00 the night is spent there */
function planDays(seq, r, Lr, mode, hrs, date) {
  var mult = mode === 'bus' ? 1.3 : 1, maxD = hrs * 3600, days = [], along = 0;
  var day = function (n) { var d = new Date(date + 'T12:00:00'); d.setDate(d.getDate() + n); return { date: ymd(d), drive: 0, km: 0, clock: 7 * 3600, items: [] }; };
  var cur = day(0);
  var close = function (p, atDham) { cur.items.push({ k: 'night', p: p, dham: atDham || null }); days.push(cur); cur = day(days.length); };
  r.legs.forEach(function (leg, i) {
    var dur = leg.duration * mult, rem = dur, km = leg.distance / 1000, legA = along;
    while (rem > 60) {
      var can = Math.min(maxD - cur.drive, 20 * 3600 - cur.clock);
      if (can <= 900) { close(X.at(Lr, legA + leg.distance * (1 - rem / dur)).p); continue; }
      var d = Math.min(can, rem);
      cur.items.push({ k: 'drive', s: d, km: Math.round(km * d / dur) });
      cur.drive += d; cur.clock += d; cur.km += km * d / dur; rem -= d;
    }
    along += leg.distance;
    var dh = seq[i], vh = VISIT_H[dh.id] || 2;
    cur.items.push({ k: 'dham', id: dh.id, at: cur.clock, h: vh, p: [dh.lng, dh.lat] });
    cur.clock += vh * 3600;
    if (i < seq.length - 1 && cur.clock > 17 * 3600) close([dh.lng, dh.lat], dh.id);
  });
  days.push(cur);
  return { mode: mode, hrs: hrs, date: date, km: Math.round(r.distance / 1000), n: seq.length, days: days, fuel: [], len: Lr.len,
           fuelAt: mode === 'bus' ? [] : fuelMarks(Lr, mode === 'moto' ? 150000 : 250000) };
}
function fuelMarks(Lr, every) { var out = []; for (var a = every; a < Lr.len - 30000 && out.length < 8; a += every) out.push({ a: a, p: X.at(Lr, a).p }); return out; }
/* town names, two hotels for each night, a petrol pump at each fuel mark */
function enrich(plan) {
  var jobs = [];
  plan.days.forEach(function (d, di) {
    d.items.forEach(function (it) {
      if (it.k !== 'night') return;
      jobs.push(function () {
        return getJ('/api/nav-search.php?city=' + it.p[1].toFixed(4) + ',' + it.p[0].toFixed(4), null, 8000).then(function (j) { it.town = (j && j.name) || ''; })
          .catch(function () {})
          .then(function () {
            if (lowData()) return null;
            return getJ('/api/nav-search.php?limit=6&q=hotel&lat=' + it.p[1].toFixed(4) + '&lng=' + it.p[0].toFixed(4), null, 9000).then(function (j) {
              it.hotels = ((j && j.results) || []).map(function (x) { return { n: x.shortName, lat: +x.lat, lng: +x.lng, d: X.hav(it.p, [+x.lng, +x.lat]) }; })
                .filter(function (x) { return x.d < 15000; }).sort(function (a, b) { return a.d - b.d; }).slice(0, 2);
            });
          }).catch(function () {});
      });
    });
  });
  plan.fuelAt.forEach(function (f) {
    jobs.push(function () {
      return getJ('/api/nav-search.php?limit=4&q=petrol&lat=' + f.p[1].toFixed(4) + '&lng=' + f.p[0].toFixed(4), null, 9000).then(function (j) {
        var x = ((j && j.results) || [])[0]; if (x) f.name = x.shortName + (x.displayName ? ' · ' + String(x.displayName).split(' · ').slice(-1)[0] : '');
      }).catch(function () {});
    });
  });
  jobs.reduce(function (c, j) { return c.then(j); }, Promise.resolve()).then(function () {
    LSy.set('shg:ny:plan', plan);
    if (SHT.open && $('#nyPlan')) showPlan(plan);
  });
}
function showPlan(plan) {
  var D = dham(), byId = {}; D.forEach(function (d) { byId[d.id] = d; });
  var h = '<h3>🗺️ ' + esc(t('plan')) + '</h3><div id="nyPlan"><p style="margin:0 0 6px"><b>' + esc(t('sum', { d: plan.days.length, k: plan.km.toLocaleString('en-IN'), n: plan.n })) + '</b> · ' + esc(plan.hrs + 'h') + '</p>';
  plan.days.forEach(function (d, di) {
    h += '<div class="nm-h">' + esc(t('day', { n: di + 1 })) + ' · ' + esc(niceDate(d.date)) + '</div><div class="nm-list">';
    d.items.forEach(function (it) {
      if (it.k === 'drive') h += '<div class="nm-li"><span class="e">' + (plan.mode === 'bus' ? '🚌' : plan.mode === 'moto' ? '🛵' : '🚗') + '</span><span>' + esc(t('drive', { t: hm(it.s), k: it.km })) + '</span></div>';
      else if (it.k === 'dham') {
        var dd = byId[it.id] || {}, fx = festAt(dd.lng, dd.lat, 0, d.date);
        h += '<div class="nm-li"><span class="e">🛕</span><span><b>' + esc(L(dd.n)) + '</b><small>' + esc(hm(it.at).replace('h ', ':').replace('m', '') + ' · ' + t('darshan', { h: it.h })) + '</small>' +
          (dd.tip ? '<small>' + esc(L(dd.tip)) + '</small>' : '') + fx.map(function (x) { return '<small style="color:#FF9F45">🎉 ' + esc(festLine(x)) + '</small>'; }).join('') + '</span></div>';
      } else if (it.k === 'night') {
        var place = it.dham ? L((byId[it.dham] || {}).city) : (it.town || '…');
        h += '<div class="nm-li"><span class="e">🌙</span><span><b>' + esc(t('night', { p: place })) + '</b>' +
          (it.hotels && it.hotels.length ? '<small>🏨 ' + esc(it.hotels.map(function (x) { return x.n; }).join(' · ')) + '</small>' : '') + '</span></div>';
      }
    });
    h += '</div><button class="nm-b" type="button" data-day="' + di + '" style="background:transparent;border:1px solid rgba(255,255,255,.18);margin-top:6px">' + esc(t('go')) + '</button>';
  });
  var fu = plan.fuelAt.filter(function (f) { return f.name; });
  if (fu.length) h += '<div class="nm-h">⛽</div><div class="nm-list">' + fu.map(function (f) { return '<div class="nm-li"><span class="e">⛽</span><span>' + esc(t('fuel', { p: 'km ' + Math.round(f.a / 1000) + ' · ' + f.name })) + '</span></div>'; }).join('') + '</div>';
  h += '<button class="nm-b" type="button" data-ai>' + esc(t('ai')) + '</button></div>';
  var el = sheet(h);
  el.querySelectorAll('[data-day]').forEach(function (b) {
    b.onclick = function () {
      var d = plan.days[+b.dataset.day], stops = d.items.filter(function (it) { return it.k === 'dham'; }).map(function (it) { var x = byId[it.id]; return { name: L(x.n), sub: L(x.city), lat: x.lat, lng: x.lng, kind: 'temple' }; });
      var night = d.items.filter(function (it) { return it.k === 'night'; })[0];
      var dest = night && !night.dham ? { name: night.town || t('night', { p: '' }), lat: night.p[1], lng: night.p[0] } : stops.pop();
      if (!dest) return;
      sheetClose(false, function () { X.goStops(stops.slice(0, 3), dest, { mode: plan.mode }); });
    };
  });
  var ai = el.querySelector('[data-ai]');
  if (ai) ai.onclick = function () {
    var txt = plan.days.map(function (d, i) {
      return t('day', { n: i + 1 }) + ' (' + d.date + '): ' + d.items.map(function (it) { return it.k === 'dham' ? L((byId[it.id] || {}).n) : it.k === 'night' ? '🌙 ' + (it.dham ? L((byId[it.dham] || {}).city) : it.town || '') : ''; }).filter(Boolean).join(' → ');
    }).join('\n');
    sheetClose(false, function () { if (X.aiAsk) X.aiAsk(t('aiQ') + txt + '\n(' + plan.mode + ', ' + plan.hrs + 'h/day)'); });
  };
}

/* ---------------------------------------------------------------------
   MONSOON MODE
--------------------------------------------------------------------- */
function monsoonOn() {
  var p = LSy.get('shg:ny:monsoon', 'auto'), m = new Date().getMonth() + 1;
  return p === 'on' || (p === 'auto' && m >= 6 && m <= 9);
}
var MZ = { cur: null, v: null };
var HAZ = { landslide: 1, flood: 1, closed: 1 };
function hazards(r) {
  if (!r.L && X.prepRoute) X.prepRoute(r);
  var Lr = r.L, pts = [], n = Math.min(6, Math.max(1, Math.ceil(Lr.len / 50000)));
  for (var i = 0; i < n; i++) pts.push(X.at(Lr, Lr.len * (i + 0.5) / n).p);
  return Promise.all(pts.map(function (p) {
    return getJ('/api/nav-reports.php?lat=' + p[1].toFixed(4) + '&lng=' + p[0].toFixed(4), null, 9000).then(function (j) { return j.reports || []; }).catch(function () { return []; });
  })).then(function (lists) {
    var seen = {}, out = [];
    lists.forEach(function (l) { l.forEach(function (x) { if (!HAZ[x.kind] || seen[x.id]) return; seen[x.id] = 1; out.push(x); }); });
    return out;
  });
}
function onRoute(Lr, list) {
  return list.filter(function (x) { var q = X.nearest(Lr, [x.lng, x.lat], 0, Lr.n - 2); return q.d < 300; });
}
function monsoonRow(r) {
  if (!monsoonOn()) return '';
  var cur = X.S.cur; if (!cur || !cur.routes) return '';
  if (MZ.cur !== cur) {
    MZ = { cur: cur, v: null };
    hazards(r).then(function (all) {
      if (MZ.cur !== cur) return;
      MZ.v = cur.routes.map(function (rr) { if (!rr.L && X.prepRoute) X.prepRoute(rr); return onRoute(rr.L, all).length; });
      if (X.S.level === 'dir' && X.S.cur === cur) moreRows(cur.routes[X.S.sel]);
    });
    return '';
  }
  if (!MZ.v) return '';
  var n = MZ.v[X.S.sel] || 0, h = '';
  var hill = r.coords.some(function (c, i) { return i % 20 === 0 && c[1] > 27.6 && c[1] < 30.4 && c[0] > 80.1 && c[0] < 88.2; });
  if (n) h += '<div class="nm-row warn"><span>🌧️</span><span><b>' + esc(t('monsoonRow', { n: n })) + '</b></span></div>';
  if (hill) h += '<div class="nm-row"><span>⛰️</span><span>' + esc(t('monsoonHill')) + '</span></div>';
  if (n) {
    var best = -1; MZ.v.forEach(function (v, i) { if (i !== X.S.sel && v < n && (best < 0 || v < MZ.v[best])) best = i; });
    if (best >= 0) h += '<button class="nm-row" type="button" data-ny-alt="' + best + '"><span>🛟</span><span><b>' + esc(t('safer', { r: cur.routes[best].via || '—', n: MZ.v[best] })) + '</b></span></button>';
  }
  return h;
}

/* ---------------------------------------------------------------------
   LOW-DATA MODE (2G / 3G / data saver)
--------------------------------------------------------------------- */
function lowWanted() {
  var p = LSy.get('shg:ny:low', 'auto'); if (p === 'on') return true; if (p === 'off') return false;
  var c = navigator.connection; return !!(c && (c.saveData || /^(slow-2g|2g|3g)$/.test(c.effectiveType || '')));
}
function lowApply() {
  var on = lowWanted(), was = !!window.SHGNAV_LOWDATA;
  window.SHGNAV_LOWDATA = on;
  document.body.classList.toggle('lowdata', on);
  var m = X.S.map;
  if (on && m) {
    try { if (m.getLayer('b3d')) m.removeLayer('b3d'); } catch (e) {}
    try { m.setPixelRatio(1); } catch (e) {}
    X.S.b3d = false;                                    /* this visit only: the saved choice stays */
  }
  if (on && !was) X.toast(t('lowOn'), 3500);
}

/* ---------------------------------------------------------------------
   SHG SEATS FROM THE MAP (an S Hari pickup's place card)
--------------------------------------------------------------------- */
var TT = { at: 0, p: null };
function timetable() {
  if (TT.p && Date.now() - TT.at < 10 * 60e3) return TT.p;
  TT.at = Date.now();
  TT.p = getJ('/api/timetable.php', null, 10000).catch(function () { TT.p = null; return null; });
  return TT.p;
}
function seatsFor(p) {
  var box = $('#placeX'); if (!box || !p || p.me) return;
  timetable().then(function (tt) {
    if (!tt || tt.serviceOff || !$('#placeX')) return;
    var now = new Date(), rows = [];
    (tt.routes || []).forEach(function (rt) {
      if (!rt.sellable) return;
      var st = (rt.boarding || []).filter(function (b) { return b.lat != null && X.hav([b.lng, b.lat], [p.lng, p.lat]) < 400; })[0];
      if (!st) return;
      var hh = String(st.time || rt.depTime || '00:00').split(':'), when = new Date(now); when.setHours(+hh[0], +hh[1], 0, 0);
      var dep = new Date(now); var dh = String(rt.depTime || '00:00').split(':'); dep.setHours(+dh[0], +dh[1], 0, 0);
      var tomorrow = when.getTime() - (tt.cutoffMinutes || 30) * 60e3 < now.getTime();
      if (tomorrow) dep.setDate(dep.getDate() + 1);
      rows.push({ rt: rt, st: st, date: ymd(dep), tm: tomorrow });
    });
    if (!rows.length) return;
    Promise.all(rows.map(function (x) {
      return getJ('/api/seats.php?routeCode=' + encodeURIComponent(x.rt.routeCode) + '&date=' + x.date, null, 9000).then(function (s) { x.n = s.availableCount; }).catch(function () { x.n = null; });
    })).then(function () {
      var bx = $('#placeX'); if (!bx || !X.S.to || X.hav([X.S.to.lng, X.S.to.lat], [p.lng, p.lat]) > 50) return;
      var old = $('#nySeats'); if (old) old.remove();
      var div = document.createElement('div'); div.id = 'nySeats'; div.style.cssText = 'display:grid;gap:6px;margin-top:8px';
      div.innerHTML = rows.filter(function (x) { return x.n != null; }).map(function (x) {
        var lbl = x.rt.from + ' → ' + x.rt.to, w = x.tm ? t('tmrW') : t('todayW');
        return '<div class="nm-row' + (x.n > 0 ? '' : ' warn') + '"><span>🪑</span><span><b>' + esc(x.n > 0 ? t('seats', { r: lbl, w: w, t: x.st.time, n: x.n }) : t('full', { r: lbl, w: w, t: x.st.time })) + '</b>' +
          (x.n > 0 ? '<small><a href="/?src=shari-nav" style="color:var(--gold,#D4A843)">' + esc(t('book')) + ' →</a></small>' : '') + '</span></div>';
      }).join('');
      bx.appendChild(div);
    });
  });
}

/* ---------------------------------------------------------------------
   WIRING
--------------------------------------------------------------------- */
function moreRows(r) {
  var box = $('#nmBox'); if (!box || !r) return;
  var old = $('#nyRows'); if (old) old.remove();
  var to = X.S.to, h = (to && !to.me ? festRow(+to.lng, +to.lat) : '') + monsoonRow(r);
  if (!h) return;
  /* beside #nmBox, not in it: nav-more redraws its own box when rain / road checks land */
  var div = document.createElement('div'); div.id = 'nyRows'; div.style.cssText = 'display:grid;gap:6px;margin-top:6px';
  div.innerHTML = h; box.parentNode.insertBefore(div, box.nextSibling);
  var alt = div.querySelector('[data-ny-alt]'); if (alt) alt.onclick = function () { if (X.selectAlt) X.selectAlt(+alt.dataset.nyAlt); };
}
function seg(k, cur) {
  return '<div class="seg" data-ny="' + k + '" style="margin-bottom:6px">' + [['auto', t('auto')], ['on', t('on')], ['off', t('off')]].map(function (x) {
    return '<button type="button" data-v="' + x[0] + '" class="' + (cur === x[0] ? 'on' : '') + '">' + esc(x[1]) + '</button>';
  }).join('') + '</div>';
}
function start() {
  if (X || !window.SHGNAV_X || !window.SHGNAV_X.hook) return;
  X = window.SHGNAV_X;
  var H = X.hook;
  var css = document.createElement('style');
  css.textContent = 'body.lowdata *,body.lowdata *::before,body.lowdata *::after{animation:none!important;transition-duration:0s!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}' +
    'body.lowdata .nm-card img{display:none}';
  document.head.appendChild(css);
  H('dirSheet', function (r) { setTimeout(function () { try { moreRows(r); } catch (e) { if (QS.has('debug')) console.warn('nav-yatra', e); } }, 0); });
  H('placeCard', function (p) {
    try {
      var bx = $('#placeX');
      if (bx && p && !p.me) { var f = festRow(+p.lng, +p.lat); if (f) bx.insertAdjacentHTML('beforeend', '<div style="display:grid;gap:6px;margin-top:8px">' + f + '</div>'); }
      if (p && (p.shg || p.ours || /S Hari/i.test(p.sub || ''))) seatsFor(p);
    } catch (e) {}
  });
  H('layersHtml', function () {
    return '<h3 style="margin-top:20px">🌧️ ' + esc(t('monsoon')) + '</h3>' + seg('monsoon', LSy.get('shg:ny:monsoon', 'auto')) +
      '<h3 style="margin-top:14px">📶 ' + esc(t('lowdata')) + '</h3>' + seg('low', LSy.get('shg:ny:low', 'auto'));
  });
  H('layersBind', function (rerender) {
    document.querySelectorAll('#modalCard [data-ny] [data-v]').forEach(function (b) {
      b.onclick = function () {
        var k = b.closest('[data-ny]').dataset.ny;
        LSy.set(k === 'monsoon' ? 'shg:ny:monsoon' : 'shg:ny:low', b.dataset.v);
        if (k === 'low') lowApply(); else MZ = { cur: null, v: null };
        if (rerender) rerender();
      };
    });
  });
  H('mapReady', lowApply);
  H('styleLoad', function () { if (window.SHGNAV_LOWDATA) setTimeout(lowApply, 0); });
  if (navigator.connection && navigator.connection.addEventListener) navigator.connection.addEventListener('change', lowApply);
  (function waitMap(n) { if (X.S.map || n > 40) lowApply(); else setTimeout(function () { waitMap(n + 1); }, 300); })(0);
  window.SHGNAV_YATRA = { openPlanner: openPlanner, openFest: openFest };
  if (QS.has('debug')) window.SHGYATRA = { FEST: FEST, festAt: festAt, order: order, makePlan: makePlan, planDays: planDays, monsoonOn: monsoonOn, MZ: function () { return MZ; }, lowApply: lowApply, seatsFor: seatsFor };
}
if (window.SHGNAV_X) start(); else document.addEventListener('shgnav:ready', start, { once: true });
})();
