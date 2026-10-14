/* =====================================================================
   27-smartbook.js — the SmartBook card (15 Oct 2026, owner's Final
   Prompt v5). One screen, one tap: direction → date → boarding → mobile +
   name → passengers (gender + age band) → type → berths (Auto by
   default) → [Cash] [Online]. The tap IS the booking.

   Everything is decided on the server (/api/smartbook.php → SmartBook):
   the bus, the date, the berths (AutoBerth), the price (the sale's own
   priceFor) and the sale (BookingService::create, idempotent). This file
   only collects the answers, shows the live summary and the ticket.

   Customer site only: no agent / counter / code field anywhere (D1, D13).
   ===================================================================== */
(function () {
  'use strict';
  var root = document.getElementById('smartBook');
  if (!root || typeof shgApi === 'undefined') return;

  /* ---------------------------------------------------------------- words */
  var W = {
    en: {
      title: 'One page. One tap. Happy journey.', sub: 'Gujarat ⇄ Rupaidiha · AC sleeper', botLbl: 'QuickBot — type it in one line',
      botPh: 'Ram 98…, 2 people, Vadodara tomorrow', botGo: 'Fill the card', dirGo: 'Gujarat → Rupaidiha', dirBack: 'Rupaidiha → Gujarat',
      date: 'Date', board: 'Boarding point', drop: 'Getting off at', near: 'Nearest to me', mobile: 'Mobile (WhatsApp ticket)', name: 'Lead passenger name',
      namePh: 'Full name', pax: 'Passengers', sharing: 'Sharing', private: 'Private', cSingle: 'Single', cDouble: 'Double', bAny: 'Any', bLower: 'Lower', bUpper: 'Upper',
      auto: 'Auto', rec: 'Recommended', choose: 'Choose seats', terms: 'By booking I accept the', termsLink: 'Terms', summary: 'Journey Summary',
      sRoute: 'Route', sDate: 'Date', sBoard: 'Boarding', sPax: 'Passengers', sSeats: 'Seats', total: 'Total', cash: 'Cash', cashSub: 'pay at boarding',
      online: 'Online', onlineSub: 'UPI · eSewa QR', pickT: 'Choose your berths', done: 'Done',
      today: 'Today', tomorrow: 'Tomorrow', pickDate: 'Pick a date', female: 'Female', male: 'Male', gender: 'F / M?',
      adult: 'Adult', child: 'Child <12', senior: 'Senior 60+', patient: 'Patient', lower: 'lower floor', upper: 'upper floor', autoTag: 'auto', yours: 'your choice',
      report: 'report', needPhone: 'Enter a valid mobile', needPhoneNp: 'Nepal mobile: 10 digits starting 96 / 97 / 98', needPhoneIn: 'India mobile: 10 digits starting 6–9',
      needName: 'Enter the lead passenger\'s name', needGender: 'Tap F / M for every passenger', loading: 'Finding the best berths…',
      booking: 'Booking…', ticketOk: 'Ticket confirmed', codNote: 'Cash — pay at the boarding counter', pnr: 'PNR', dlPdf: 'Download PDF', dlPng: 'Download PNG',
      share: 'Share', print: 'Print', copy: 'Copy PNR', track: 'Track bus', copied: 'PNR copied', newB: 'Book another', heldQr: 'Berths held — opening the QR page…',
      paxN: '{n} passenger(s)', left: '{n} left', changed: 'The price or date changed — please check and tap again.', geoNo: 'Location not available — choose from the list.',
      pickN: '{a} of {b} chosen', women: 'Women only', taken: 'Taken', free: 'Free', mine: 'Your pick', pickAll: 'Choose one berth for each passenger',
      floor1: 'Lower floor (1F)', floor2: 'Upper floor (2F)', perCabin: 'cabin', cabins: 'cabins'
    },
    hi: {
      title: 'एक पेज। एक टैप। शुभ यात्रा।', sub: 'गुजरात ⇄ रुपईडीहा · AC स्लीपर', botLbl: 'QuickBot — एक लाइन में लिखें',
      botPh: 'राम 98…, 2 लोग, वडोदरा कल', botGo: 'कार्ड भरें', dirGo: 'गुजरात → रुपईडीहा', dirBack: 'रुपईडीहा → गुजरात',
      date: 'तारीख', board: 'चढ़ने की जगह', drop: 'उतरने की जगह', near: 'मेरे पास', mobile: 'मोबाइल (WhatsApp टिकट)', name: 'मुख्य यात्री का नाम',
      namePh: 'पूरा नाम', pax: 'यात्री', sharing: 'शेयरिंग', private: 'प्राइवेट', cSingle: 'सिंगल', cDouble: 'डबल', bAny: 'कोई भी', bLower: 'नीचे', bUpper: 'ऊपर',
      auto: 'ऑटो', rec: 'सुझाया', choose: 'सीट चुनें', terms: 'बुकिंग करके मैं', termsLink: 'शर्तें मानता हूँ', summary: 'यात्रा सारांश',
      sRoute: 'रूट', sDate: 'तारीख', sBoard: 'चढ़ना', sPax: 'यात्री', sSeats: 'सीट', total: 'कुल', cash: 'कैश', cashSub: 'चढ़ते समय भुगतान',
      online: 'ऑनलाइन', onlineSub: 'UPI · eSewa QR', pickT: 'अपनी बर्थ चुनें', done: 'हो गया',
      today: 'आज', tomorrow: 'कल', pickDate: 'तारीख चुनें', female: 'महिला', male: 'पुरुष', gender: 'म / पु?',
      adult: 'वयस्क', child: 'बच्चा <12', senior: 'वरिष्ठ 60+', patient: 'मरीज़', lower: 'नीचे', upper: 'ऊपर', autoTag: 'ऑटो', yours: 'आपकी पसंद',
      report: 'रिपोर्ट', needPhone: 'सही मोबाइल लिखें', needPhoneNp: 'नेपाल मोबाइल: 96 / 97 / 98 से 10 अंक', needPhoneIn: 'भारत मोबाइल: 6–9 से 10 अंक',
      needName: 'मुख्य यात्री का नाम लिखें', needGender: 'हर यात्री के लिए म / पु दबाएँ', loading: 'सबसे अच्छी बर्थ ढूँढ रहे हैं…',
      booking: 'बुक हो रहा है…', ticketOk: 'टिकट पक्का', codNote: 'कैश — चढ़ते समय काउंटर पर भुगतान', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
      share: 'शेयर', print: 'प्रिंट', copy: 'PNR कॉपी', track: 'बस ट्रैक', copied: 'PNR कॉपी हुआ', newB: 'और बुक करें', heldQr: 'बर्थ रखी गई — QR पेज खुल रहा है…',
      paxN: '{n} यात्री', left: '{n} बाकी', changed: 'किराया या तारीख बदली — जाँचकर फिर दबाएँ।', geoNo: 'लोकेशन नहीं मिली — सूची से चुनें।',
      pickN: '{b} में {a} चुनी', women: 'केवल महिला', taken: 'बुक', free: 'खाली', mine: 'आपकी', pickAll: 'हर यात्री के लिए एक बर्थ चुनें',
      floor1: 'नीचे (1F)', floor2: 'ऊपर (2F)', perCabin: 'केबिन', cabins: 'केबिन'
    },
    ne: {
      title: 'एउटै पेज। एउटै ट्याप। शुभ यात्रा।', sub: 'गुजरात ⇄ रुपैडिहा · AC स्लिपर', botLbl: 'QuickBot — एक लाइनमा लेख्नुहोस्',
      botPh: 'राम 98…, 2 जना, वडोदरा भोलि', botGo: 'कार्ड भर्नुहोस्', dirGo: 'गुजरात → रुपैडिहा', dirBack: 'रुपैडिहा → गुजरात',
      date: 'मिति', board: 'चढ्ने ठाउँ', drop: 'ओर्लने ठाउँ', near: 'मेरो नजिक', mobile: 'मोबाइल (WhatsApp टिकट)', name: 'मुख्य यात्रुको नाम',
      namePh: 'पूरा नाम', pax: 'यात्रु', sharing: 'सेयरिङ', private: 'प्राइभेट', cSingle: 'सिंगल', cDouble: 'डबल', bAny: 'जुनसुकै', bLower: 'तल', bUpper: 'माथि',
      auto: 'अटो', rec: 'सिफारिस', choose: 'सिट छान्ने', terms: 'बुकिङ गर्दा', termsLink: 'सर्त मान्छु', summary: 'यात्रा सारांश',
      sRoute: 'रुट', sDate: 'मिति', sBoard: 'चढ्ने', sPax: 'यात्रु', sSeats: 'सिट', total: 'जम्मा', cash: 'नगद', cashSub: 'चढ्दा तिर्ने',
      online: 'अनलाइन', onlineSub: 'UPI · eSewa QR', pickT: 'आफ्नो बर्थ छान्नुहोस्', done: 'भयो',
      today: 'आज', tomorrow: 'भोलि', pickDate: 'मिति छान्नुहोस्', female: 'महिला', male: 'पुरुष', gender: 'म / पु?',
      adult: 'वयस्क', child: 'बच्चा <१२', senior: 'ज्येष्ठ ६०+', patient: 'बिरामी', lower: 'तल्लो तला', upper: 'माथिल्लो तला', autoTag: 'अटो', yours: 'तपाईंको रोजाइ',
      report: 'रिपोर्ट', needPhone: 'सही मोबाइल लेख्नुहोस्', needPhoneNp: 'नेपाल मोबाइल: ९६ / ९७ / ९८ बाट १० अङ्क', needPhoneIn: 'भारत मोबाइल: ६–९ बाट १० अङ्क',
      needName: 'मुख्य यात्रुको नाम लेख्नुहोस्', needGender: 'हरेक यात्रुको म / पु थिच्नुहोस्', loading: 'सबैभन्दा राम्रो बर्थ खोज्दै…',
      booking: 'बुक हुँदैछ…', ticketOk: 'टिकट पक्का भयो', codNote: 'नगद — चढ्दा काउन्टरमा तिर्ने', pnr: 'PNR', dlPdf: 'PDF डाउनलोड', dlPng: 'PNG डाउनलोड',
      share: 'सेयर', print: 'प्रिन्ट', copy: 'PNR कपी', track: 'बस ट्र्याक', copied: 'PNR कपी भयो', newB: 'अर्को बुक', heldQr: 'बर्थ राखियो — QR पेज खुल्दैछ…',
      paxN: '{n} जना', left: '{n} बाँकी', changed: 'भाडा वा मिति बदलियो — हेरेर फेरि थिच्नुहोस्।', geoNo: 'लोकेसन भेटिएन — सूचीबाट छान्नुहोस्।',
      pickN: '{b} मध्ये {a} छानियो', women: 'महिला मात्र', taken: 'बुक भइसक्यो', free: 'खाली', mine: 'तपाईंको', pickAll: 'हरेक यात्रुका लागि एउटा बर्थ छान्नुहोस्',
      floor1: 'तल्लो तला (1F)', floor2: 'माथिल्लो तला (2F)', perCabin: 'क्याबिन', cabins: 'क्याबिन'
    }
  };
  /* Staff faces (admin/smartbook.php on the .network door) set window.SB_STAFF =
     {mode: 'agent' | 'counter' | 'admin', …}; the customer site never does, and
     the server ignores an asked mode a session may not use (SmartBook::sessionMode). */
  var STAFF = (window.SB_STAFF && typeof window.SB_STAFF === 'object') ? window.SB_STAFF : null;
  var MODE = STAFF ? String(STAFF.mode || 'admin') : 'customer';
  function api(body) { if (STAFF) body.mode = MODE; return shgApi.post('/smartbook.php', body); }
  function lang() { var l = (typeof LANG === 'string' && LANG) ? LANG : 'en'; return W[l] ? l : 'en'; }
  function tr(k, v) {
    var s = (W[lang()] && W[lang()][k]) || W.en[k] || k;
    if (v) Object.keys(v).forEach(function (x) { s = s.split('{' + x + '}').join(v[x]); });
    return s;
  }
  function L3(o) { if (!o) return ''; return o[lang()] || o.en || ''; }
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function npr(n) { return 'NPR ' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function say(m) { try { if (typeof toast === 'function') toast(m); } catch (e) {} }

  /* ---------------------------------------------------------------- state */
  var S = {
    dirs: [], points: {}, dir: 'toNepal', date: '', point: '', cc: 'IN', phone: '', name: '',
    pax: [{ gender: '', band: 'adult' }], type: 'sharing', cabin: 'single', berth: 'any', seatMode: 'auto', manual: [],
    q: null, busy: false, idem: null, touched: {}, why: {}, allowCod: true, maxPax: 6, last: null
  };
  var store = {
    get: function () { try { return JSON.parse(localStorage.getItem('sb:v1') || 'null') || {}; } catch (e) { return {}; } },
    set: function () {
      try {
        localStorage.setItem('sb:v1', JSON.stringify({ dir: S.dir, point: S.point, cc: S.cc, phone: S.phone, name: S.name,
          pax: S.pax.map(function (p) { return { gender: p.gender, band: p.band }; }), type: S.type, cabin: S.cabin, berth: S.berth }));
      } catch (e) {}
    }
  };

  /* -------------------------------------------------------------- i18n */
  function applyWords() {
    root.querySelectorAll('[data-sbi]').forEach(function (el) { el.textContent = tr(el.getAttribute('data-sbi')); });
    root.querySelectorAll('[data-sbi-ph]').forEach(function (el) { el.setAttribute('placeholder', tr(el.getAttribute('data-sbi-ph'))); });
    root.querySelectorAll('[data-sbi-title]').forEach(function (el) { var s = tr(el.getAttribute('data-sbi-title')); el.title = s; el.setAttribute('aria-label', s); });
    try { if (typeof factPolicyLine === 'function') $('sbPolicy').textContent = ' · ' + factPolicyLine(); } catch (e) {}
    if (STAFF) {   // at a desk Cash is money taken now; an agent's Online is the customer's QR
      root.querySelector('#sbCash small').textContent = MODE === 'agent' ? 'agent collects' : 'received now';
      root.querySelector('#sbOnline small').textContent = MODE === 'agent' ? 'customer pays QR' : 'UPI · eSewa seen';
    }
    $('sbPointL').textContent = tr(pointType() === 'drop' ? 'drop' : 'board');
    renderDates(); renderPax(); renderQuote();
  }

  /* -------------------------------------------------------------- boot */
  function boot() {
    document.documentElement.classList.add('sb-on');
    root.hidden = false;
    retargetBookLinks();
    var saved = store.get();
    api({ action: 'boot', phone: saved.phone || '', direction: saved.dir || '' })
      .then(function (d) {
        S.dirs = d.directions || [];
        S.points = d.points || {};
        S.allowCod = d.allowCod !== false;
        S.maxPax = d.maxPax || 6;
        var df = d.defaults || {};
        S.why = df.why || {};
        S.dir = saved.dir || df.direction || 'toNepal';
        S.point = (saved.point && hasPoint(S.dir, saved.point)) ? saved.point : (df.point || '');
        if (S.dir !== df.direction && !hasPoint(S.dir, S.point)) S.point = firstPoint(S.dir);
        S.cc = saved.cc || 'IN'; S.phone = saved.phone || ''; S.name = saved.name || df.name || '';
        S.pax = (saved.pax && saved.pax.length ? saved.pax : (df.pax && df.pax.length ? df.pax : S.pax)).slice(0, S.maxPax)
          .map(function (p) { return { gender: p.gender === 'Female' || p.gender === 'Male' ? p.gender : '', band: p.band || 'adult' }; });
        S.type = saved.type || df.type || 'sharing'; S.cabin = saved.cabin || df.cabin || 'single'; S.berth = saved.berth || df.berth || 'any';
        if (!S.allowCod) $('sbCash').hidden = true;
        fill(); applyWords(); requote(0);
      })
      .catch(function (e) {
        // the engine is unreachable: the classic search card takes over
        document.documentElement.classList.remove('sb-on');
        root.hidden = true;
        console.warn('SmartBook boot failed', e);
      });
  }
  function pointType() { return S.dir === 'toIndia' ? 'drop' : 'boarding'; }
  function pointList(dir) { return ((S.points[dir || S.dir] || {}).list) || []; }
  function hasPoint(dir, name) { return pointList(dir).some(function (p) { return p.name === name; }); }
  function firstPoint(dir) { var l = pointList(dir); return l.length ? l[0].name : ''; }

  /* ------------------------------------------------------- the controls */
  function fill() {
    root.querySelectorAll('.sb-dir-b').forEach(function (b) {
      var on = b.getAttribute('data-dir') === S.dir;
      b.classList.toggle('on', on); b.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    var sel = $('sbPoint'); var h = '';
    pointList().forEach(function (p) {
      var lbl = p.short || p.name;
      if (p.time) lbl += ' · ' + p.time; else if (p.landmark) lbl += ' · ' + p.landmark;
      h += '<option value="' + esc(p.name) + '"' + (p.name === S.point ? ' selected' : '') + '>' + esc(lbl) + '</option>';
    });
    sel.innerHTML = h || '<option value="">—</option>';
    if (!hasPoint(S.dir, S.point)) S.point = firstPoint();
    sel.value = S.point;
    $('sbPointWhy').textContent = S.why.point ? L3(S.why.point) : '';
    $('sbCc').value = S.cc; $('sbPhone').value = S.phone; $('sbName').value = S.name;
    seg('sbType', S.type); seg('sbCabin', S.cabin); seg('sbBerth', S.berth); seg('sbSeatMode', S.seatMode);
    $('sbCabin').hidden = S.type !== 'private';
    renderPax(); renderDates();
  }
  function seg(id, v) {
    $(id).querySelectorAll('button').forEach(function (b) { var on = b.getAttribute('data-v') === v; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  }

  function isoAdd(iso, n) { var d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function todayIso() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function dayLabel(iso) {
    if (iso === todayIso()) return tr('today');
    if (iso === isoAdd(todayIso(), 1)) return tr('tomorrow');
    try { return new Date(iso + 'T00:00:00').toLocaleDateString(lang() === 'en' ? 'en-IN' : (lang() === 'hi' ? 'hi-IN' : 'ne-NP'), { weekday: 'short' }); } catch (e) { return iso; }
  }
  function renderDates() {
    var box = $('sbDates'); if (!box) return;
    var first = (S.q && S.q.date) || S.date || todayIso();
    var cur = S.date || (S.q && S.q.date) || '';
    var a = S.date && S.date !== first ? [S.date] : [];
    var list = [first, isoAdd(first, 1)];
    if (a.length && list.indexOf(a[0]) < 0) list = [first, a[0]];
    var h = '';
    list.forEach(function (iso) {
      var d = new Date(iso + 'T00:00:00');
      h += '<button type="button" class="sb-chip' + (iso === cur ? ' on' : '') + '" role="radio" aria-checked="' + (iso === cur) + '" data-date="' + iso + '">'
        + '<b>' + esc(dayLabel(iso)) + '</b><small>' + d.getDate() + ' ' + d.toLocaleDateString('en-IN', { month: 'short' }) + '</small></button>';
    });
    h += '<button type="button" class="sb-chip sb-chip-cal" data-date="cal" aria-label="' + esc(tr('pickDate')) + '">📅</button>';
    box.innerHTML = h;
    if (S.q && S.q.window && !STAFF) { $('sbDateIn').min = S.q.window.from; $('sbDateIn').max = S.q.window.to; }
  }

  function bandLabel(b) { return tr(b === 'child' ? 'child' : (b === 'senior' ? 'senior' : (b === 'patient' ? 'patient' : 'adult'))); }
  function renderPax() {
    $('sbCount').textContent = String(S.pax.length);
    var h = '';
    S.pax.forEach(function (p, i) {
      var g = p.gender === 'Female' ? 'f' : (p.gender === 'Male' ? 'm' : 'u');
      var gl = p.gender === 'Female' ? '♀ ' + tr('female') : (p.gender === 'Male' ? '♂ ' + tr('male') : tr('gender'));
      h += '<span class="sb-p' + (g === 'u' && S.touched.book ? ' sb-need' : '') + '"><button type="button" class="sb-g ' + g + '" data-i="' + i + '" data-act="g" aria-label="Passenger ' + (i + 1) + ' gender">' + esc(gl) + '</button>'
        + '<button type="button" class="sb-b" data-i="' + i + '" data-act="b" aria-label="Passenger ' + (i + 1) + ' age band">' + esc(bandLabel(p.band)) + '</button></span>';
    });
    var row = $('sbPax'); row.innerHTML = h;
    row.classList.toggle('sb-scroll', S.pax.length >= 5);
  }

  /* ------------------------------------------------------- validation */
  function digits(s) { return String(s || '').replace(/\D/g, ''); }
  function phoneErr() {
    var d = digits(S.phone);
    if (!d && (MODE === 'counter' || MODE === 'admin')) return '';
    if (S.cc === 'NP') { if (d.length === 13 && d.indexOf('977') === 0) d = d.slice(3); return /^9[678]\d{8}$/.test(d) ? '' : tr('needPhoneNp'); }
    if (d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    if (d.length === 11 && d.charAt(0) === '0') d = d.slice(1);
    return /^[6-9]\d{9}$/.test(d) ? '' : tr('needPhoneIn');
  }
  function errs() {
    var e = {};
    var pe = phoneErr(); if (pe) e.phone = pe;
    if (String(S.name || '').trim().length < 2) e.name = tr('needName');
    if (S.pax.some(function (p) { return !p.gender; })) e.pax = tr('needGender');
    return e;
  }
  function showErrs(all) {
    var e = errs();
    var f = [['phone', 'sbPhone', 'sbPhoneE'], ['name', 'sbName', 'sbNameE'], ['pax', null, 'sbPaxE']];
    f.forEach(function (x) {
      var show = !!e[x[0]] && (all || S.touched[x[0]]);
      var el = $(x[2]); el.hidden = !show; el.textContent = show ? e[x[0]] : '';
      if (x[1]) $(x[1]).classList.toggle('sb-bad', show);
    });
    if (all) renderPax();
    return e;
  }
  function canBook() { return S.q && S.q.ok && !S.busy && Object.keys(errs()).length === 0; }
  function syncButtons() {
    var ok = canBook();
    $('sbCash').disabled = !ok || !S.allowCod;
    $('sbOnline').disabled = !ok;
  }

  /* ------------------------------------------------------------ quote */
  var qTimer = null, qSeq = 0;
  function requote(ms) {
    clearTimeout(qTimer);
    S.idem = null;   // anything changed → a new booking intent
    qTimer = setTimeout(doQuote, ms == null ? 260 : ms);
  }
  function quoteBody() {
    return { action: 'quote', direction: S.dir, date: S.date, point: S.point, type: S.type, cabin: S.cabin, berth: S.berth,
      pax: S.pax.map(function (p) { return { gender: p.gender, band: p.band }; }),
      seats: S.seatMode === 'manual' ? S.manual : [], phone: digits(S.phone) };
  }
  function doQuote() {
    var my = ++qSeq;
    $('sbSeatLine').innerHTML = '<span class="sb-skel sb-skel-w"></span>';
    syncButtons();
    api(quoteBody())
      .then(function (q) {
        if (my !== qSeq) return;
        var flash = S.q && q.seats && S.q.seats && q.seats.join() !== S.q.seats.join();
        S.q = q;
        if (!S.date && q.date) { /* next departure chosen by the engine */ }
        renderDates(); renderQuote(flash);
        syncButtons();
      })
      .catch(function (e) {
        if (my !== qSeq) return;
        S.q = null;
        $('sbSeatLine').textContent = e && e.message ? e.message : '—';
        renderQuote(); syncButtons();
      });
  }
  function seatSummary(q) {
    if (!q || !q.seats || !q.seats.length) return '';
    var floors = q.floors || [];
    var allLower = floors.every(function (f) { return f === 'lower'; });
    var allUpper = floors.every(function (f) { return f === 'upper'; });
    return q.seatLabels.join(', ') + (allLower ? ' · ' + tr('lower') : (allUpper ? ' · ' + tr('upper') : ''));
  }
  function renderQuote(flash) {
    var q = S.q;
    var line = $('sbSeatLine');
    if (q) {
      if (q.ok) {
        var tag = q.auto ? tr('autoTag') : tr('yours');
        var why = q.reasons && q.reasons[0] ? L3(q.reasons[0]) : '';
        line.innerHTML = '<b class="' + (flash ? 'sb-flash' : '') + '">' + esc(seatSummary(q)) + '</b> · ' + esc(tag)
          + (why ? '<br><small>' + esc(why) + '</small>' : '');
      } else {
        line.innerHTML = '<span style="color:var(--sb-err)">' + esc(q.error || '—') + '</span>';
      }
      var sug = $('sbSuggest');
      if (q.suggest && S.type === 'sharing') {
        sug.hidden = false;
        sug.innerHTML = '<span>' + esc(L3(q.suggest)) + '</span><button type="button" data-sug="private">' + esc(tr('private') + ' ' + tr('cDouble')) + '</button>';
      } else { sug.hidden = true; sug.innerHTML = ''; }
      var alt = $('sbAlt');
      if (!q.ok && q.alternatives && q.alternatives.length) {
        alt.hidden = false;
        alt.innerHTML = q.alternatives.map(function (a, i) { return '<button type="button" data-alt="' + i + '">' + esc(L3(a)) + '</button>'; }).join('');
      } else { alt.hidden = true; alt.innerHTML = ''; }
    }
    // summary rail + phone bar
    var route = q ? (q.from + ' → ' + q.to) : '—';
    $('sbSRoute').textContent = route;
    $('sbSDate').textContent = q ? (q.dateLabel + (q.depTime ? ' · ' + q.depTime : '')) : '—';
    $('sbSBoard').textContent = q ? ((q.pointType === 'drop' ? q.point : q.boardingName + (q.boardingTime ? ' ' + q.boardingTime : '')) + (q.reportTime && q.pointType !== 'drop' ? ' (' + tr('report') + ' ' + q.reportTime + ')' : '')) : '—';
    $('sbSPax').textContent = tr('paxN', { n: S.pax.length }) + ' · ' + tr(S.type);
    $('sbSSeats').textContent = q && q.ok ? seatSummary(q) : '—';
    var tot = q && q.ok && q.price ? money(q.price.total) : '—';
    $('sbTotal').textContent = tot;
    $('sbNpr').textContent = q && q.ok && q.price ? '≈ ' + npr(q.price.npr) : '';
    $('sbBarTotal').textContent = tot;
    $('sbBarSeats').textContent = q && q.ok ? (seatSummary(q) + (q.price ? ' · ≈ ' + npr(q.price.npr) : '')) : (q && q.error ? q.error : tr('loading'));
  }

  /* ------------------------------------------------------------ events */
  function changed(field) { if (field) S.touched[field] = true; store.set(); showErrs(false); requote(); }
  root.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('.sb-dir-b'))) {
      var d = b.getAttribute('data-dir'); if (d === S.dir) return;
      S.dir = d; S.point = firstPoint(d); S.date = ''; S.manual = []; S.seatMode = 'auto'; S.why = {};
      fill(); applyWords(); changed(); return;
    }
    if ((b = e.target.closest('#sbDates .sb-chip'))) {
      var v = b.getAttribute('data-date');
      if (v === 'cal') { var inp = $('sbDateIn'); try { inp.showPicker(); } catch (x) { inp.focus(); inp.click(); } return; }
      S.date = v; S.manual = []; renderDates(); changed(); return;
    }
    if ((b = e.target.closest('#sbPax button'))) {
      var i = +b.getAttribute('data-i'), p = S.pax[i]; if (!p) return;
      if (b.getAttribute('data-act') === 'g') p.gender = p.gender === 'Female' ? 'Male' : (p.gender === 'Male' ? 'Female' : 'Female');
      else { var order = ['adult', 'child', 'senior', 'patient']; p.band = order[(order.indexOf(p.band) + 1) % order.length]; }
      S.manual = []; renderPax(); changed('pax'); return;
    }
    if ((b = e.target.closest('#sbMinus, #sbPlus'))) {
      if (b.id === 'sbPlus' && S.pax.length < S.maxPax) S.pax.push({ gender: S.pax.length ? S.pax[S.pax.length - 1].gender : '', band: 'adult' });
      if (b.id === 'sbMinus' && S.pax.length > 1) S.pax.pop();
      S.manual = []; renderPax(); changed(); return;
    }
    if ((b = e.target.closest('.sb-seg button'))) {
      var segId = b.parentNode.id, val = b.getAttribute('data-v');
      if (segId === 'sbType') { S.type = val; $('sbCabin').hidden = val !== 'private'; S.manual = []; }
      else if (segId === 'sbCabin') { S.cabin = val; S.manual = []; }
      else if (segId === 'sbBerth') { S.berth = val; }
      else if (segId === 'sbSeatMode') {
        if (val === 'manual') { openSheet(); return; }
        S.seatMode = 'auto'; S.manual = [];
      }
      seg(segId, val); changed(); return;
    }
    if ((b = e.target.closest('[data-sug]'))) { S.type = 'private'; S.cabin = 'double'; fill(); changed(); return; }
    if ((b = e.target.closest('[data-alt]'))) {
      var a = S.q && S.q.alternatives ? S.q.alternatives[+b.getAttribute('data-alt')] : null; if (!a) return;
      S.type = a.type || S.type; S.cabin = a.cabin || S.cabin; if (a.kind === 'nextDay') S.date = a.date;
      S.manual = []; S.seatMode = 'auto'; fill(); changed(); return;
    }
    if (e.target.closest('#sbNear')) { nearMe(); return; }
    if ((b = e.target.closest('#sbCash, #sbOnline'))) { book(b.id === 'sbCash' ? 'cash' : 'online'); return; }
  });
  $('sbPoint').addEventListener('change', function () { S.point = this.value; S.why.point = null; $('sbPointWhy').textContent = ''; S.manual = []; changed(); });
  $('sbDateIn').addEventListener('change', function () { if (this.value) { S.date = this.value; S.manual = []; renderDates(); changed(); } });
  $('sbCc').addEventListener('change', function () { S.cc = this.value; changed('phone'); });
  $('sbPhone').addEventListener('input', function () { S.phone = this.value; S.idem = null; store.set(); showErrs(false); syncButtons(); });
  $('sbPhone').addEventListener('blur', function () { S.touched.phone = true; showErrs(false); if (S.q) requote(); });
  $('sbName').addEventListener('input', function () { S.name = this.value; S.idem = null; store.set(); showErrs(false); syncButtons(); });
  $('sbName').addEventListener('blur', function () { S.touched.name = true; showErrs(false); });

  function nearMe() {
    if (!navigator.geolocation) { say(tr('geoNo')); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      var best = null;
      pointList().forEach(function (p) {
        if (p.lat == null || p.lng == null) return;
        var dLat = (p.lat - pos.coords.latitude) * Math.PI / 180, dLng = (p.lng - pos.coords.longitude) * Math.PI / 180;
        var a = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(pos.coords.latitude * Math.PI / 180) * Math.cos(p.lat * Math.PI / 180) * Math.pow(Math.sin(dLng / 2), 2);
        var km = 12742 * Math.asin(Math.min(1, Math.sqrt(a)));
        if (!best || km < best.km) best = { name: p.name, km: Math.round(km), short: p.short };
      });
      if (!best) { say(tr('geoNo')); return; }
      S.point = best.name; $('sbPoint').value = best.name;
      $('sbPointWhy').textContent = '📍 ' + (best.short || best.name) + ' · ~' + best.km + ' km';
      changed();
    }, function () { say(tr('geoNo')); }, { timeout: 6000, maximumAge: 600000 });
  }

  /* ----------------------------------------------------- QuickBot line */
  $('sbBot').addEventListener('submit', function (e) {
    e.preventDefault();
    var text = $('sbBotIn').value.trim(); if (!text) return;
    fillFromText(text);
  });
  /* One reader for the QuickBot line AND Sahayak's "Card ma herau" (§10): the
     server reads the text (TicketBot::parse), the card shows the result, and
     only the passenger's Cash / Online tap books. */
  function fillFromText(text) {
    return api({ action: 'parse', text: text }).then(function (f) {
      if (f.direction && f.direction !== S.dir) { S.dir = f.direction; S.point = firstPoint(S.dir); }
      if (f.point) S.point = f.point;
      if (f.date) S.date = f.date;
      if (f.phone) { S.phone = f.phone; if (f.country) S.cc = f.country; }
      if (f.name) S.name = f.name;
      if (f.pax && f.pax.length) S.pax = f.pax.slice(0, S.maxPax).map(function (p) { return { gender: p.gender || '', band: p.band || 'adult' }; });
      S.manual = []; S.seatMode = 'auto';
      fill(); applyWords(); store.set(); requote(0);
      $('sbBotIn').value = '';
      var missing = f.missing || [];
      if (missing.indexOf('gender') >= 0) { S.touched.pax = true; }
      if (missing.indexOf('phone') >= 0) { $('sbPhone').focus(); } else if (missing.indexOf('name') >= 0) { $('sbName').focus(); }
      showErrs(false);
    }).catch(function (er) { say(er.message); });
  }
  /* Sahayak (16-lazy.js) and old "Book" links: the card is the booking now. */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-sb-fill]');
    if (a) {
      e.preventDefault();
      var tx = a.getAttribute('data-sb-fill') || '';
      try { var cb = document.querySelector('.ai-close, #aiClose, [data-ai-close]'); if (cb) cb.click(); } catch (x) {}
      if (location.hash && location.hash !== '#/' && location.hash.indexOf('#/?') !== 0) location.hash = '#/';
      setTimeout(function () { root.scrollIntoView({ behavior: 'smooth', block: 'start' }); if (tx) fillFromText(tx); }, 120);
      return;
    }
    var s = e.target.closest('[data-scroll="search-anchor"]');
    if (s && document.documentElement.classList.contains('sb-on')) s.setAttribute('data-scroll', 'smartBook');
  }, true);

  /* ---------------------------------------------------- choose seats */
  var pick = [];
  function openSheet() {
    if (!S.q || !S.q.scheduleId) return;
    var need = S.type === 'private' ? (S.cabin === 'double' ? Math.ceil(S.pax.length / 2) : S.pax.length) : S.pax.length;
    pick = (S.manual.length ? S.manual : (S.q.seats || [])).slice(0, need);
    api({ action: 'seats', scheduleId: S.q.scheduleId, type: S.type }).then(function (m) {
      var sh = $('sbSheet'); sh.hidden = false; document.body.classList.add('shg-modal-open');
      drawMap(m, need);
      $('sbSheetOk').onclick = function () {
        if (pick.length !== need) { say(tr('pickAll')); return; }
        S.manual = pick.slice(); S.seatMode = 'manual'; seg('sbSeatMode', 'manual'); closeSheet(); changed();
      };
    }).catch(function (er) { say(er.message); });
  }
  function closeSheet() { $('sbSheet').hidden = true; document.body.classList.remove('shg-modal-open'); }
  $('sbSheetX').addEventListener('click', closeSheet);
  $('sbSheet').addEventListener('click', function (e) { if (e.target === this) closeSheet(); });
  function drawMap(m, need) {
    var free = {}, fem = {};
    (m.available || []).forEach(function (s) { free[s] = 1; });
    (m.female || []).forEach(function (s) { fem[s] = 1; });
    $('sbLegend').innerHTML = '<span><i></i>' + esc(tr('free')) + '</span><span><i style="background:var(--sb-women-bg);border-color:var(--sb-women)"></i>' + esc(tr('women'))
      + '</span><span><i style="background:var(--sb-line)"></i>' + esc(tr('taken')) + '</span><span><i style="background:var(--sb-primary)"></i>' + esc(tr('mine')) + '</span>';
    var h = '';
    (m.decks || []).forEach(function (dk) {
      h += '<div class="sb-deck"><h4>' + esc(dk.key === 'U' ? tr('floor2') : tr('floor1')) + '</h4>';
      dk.rows.forEach(function (r, ri) {
        h += '<div class="sb-mrow"><span class="sb-rl">' + esc(String.fromCharCode(65 + ri)) + '</span>';
        r.left.forEach(function (s) { h += cell(s); });
        h += '<span class="sb-aisle"></span>';
        r.right.forEach(function (s) { h += cell(s); });
        h += '</div>';
      });
      h += '</div>';
    });
    function cell(s) {
      var cls = 'sb-berth' + (fem[s] ? ' w' : '') + (!free[s] ? ' x' : '') + (pick.indexOf(s) >= 0 ? ' sel' : '');
      return '<button type="button" class="' + cls + '" data-seat="' + esc(s) + '"' + (!free[s] ? ' disabled aria-disabled="true"' : '') + ' aria-pressed="' + (pick.indexOf(s) >= 0) + '">' + esc((m.labels || {})[s] || s) + '</button>';
    }
    $('sbMap').innerHTML = h;
    $('sbPickN').textContent = tr('pickN', { a: pick.length, b: need });
    $('sbMap').onclick = function (e) {
      var b = e.target.closest('.sb-berth'); if (!b || b.disabled) return;
      var s = b.getAttribute('data-seat'), at = pick.indexOf(s);
      if (at >= 0) pick.splice(at, 1); else { if (pick.length >= need) pick.shift(); pick.push(s); }
      drawMap(m, need);
    };
  }

  /* -------------------------------------------------------------- book */
  function newKey() {
    var a = new Uint8Array(16);
    try { crypto.getRandomValues(a); } catch (e) { for (var i = 0; i < 16; i++) a[i] = Math.floor(Math.random() * 256); }
    return 'sb' + Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }
  function book(pay, wantPrint) {
    S.touched.book = S.touched.phone = S.touched.name = S.touched.pax = true;
    var e = showErrs(true);
    if (Object.keys(e).length) { var k = Object.keys(e)[0]; say(e[k]); if (k === 'phone') $('sbPhone').focus(); else if (k === 'name') $('sbName').focus(); return; }
    if (!S.q || !S.q.ok || S.busy) return;
    printNext = !!wantPrint;   // only a sale that really starts may print
    S.busy = true; S.idem = S.idem || newKey();
    var btn = $(pay === 'cash' ? 'sbCash' : 'sbOnline'); btn.classList.add('sb-busy');
    var lbl = btn.querySelector('span'); var old = lbl.textContent; lbl.textContent = tr('booking');
    var body = quoteBody();
    body.action = 'book'; body.pay = pay; body.idem = S.idem; body.name = S.name.trim(); body.phone = digits(S.phone); body.country = S.cc;
    body.seats = S.q.seats; body.autoPicked = !!S.q.auto;   // the shown berths are the berths sold (re-checked on the server)
    body.expect = { total: S.q.price ? S.q.price.total : null, date: S.q.date };
    store.set();
    api(body).then(function (r) {
      S.busy = false; btn.classList.remove('sb-busy'); lbl.textContent = old;
      adopt(r);
      S.idem = null;
      if (STAFF && r.commission) say('💰 Commission ' + money(r.commission));
      if (r.status === 'confirmed') {
        showTicket(r, !STAFF);
        if (STAFF && (printNext || MODE === 'counter' && staffPrint())) window.open('/admin/ticket-80mm.php?pnr=' + encodeURIComponent(r.pnr), '_blank', 'noopener,width=420,height=700');
        printNext = false;
        if (STAFF && typeof window.SB_AFTER === 'function') { try { window.SB_AFTER(r); } catch (x) {} }
      }
      else if (r.payPage && STAFF) { showPayLink(r); }
      else if (r.payPage) { say(tr('heldQr')); setTimeout(function () { location.href = r.payPage; }, 400); }
    }).catch(function (er) {
      S.busy = false; btn.classList.remove('sb-busy'); lbl.textContent = old;
      printNext = false;
      if (er && er.fields && er.fields.code === 'changed' && er.fields.quote) { S.q = er.fields.quote; renderDates(); renderQuote(true); say(tr('changed')); S.idem = null; }
      else say(er && er.message ? er.message : 'Error');
      // The server answered (a refusal): the next tap is a new request with a new key. A lost
      // answer (no status: timeout / offline) keeps the key, so the retry gets the same booking back.
      if (er && er.status) S.idem = null;
      syncButtons();
    });
  }
  var printNext = false;
  function staffPrint() { try { return localStorage.getItem('sb:print80') !== '0'; } catch (e) { return true; } }
  /* Agent + Online: the customer pays by QR on their own phone - the agent sends the link. */
  function showPayLink(r) {
    var url = location.origin + r.payPage;
    var text = 'S Hari Global — pay ' + (r.totalLabel || '') + ' for ' + r.pnr + ' (berths held ' + (r.expiresAt || '') + '): ' + url;
    var html = '<div class="sb-tk"><div class="sb-tk-h"><span class="sb-tk-ok" aria-hidden="true" style="background:var(--sb-gold)">⏳</span><div><b>' + esc(r.pnr) + '</b><small>' + esc(r.totalLabel || '') + '</small></div>'
      + '<button type="button" class="sb-x" data-tk="close" aria-label="Close" style="margin-left:auto">✕</button></div>'
      + '<div class="sb-tk-row">Send the payment link to the customer. The ticket is issued when the payment is verified.</div>'
      + '<div class="sb-tk-acts"><a class="sb-main-act" target="_blank" rel="noopener" href="https://wa.me/' + (r.phone ? (S.cc === 'NP' ? '977' : '91') + digits(r.phone) : '') + '?text=' + encodeURIComponent(text) + '">WhatsApp link</a>'
      + '<button type="button" data-copylink="' + esc(url) + '">Copy link</button><a href="' + esc(r.payPage) + '" target="_blank" rel="noopener">Open QR</a></div></div>';
    lastTicket = null;
    if (wide()) { var tk = $('sbTicket'); tk.innerHTML = html; tk.hidden = false; }
    else { $('sbModalIn').innerHTML = html; $('sbModal').hidden = false; }
  }
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copylink]'); if (!b) return;
    try { navigator.clipboard.writeText(b.getAttribute('data-copylink')).then(function () { say('Link copied'); }); } catch (x) {}
  });
  /* Counter keyboard: Ctrl+Enter = auto-berth + Cash + print · Enter in the name / passengers = one more passenger. */
  if (STAFF) {
    root.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); book('cash', true); return; }
      if (e.key === 'Enter' && (e.target.id === 'sbName' || e.target.closest('#sbPax'))) {
        e.preventDefault();
        if (S.pax.length < S.maxPax) { S.pax.push({ gender: S.pax.length ? S.pax[S.pax.length - 1].gender : '', band: 'adult' }); S.manual = []; renderPax(); changed(); }
        var last = $('sbPax').querySelector('.sb-p:last-child .sb-g'); if (last) last.focus();
      }
    });
  }
  /* A regular customer from the staff list: their number + their own history (staff may read it). */
  function loadCustomer(phone, name) {
    S.phone = String(phone || ''); S.name = String(name || S.name || '');
    api({ action: 'boot', phone: digits(S.phone), direction: '' }).then(function (d) {
      var df = d.defaults || {};
      S.why = df.why || {};
      if (df.direction) S.dir = df.direction;
      if (df.point) S.point = df.point;
      if (df.name && !name) S.name = df.name;
      if (df.pax && df.pax.length) S.pax = df.pax.map(function (p) { return { gender: p.gender || '', band: p.band || 'adult' }; });
      S.manual = []; S.seatMode = 'auto'; S.date = '';
      fill(); applyWords(); store.set(); requote(0);
    }).catch(function (er) { say(er.message); });
  }
  function adopt(r) {
    try {
      if (!r || !r.customer || typeof bookingFromServer !== 'function' || typeof DB === 'undefined') return;
      var b = bookingFromServer(r.customer, r.phone); if (!b) return;
      b.ownBrowser = true;
      var at = -1;
      for (var i = 0; i < DB.bookings.length; i++) { if (DB.bookings[i] && DB.bookings[i].id === b.id) { at = i; break; } }
      if (at >= 0) DB.bookings[at] = Object.assign({}, DB.bookings[at], b); else DB.bookings.unshift(b);
      if (typeof persist === 'function') persist('bookings');
    } catch (e) {}
  }

  /* ------------------------------------------------------------ ticket */
  var lastTicket = null;
  function ticketHtml(r) {
    var t = r.ticket || {};
    return '<div class="sb-tk">'
      + '<div class="sb-tk-h"><span class="sb-tk-ok" aria-hidden="true">✓</span><div><b>' + esc(tr('ticketOk')) + '</b><small>' + esc(r.route || '') + ' · ' + esc(r.dateLabel || '') + '</small></div>'
      + '<button type="button" class="sb-x" data-tk="close" aria-label="Close" style="margin-left:auto">✕</button></div>'
      + '<div class="sb-tk-pnr">' + esc(tr('pnr')) + ' ' + esc(r.pnr) + '</div>'
      + (t.view ? '<img class="sb-tk-img" src="' + esc(t.view) + '" alt="Ticket ' + esc(r.pnr) + '" loading="eager" decoding="async">' : '')
      + '<div class="sb-tk-row">' + esc((r.seatLabels || []).join(', ')) + ' · ' + esc(r.totalLabel || '') + ' <small>(≈ ' + esc(npr(r.npr)) + ')</small></div>'
      + (r.boardingTime ? '<div class="sb-tk-row">' + esc(r.boarding || '') + ' · <small>' + esc(tr('report')) + ' ' + esc(r.reportTime || '') + '</small></div>' : '')
      + (r.isCod ? '<div class="sb-tk-cod">💵 ' + esc(tr('codNote')) + ' — ' + esc(r.totalLabel || '') + '</div>' : '')
      + '<div class="sb-tk-acts">'
      + (t.pdf ? '<a class="sb-main-act" href="' + esc(t.pdf) + '" download>' + esc(tr('dlPdf')) + '</a>' : '')
      + (t.png ? '<a href="' + esc(t.png) + '" download>' + esc(tr('dlPng')) + '</a>' : '')
      + '<button type="button" data-tk="share">' + esc(tr('share')) + '</button>'
      + (t.print ? '<a href="' + esc(t.print) + '" target="_blank" rel="noopener">' + esc(tr('print')) + '</a>' : '')
      + '<button type="button" data-tk="copy">' + esc(tr('copy')) + '</button>'
      + '<a href="#/trip/' + encodeURIComponent(r.pnr) + '">' + esc(tr('track')) + '</a>'
      + '</div><button type="button" class="sb-tk-new" data-tk="new">＋ ' + esc(tr('newB')) + '</button></div>';
  }
  function wide() { return window.matchMedia && window.matchMedia('(min-width: 860px)').matches; }
  function showTicket(r, fresh) {
    lastTicket = r;
    var html = ticketHtml(r);
    if (wide()) { var tk = $('sbTicket'); tk.innerHTML = html; tk.hidden = false; tk.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    else { $('sbModalIn').innerHTML = html; $('sbModal').hidden = false; document.body.classList.add('shg-modal-open'); }
    try { if (!STAFF && location.hash.indexOf('#/?ticket=') !== 0) history.replaceState(null, '', '#/?ticket=' + encodeURIComponent(r.pnr)); } catch (e) {}
    if (fresh && r.ticket && r.ticket.pdf) {
      // the PDF downloads by itself (§7); the buttons stay for PNG / share / print
      setTimeout(function () { var a = document.createElement('a'); a.href = r.ticket.pdf; a.download = ''; a.rel = 'noopener'; document.body.appendChild(a); a.click(); a.remove(); }, 600);
    }
  }
  function closeTicket() {
    $('sbModal').hidden = true; document.body.classList.remove('shg-modal-open');
    $('sbTicket').hidden = true;
    try { if (location.hash.indexOf('#/?ticket=') === 0) history.replaceState(null, '', '#/'); } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tk]'); if (!b || !root.contains(b)) return;
    var r = lastTicket; var act = b.getAttribute('data-tk');
    if (act === 'close') { closeTicket(); return; }
    if (act === 'new') { closeTicket(); S.manual = []; S.seatMode = 'auto'; S.date = ''; fill(); requote(0); window.scrollTo({ top: root.offsetTop - 60, behavior: 'smooth' }); return; }
    if (!r) return;
    if (act === 'copy') { try { navigator.clipboard.writeText(r.pnr).then(function () { say(tr('copied')); }); } catch (x) { say(r.pnr); } return; }
    if (act === 'share') {
      var url = location.origin + (r.ticket && r.ticket.view ? r.ticket.view : '/#/ticket/' + r.pnr);
      var text = 'S Hari Global ticket ' + r.pnr + ' · ' + (r.route || '') + ' · ' + (r.dateLabel || '') + ' · ' + (r.seatLabels || []).join(', ');
      var shareFile = function () {
        if (!(r.ticket && r.ticket.png && navigator.canShare)) return Promise.reject();
        return fetch(r.ticket.png, { credentials: 'same-origin' }).then(function (x) { return x.blob(); }).then(function (blob) {
          var f = new File([blob], r.pnr + '.png', { type: blob.type || 'image/png' });
          if (!navigator.canShare({ files: [f] })) throw new Error('no');
          return navigator.share({ files: [f], title: r.pnr, text: text });
        });
      };
      shareFile().catch(function () {
        if (navigator.share) return navigator.share({ title: r.pnr, text: text, url: url }).catch(function () {});
        window.open('https://wa.me/?text=' + encodeURIComponent(text + ' ' + url), '_blank', 'noopener');
      });
    }
  });
  $('sbModal').addEventListener('click', function (e) { if (e.target === this) closeTicket(); });

  /* /#/?ticket=PNR opens the ticket on the home page (§7) */
  function ticketFromHash() {
    var m = /^#\/\?ticket=([A-Za-z0-9-]+)/.exec(location.hash || '');
    if (!m) return;
    var pnr = decodeURIComponent(m[1]);
    if (lastTicket && lastTicket.pnr === pnr) { showTicket(lastTicket, false); return; }
    var b = (typeof DB !== 'undefined' && DB.bookings) ? DB.bookings.find(function (x) { return x && x.id === pnr; }) : null;
    if (b && b.dlKey) {
      var k = '&k=' + encodeURIComponent(b.dlKey);
      showTicket({ pnr: pnr, route: (b.from || '') + ' → ' + (b.to || ''), dateLabel: b.date || '', seatLabels: b.seats || [], totalLabel: money(b.total || 0), npr: 0,
        isCod: !!b.codFlag, ticket: { view: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&img=1&view=1', png: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&img=1',
          pdf: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k, print: '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + k + '&print=1' } }, false);
    } else {
      location.hash = '#/ticket/' + encodeURIComponent(pnr);
    }
  }

  /* --------------------------------------------- page-level wiring */
  function retargetBookLinks() {
    document.querySelectorAll('[data-scroll="search-anchor"]').forEach(function (a) { a.setAttribute('data-scroll', 'smartBook'); });
  }
  function homeFlag() {
    var home = document.getElementById('view-home');
    document.documentElement.classList.toggle('sb-home', !home || home.classList.contains('active'));
  }
  window.addEventListener('hashchange', function () { setTimeout(function () { homeFlag(); ticketFromHash(); }, 0); });
  // While the card is on screen the floating WhatsApp / Sahayak buttons step aside (phone).
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) {
      document.documentElement.classList.toggle('sb-in-view', !!(en[0] && en[0].isIntersecting));
    }, { threshold: 0.25 }).observe($('sbCard'));
  }
  new MutationObserver(function () { applyWords(); }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
  /* Staff: clear the card for the next customer after a sale (keeps the date / pickup / type). */
  function resetForNext() {
    S.phone = ''; S.name = ''; S.pax = [{ gender: '', band: 'adult' }]; S.manual = []; S.seatMode = 'auto'; S.idem = null; S.touched = {};
    S.why = {}; fill(); applyWords(); store.set(); showErrs(false); requote(0);
  }
  window.SmartBook = { quote: function () { return S.q; }, state: S, requote: requote, loadCustomer: loadCustomer, fillText: fillFromText, resetForNext: resetForNext, mode: MODE };

  boot();
  homeFlag();
  setTimeout(ticketFromHash, 300);
})();
