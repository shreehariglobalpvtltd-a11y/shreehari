/* 22-routeline.js — 9 Oct 2026: the route line (owner asks #11 + #12:
   "kati km, kati thau rokinchha, khana-nasta kaha, ke bokne").
   Owner: ROUTE work package.

   window.shgRouteLine — auto-mounts every [data-routeline] element
   ("home", "trip") on boot and whenever #view-home becomes active, and
   exposes render(el, {dir: 'toNepal'|'toIndia', compact: true}).

   What it draws (Pixel / Material-3 style, light / dark / AMOLED via tokens):
     - a direction switch: Nepal जाने / Gujarat फर्कने (with SVG flags)
     - totals: road km (Surat → Rupaidiha and Ahmedabad → Rupaidiha), stops,
       halts, journey time (≈, from the timetable)
     - the next departure with a live countdown, today or tomorrow — always
       labelled "as per timetable": the home page shows NO public GPS (live
       position stays on the keyed track.php / Trip Companion)
     - a km strip (horizontal overview) and the vertical rail: origin →
       pickups (time, km) → halts (≈ time, duration) → Rupaidiha border →
       Nepalgunj across the border
     - "What to carry" from SHG_BOOT.settings.travel_checklist

   Data: /api/timetable.php (distanceKm, spans, stopCount, haltCount,
   durationMin, halts[], line[]). If the API cannot be reached, a small
   built-in copy of the measured corridor is drawn and marked "offline copy".
   Never a price, never a phone number, never a position. */
(function () {
  'use strict';
  var D = document, ROOT = D.documentElement;
  if (window.shgRouteLine) return;

  /* ---------------------------------------------------------- strings */
  var S = {
    ne: {
      go: 'नेपाल जाने', back: 'गुजरात फर्कने', km: 'किमी', stops: 'स्टप', halts: 'बिसौनी', journey: 'यात्रा समय',
      hours: 'घण्टा', hoursLong: 'घण्टा', mins: 'मिनेट', approx: '≈', next: 'अर्को बस', from: 'बाट', leavesIn: 'छुट्न बाँकी',
      today: 'आज', tomorrow: 'भोलि', tt: 'समय तालिका अनुसार', ttEn: 'as per timetable', noBus: 'आजको समय तालिका उपलब्ध छैन',
      border: 'भारत–नेपाल सीमा', across: 'सीमापारि नेपालगन्ज (Nepalgunj), नेपाल', start: 'सुरु', end: 'अन्तिम',
      more: 'सबै %d पिकअप हेर्नुहोस्', moreDrop: 'सबै %d ओर्लने ठाउँ हेर्नुहोस्', less: 'कम देखाउनुहोस्', other: 'अन्य पिकअप', board: 'चढ्ने', drop: 'ओर्लने',
      near: '%s नजिक', haltFor: '%d मिनेट', carry: 'यात्रामा के बोक्ने?', carrySub: 'टिक लगाउँदै तयारी गर्नुहोस्',
      approxNote: '≈ अनुमानित: खाना/चियाको ठाउँ चालक दलले छान्छ, ट्राफिकले समय फरक पर्न सक्छ।',
      offline: 'अफलाइन प्रति — इन्टरनेट आएपछि ताजा हुन्छ', arrival: 'पुग्ने समय पक्का हुँदैछ', viaAmd: 'अहमदाबाद हुँदै',
      dep: 'प्रस्थान', starts: 'बस छुट्छ', hk: { breakfast: 'नास्ता', lunch: 'दिउँसोको खाना', dinner: 'बेलुकाको खाना', meal: 'खाना', tea: 'चिया ब्रेक', fuel: 'इन्धन', rest: 'विश्राम', border: 'सीमा' },
      dirAria: 'यात्राको दिशा', carryDone: '%1$d मध्ये %2$d तयार'
    },
    hi: {
      go: 'नेपाल जाने', back: 'गुजरात लौटने', km: 'किमी', stops: 'स्टॉप', halts: 'पड़ाव', journey: 'यात्रा समय',
      hours: 'घंटे', hoursLong: 'घंटे', mins: 'मिनट', approx: '≈', next: 'अगली बस', from: 'से', leavesIn: 'रवाना होने में',
      today: 'आज', tomorrow: 'कल', tt: 'समय-सारणी के अनुसार', ttEn: 'as per timetable', noBus: 'आज की समय-सारणी उपलब्ध नहीं',
      border: 'भारत–नेपाल सीमा', across: 'सीमा पार नेपालगंज (Nepalgunj), नेपाल', start: 'शुरू', end: 'अंतिम',
      more: 'सभी %d पिकअप देखें', moreDrop: 'सभी %d उतरने की जगह देखें', less: 'कम दिखाएँ', other: 'अन्य पिकअप', board: 'चढ़ें', drop: 'उतरें',
      near: '%s के पास', haltFor: '%d मिनट', carry: 'यात्रा में क्या साथ रखें?', carrySub: 'टिक करते हुए तैयारी करें',
      approxNote: '≈ अनुमानित: खाने/चाय की जगह चालक दल चुनता है, ट्रैफ़िक से समय बदल सकता है।',
      offline: 'ऑफ़लाइन कॉपी — इंटरनेट आने पर ताज़ा होगी', arrival: 'पहुँचने का समय पक्का हो रहा है', viaAmd: 'अहमदाबाद होकर',
      dep: 'प्रस्थान', starts: 'बस रवाना', hk: { breakfast: 'नाश्ता', lunch: 'दोपहर का खाना', dinner: 'रात का खाना', meal: 'खाना', tea: 'चाय ब्रेक', fuel: 'ईंधन', rest: 'आराम', border: 'सीमा' },
      dirAria: 'यात्रा की दिशा', carryDone: '%1$d में से %2$d तैयार'
    },
    en: {
      go: 'To Nepal', back: 'To Gujarat', km: 'km', stops: 'stops', halts: 'halts', journey: 'Journey time',
      hours: 'h', hoursLong: 'hours', mins: 'min', approx: '≈', next: 'Next bus', from: 'from', leavesIn: 'Leaves in',
      today: 'Today', tomorrow: 'Tomorrow', tt: 'As per timetable', ttEn: '', noBus: 'No timetable for today yet',
      border: 'India–Nepal border', across: 'Nepalgunj is just across the border, in Nepal', start: 'Start', end: 'End',
      more: 'Show all %d pickups', moreDrop: 'Show all %d drop points', less: 'Show fewer', other: 'Other pickups', board: 'Board', drop: 'Drop',
      near: 'near %s', haltFor: '%d min', carry: 'What to carry', carrySub: 'Tick them off as you pack',
      approxNote: '≈ approximate: the crew picks the exact dhaba / hotel and traffic changes the times.',
      offline: 'Offline copy — refreshes when you are back online', arrival: 'Arrival time being confirmed', viaAmd: 'via Ahmedabad',
      dep: 'Departs', starts: 'Bus leaves', hk: { breakfast: 'Breakfast', lunch: 'Lunch', dinner: 'Dinner', meal: 'Meal', tea: 'Tea break', fuel: 'Fuel', rest: 'Rest', border: 'Border' },
      dirAria: 'Direction of travel', carryDone: '%2$d of %1$d ready'
    }
  };
  /* The seeded checklist (database/seed.sql) in all three languages; an item the office typed itself is shown as typed. */
  var CARRY = {
    'Original government photo ID (passport / voter ID / citizenship card)': { ne: 'सक्कली सरकारी परिचयपत्र (राहदानी / मतदाता परिचयपत्र / नागरिकता)', hi: 'असली सरकारी पहचान पत्र (पासपोर्ट / वोटर आईडी / नागरिकता प्रमाणपत्र)' },
    'Printed or downloaded PDF ticket (save it before you lose signal)': { ne: 'प्रिन्ट गरेको वा डाउनलोड गरेको PDF टिकट (नेटवर्क जानुअघि सेभ गर्नुहोस्)', hi: 'प्रिंट या डाउनलोड किया PDF टिकट (नेटवर्क जाने से पहले सेव करें)' },
    'Phone + charger / power bank': { ne: 'फोन + चार्जर / पावर बैंक', hi: 'फ़ोन + चार्जर / पावर बैंक' },
    'Water & light snacks for the border halt': { ne: 'सीमामा रोकिँदाका लागि पानी र हल्का खाजा', hi: 'सीमा पर रुकने के लिए पानी और हल्का नाश्ता' },
    'INR and NPR cash for small expenses': { ne: 'साना खर्चका लागि भारतीय र नेपाली नगद', hi: 'छोटे खर्चों के लिए भारतीय और नेपाली नकद' }
  };
  var HALT_ICON = { breakfast: '🍳', lunch: '🍛', dinner: '🍽️', meal: '🍽️', tea: '☕', fuel: '⛽', rest: '🛏️', border: '🛂' };

  /* Offline copy of the measured corridor (Valhalla, bus costing, 9 Oct 2026). */
  var FALLBACK = {
    toNepal: { from: 'Surat', to: 'Rupaidiha', depTime: '13:00', distanceKm: 1688, spans: [{ from: 'Surat', to: 'Rupaidiha', km: 1688 }, { from: 'Ahmedabad', to: 'Rupaidiha', km: 1372 }],
      line: [['stop', 'board', 'Surat', '13:00', 0], ['stop', 'board', 'Kamrej', '13:30', 27], ['stop', 'board', 'Ankleshwar', '15:00', 70], ['stop', 'board', 'Bharuch', '16:00', 85],
        ['stop', 'board', 'Vadodara', '17:00', 173], ['stop', 'board', 'Anand', '18:30', 219], ['stop', 'board', 'Nadiad', '20:00', 241], ['stop', 'board', 'Nana Chiloda', '23:00', 316],
        ['halt', 'tea', 'Udaipur', '03:00', 558, 15, 1], ['halt', 'breakfast', 'Kota', '08:00', 841, 30, 1], ['halt', 'lunch', 'Jhansi', '13:30', 1164, 40, 1],
        ['halt', 'tea', 'Kanpur', '17:30', 1393, 15, 1], ['halt', 'dinner', 'Lucknow', '20:00', 1483, 30, 1], ['stop', 'drop', 'Rupaidiha', '', 1688]] },
    toIndia: { from: 'Rupaidiha', to: 'Surat', depTime: '18:00', distanceKm: 1688, spans: [{ from: 'Rupaidiha', to: 'Surat', km: 1688 }, { from: 'Rupaidiha', to: 'Ahmedabad', km: 1372 }],
      line: [['stop', 'board', 'Rupaidiha', '18:00', 0], ['halt', 'dinner', 'Lucknow', '21:30', 205, 30, 0], ['halt', 'tea', 'Jhansi', '03:00', 524, 15, 1],
        ['halt', 'breakfast', 'Kota', '08:30', 847, 30, 1], ['halt', 'lunch', 'Udaipur', '13:30', 1130, 40, 1], ['stop', 'drop', 'Nana Chiloda', '', 1372],
        ['stop', 'drop', 'Nadiad', '', 1447], ['stop', 'drop', 'Anand', '', 1469], ['stop', 'drop', 'Vadodara', '', 1515], ['stop', 'drop', 'Bharuch', '', 1603],
        ['stop', 'drop', 'Ankleshwar', '', 1618], ['stop', 'drop', 'Kamrej', '', 1661], ['stop', 'drop', 'Surat', '', 1688]] }
  };

  /* ---------------------------------------------------------- helpers */
  function lang() {
    var l = '';
    try { if (typeof LANG === 'string') l = LANG; } catch (e) { /* not loaded */ }
    l = l || ROOT.getAttribute('lang') || 'ne';
    return S[l] ? l : 'en';
  }
  function L() { return S[lang()]; }
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function fill(s, a) {
    return String(s).replace(/%(\d)\$d/g, function (m, n) { return a[+n - 1]; }).replace(/%[ds]/, function () { return a[0]; });
  }
  function kmf(v) { return Math.round(+v || 0).toLocaleString('en-IN'); }
  function feel(n) { try { if (window.SHGFeel && typeof window.SHGFeel.play === 'function') window.SHGFeel.play(n); } catch (e) { /* silent */ } }
  function reduced() {
    if (ROOT.getAttribute('data-motion') === 'reduced') return true;
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
  }
  var FLAG_SVG = {
    IN: '<svg class="rl-flag" viewBox="0 0 30 20" aria-hidden="true"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.7" fill="#FF9933"/><rect y="13.3" width="30" height="6.7" fill="#138808"/><circle cx="15" cy="10" r="2.8" fill="none" stroke="#000080" stroke-width=".9"/><circle cx="15" cy="10" r=".7" fill="#000080"/></svg>',
    NP: '<svg class="rl-flag rl-flag-np" viewBox="-1.2 -1.2 22.4 29.4" aria-hidden="true"><path d="M0 0L20 12.5H6L20 27H0Z" fill="#DC143C" stroke="#003893" stroke-width="1.8" stroke-linejoin="round"/><path d="M2.3 7.2a3.7 3.7 0 0 0 7.4 0a3.7 2.6 0 0 1-7.4 0z" fill="#fff"/><circle cx="6" cy="8.7" r="1.3" fill="#fff"/><circle cx="6" cy="20" r="3" fill="#fff"/></svg>'
  };
  function flag(cc) {
    try { if (typeof window.shgFlag === 'function') { var s = window.shgFlag(cc, 14); if (s) return '<span class="rl-flagw">' + s + '</span>'; } } catch (e) { /* own copy */ }
    return '<span class="rl-flagw">' + FLAG_SVG[cc] + '</span>';
  }
  function isNepal(name) { return /rupaidiha|nepalgunj|nepal/i.test(String(name || '')); }
  function hmMin(t) { var m = /^(\d{1,2}):(\d{2})/.exec(String(t || '')); return m ? (+m[1]) * 60 + (+m[2]) : null; }
  function durText(min) {
    if (min == null || !(min > 0)) return '';
    var s = L(), h = Math.round(min / 30) / 2, whole = Math.floor(h);
    return s.approx + ' ' + whole + (h - whole ? '½' : '');
  }
  /* Today's date in IST and the epoch ms of an IST wall-clock moment. */
  var IST = 330 * 60000;
  function istToday(add) {
    var d = new Date(Date.now() + IST + (add || 0) * 86400000);
    return d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2);
  }
  function istMs(date, hm) {
    var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date), m = hmMin(hm);
    if (!p || m == null) return null;
    return Date.UTC(+p[1], +p[2] - 1, +p[3], 0, m) - IST;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  /* ---------------------------------------------------------- data */
  var CACHE = {};
  function getTT(date) {
    var k = date || '';
    if (CACHE[k] && Date.now() - CACHE[k].at < 60000) return CACHE[k].p;
    var p;
    if (typeof window.shgTimetableGet === 'function') {
      p = Promise.resolve().then(function () { return window.shgTimetableGet(k); });
    } else {
      p = fetch('/api/timetable.php' + (k ? '?date=' + encodeURIComponent(k) : ''), { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }
    p = p.then(function (j) {
      var d = j && j.routes ? j : (j && j.data) || {};
      if (!d.routes) throw new Error('no routes');
      return d;
    });
    p.catch(function () { delete CACHE[k]; });
    CACHE[k] = { p: p, at: Date.now() };
    return p;
  }
  function pickRoute(data, dir) {
    var list = (data && data.routes) || [];
    var want = list.filter(function (r) {
      var out = isNepal(r.to) && !isNepal(r.from), inn = isNepal(r.from) && !isNepal(r.to);
      return dir === 'toNepal' ? out : inn;
    });
    return want.filter(function (r) { return r.sellable; })[0] || want[0] || null;
  }
  /* The route in one shape whether it came from the API or the offline copy. */
  function model(route, dir, offline) {
    if (offline) {
      var f = FALLBACK[dir];
      return {
        offline: true, from: f.from, to: f.to, depTime: f.depTime, distanceKm: f.distanceKm, spans: f.spans,
        durationMin: null, halts: 5, stops: f.line.filter(function (x) { return x[0] === 'stop'; }).length,
        line: f.line.map(function (x) {
          return x[0] === 'halt'
            ? { kind: 'halt', role: 'halt', halt: x[1], name: x[2], time: x[3], km: x[4], haltMin: x[5], dayPlus: x[6], approx: true }
            : { kind: 'stop', role: x[1], name: x[2], time: x[3], km: x[4], border: /rupaidiha/i.test(x[2]) };
        })
      };
    }
    var line = Array.isArray(route.line) ? route.line.slice() : [];
    if (!line.length) {
      (route.boarding || []).forEach(function (b) { line.push({ kind: 'stop', role: 'board', name: b.name, time: b.time, km: b.km, border: b.isBorder, landmark: b.landmark }); });
      (route.halts || []).forEach(function (h) { line.push({ kind: 'halt', role: 'halt', halt: h.kind, name: h.name, time: h.timeText || h.time, km: h.km, haltMin: h.haltMin, approx: h.approx, dayPlus: h.dayPlus }); });
      (route.drop || []).forEach(function (b) { line.push({ kind: 'stop', role: 'drop', name: b.name, time: '', km: b.km, border: b.isBorder, landmark: b.landmark }); });
    }
    return {
      offline: false, from: route.from, to: route.to, depTime: route.depTime, distanceKm: route.distanceKm,
      spans: route.spans || [], durationMin: route.durationMin, durationApprox: route.durationApprox !== false,
      arrTime: route.arrTime || '', sellable: !!route.sellable,
      stops: route.stopCount != null ? route.stopCount : line.filter(function (x) { return x.kind === 'stop'; }).length,
      halts: route.haltCount != null ? route.haltCount : line.filter(function (x) { return x.kind === 'halt'; }).length,
      line: line
    };
  }

  /* ---------------------------------------------------------- CSS */
  function css() {
    if (D.getElementById('shg-routeline-css')) return;
    var st = D.createElement('style');
    st.id = 'shg-routeline-css';
    st.textContent = [
      '.rl{--rl-pri:var(--md-primary,var(--royal,#1F4FA3));--rl-on-pri:var(--md-on-primary,var(--on-royal,#fff));--rl-pc:var(--md-primary-container,var(--royal-soft,#E3ECFB));--rl-on-pc:var(--md-on-primary-container,var(--royal-strong,#15377A));',
      '--rl-ter:var(--md-tertiary,var(--orange,#F07C1F));--rl-tc:var(--md-tertiary-container,var(--orange-soft,#FDEBD9));--rl-s0:var(--md-surface-container-lowest,var(--surface,#fff));--rl-s1:var(--md-surface-container-low,var(--surface,#fff));',
      '--rl-s2:var(--md-surface-container,var(--surface-sunken,#F3F6FB));--rl-s3:var(--md-surface-container-high,var(--surface-hover,#EAF0F8));--rl-ink:var(--md-on-surface,var(--ink,#14213D));--rl-ink2:var(--md-on-surface-variant,var(--ink-2,var(--muted,#56657F)));',
      '--rl-line:var(--md-outline-variant,var(--line,#E2E9F4));--rl-ok:var(--success,#15803D);--rl-r:var(--md-r-card,24px);--rl-ease:var(--md-ease-emph-decel,cubic-bezier(.05,.7,.1,1));color:var(--rl-ink);font-variant-numeric:tabular-nums}',
      '.rl *{box-sizing:border-box}',
      '.rl-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin:0 0 12px}',
      '.rl-seg{display:grid;grid-template-columns:1fr 1fr;gap:4px;padding:4px;border-radius:999px;background:var(--rl-s2);width:100%;max-width:440px}',
      '.rl-seg button{all:unset;box-sizing:border-box;min-height:44px;padding:8px 12px;border-radius:999px;display:flex;align-items:center;justify-content:center;gap:7px;font-weight:800;font-size:14px;color:var(--rl-ink2);cursor:pointer;text-align:center;line-height:1.15;-webkit-tap-highlight-color:transparent}',
      '.rl-seg button[aria-pressed="true"]{background:var(--rl-pri);color:var(--rl-on-pri);box-shadow:0 2px 8px rgba(0,0,0,.16)}',
      '.rl-seg button:focus-visible{outline:3px solid var(--rl-ter);outline-offset:2px}',
      '.rl-flagw{display:inline-flex;align-items:center;flex:none}.rl-flagw svg{height:14px;width:auto;border-radius:2px;display:block}.rl-flag{height:14px;width:21px}.rl-flag-np{width:11px}',
      '.rl-flagw .rl-flag:not(.rl-flag-np){box-shadow:0 0 0 1px rgba(0,0,0,.14)}',
      '.rl-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 10px}',
      '.rl-stat{background:var(--rl-s2);border-radius:20px;padding:12px 10px;min-width:0}',
      '.rl-stat b{display:block;font-size:19px;line-height:1.15;font-weight:800;color:var(--rl-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
      '.rl-stat small{display:block;font-size:11.5px;font-weight:700;color:var(--rl-ink2);margin-top:2px}',
      '.rl-stat.rl-acc{background:var(--rl-pc)}.rl-stat.rl-acc b,.rl-stat.rl-acc small{color:var(--rl-on-pc)}',
      '.rl-spans{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}',
      '.rl-span{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:6px 11px;border-radius:999px;background:var(--rl-s1);border:1px solid var(--rl-line);color:var(--rl-ink)}',
      '.rl-nrow{display:flex;align-items:center;gap:12px;position:relative;z-index:1}.rl-nt{min-width:0}',
      '.rl-next{position:relative;overflow:hidden;display:block;padding:14px 16px;border-radius:var(--rl-r);margin:0 0 14px;color:#fff;background:linear-gradient(135deg,var(--navy,#12264E),var(--rl-pri))}',
      '.rl-next::after{content:"";position:absolute;right:-30px;top:-40px;width:130px;height:130px;border-radius:50%;border:22px solid rgba(255,255,255,.07);pointer-events:none}',
      '.rl-next .rl-bus{flex:none;width:46px;height:46px;border-radius:16px;display:grid;place-items:center;font-size:24px;background:rgba(255,255,255,.14)}',
      '.rl-next small{display:block;font-size:12px;font-weight:700;opacity:.86}',
      '.rl-next b{display:block;font-size:15px;font-weight:800;line-height:1.25}',
      '.rl-cd{margin-left:auto;text-align:right;flex:none}',
      '.rl-cd b{font-size:22px;letter-spacing:.01em}',
      '.rl-tt{position:relative;z-index:1;display:inline-block;margin-top:10px;font-size:11px;font-weight:800;padding:3px 10px;border-radius:999px;border:1px dashed rgba(255,255,255,.6)}',
      '.rl-strip{position:relative;height:46px;margin:2px 10px 14px}',
      '.rl-strip .rl-track{position:absolute;left:0;right:0;top:27px;height:6px;border-radius:6px;background:linear-gradient(90deg,var(--rl-pri),var(--rl-ter))}',
      '.rl-strip .rl-tk{position:absolute;top:24px;width:12px;height:12px;margin-left:-6px;border-radius:50%;background:var(--rl-s0);border:3px solid var(--rl-pri)}',
      '.rl-strip .rl-tk.rl-h{border-color:var(--rl-ter);width:10px;height:10px;margin-left:-5px;top:25px}',
      '.rl-strip .rl-em{position:absolute;top:0;transform:translateX(-50%);font-size:14px;line-height:1}',
      '.rl-strip .rl-lab{position:absolute;top:40px;font-size:10.5px;font-weight:800;color:var(--rl-ink2);white-space:nowrap}',
      '.rl-rail{list-style:none;margin:0;padding:0}',
      '.rl-row{display:grid;grid-template-columns:54px 22px minmax(0,1fr);column-gap:8px;position:relative}',
      '.rl-row time{font-size:13px;font-weight:800;text-align:right;white-space:nowrap;padding-top:1px;color:var(--rl-ink)}',
      '.rl-row time sup{font-size:9px;color:var(--rl-ter);margin-left:1px}',
      '.rl-row time.rl-dim{color:var(--rl-ink2);font-weight:700}',
      '.rl-dot{position:relative}',
      '.rl-dot::before{content:"";position:absolute;left:9.5px;top:0;bottom:0;width:3px;border-radius:3px;background:var(--rl-line)}',
      '.rl-row.rl-on .rl-dot::before{background:var(--rl-pri)}.rl-row.rl-hr .rl-dot::before{background:var(--rl-ter)}',
      '.rl-dot::after{content:"";position:absolute;left:4px;top:3px;width:14px;height:14px;border-radius:50%;background:var(--rl-s0);border:3px solid var(--rl-pri);box-sizing:border-box}',
      '.rl-row:first-child .rl-dot::before{top:10px}.rl-row:last-child .rl-dot::before{bottom:auto;height:10px}',
      '.rl-row.rl-end .rl-dot::after,.rl-row.rl-origin .rl-dot::after{width:18px;height:18px;left:2px;top:1px;background:var(--rl-pri);box-shadow:0 0 0 4px var(--rl-pc)}',
      '.rl-row.rl-end .rl-dot::after{background:var(--rl-ter);border-color:var(--rl-ter);box-shadow:0 0 0 4px var(--rl-tc)}',
      '.rl-row.rl-halt .rl-dot::after{left:6px;top:5px;width:10px;height:10px;border-width:2px;border-color:var(--rl-ter)}',
      '.rl-row .rl-body{padding:0 0 14px;min-width:0}',
      '.rl-row .rl-nm{font-size:15px;font-weight:800;line-height:1.3;display:flex;flex-wrap:wrap;align-items:center;gap:6px}',
      '.rl-row .rl-sub{display:block;font-size:12px;color:var(--rl-ink2);margin-top:1px}',
      '.rl-kmc{font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:var(--rl-s2);color:var(--rl-ink2)}',
      '.rl-tag{font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px;background:var(--rl-pc);color:var(--rl-on-pc)}',
      '.rl-hc{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:18px;background:var(--rl-tc);color:var(--rl-ink);font-size:13px;font-weight:600;line-height:1.35;max-width:100%}',
      '.rl-hc i{font-style:normal;font-size:17px;flex:none;width:32px;height:32px;border-radius:11px;display:grid;place-items:center;background:var(--rl-s0)}',
      '.rl-hct{min-width:0}.rl-hct b{font-weight:800}',
      '.rl-hcm{display:flex;flex-wrap:wrap;gap:6px;margin-top:5px}',
      '.rl-hc em{font-style:normal;font-weight:800;font-size:11.5px;padding:2px 8px;border-radius:999px;background:var(--rl-s0);color:var(--rl-ink)}',
      '.rl-more{all:unset;box-sizing:border-box;display:flex;align-items:center;gap:6px;min-height:44px;padding:0 14px;margin:0 0 12px 84px;border-radius:999px;font-size:13px;font-weight:800;color:var(--rl-pri);background:var(--rl-s2);cursor:pointer}',
      '.rl-more:focus-visible{outline:3px solid var(--rl-ter);outline-offset:2px}',
      '.rl-other{font-size:12.5px;color:var(--rl-ink2);margin:-4px 0 10px 84px}',
      '.rl-across{display:grid;gap:8px;margin:2px 0 12px;padding:12px 14px;border-radius:18px;background:var(--rl-s2);font-size:13px;font-weight:700;line-height:1.35}',
      '.rl-across>span{display:flex;align-items:center;gap:10px}.rl-across .rl-flagw{width:22px;justify-content:center}',
      '.rl-note{font-size:12px;color:var(--rl-ink2);margin:10px 0 0;line-height:1.45}',
      '.rl-carry{margin-top:14px;border-radius:var(--rl-r);background:var(--rl-s1);border:1px solid var(--rl-line);overflow:hidden}',
      '.rl-carry summary{list-style:none;display:flex;align-items:center;gap:10px;min-height:56px;padding:10px 16px;cursor:pointer;font-weight:800;font-size:15px}',
      '.rl-carry summary::-webkit-details-marker{display:none}',
      '.rl-carry summary .rl-ci{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:var(--rl-pc);font-size:18px;flex:none}',
      '.rl-carry summary small{display:block;font-weight:700;font-size:12px;color:var(--rl-ink2)}',
      '.rl-carry summary .rl-chev{margin-left:auto;transition:transform .25s var(--rl-ease)}',
      '.rl-carry[open] summary .rl-chev{transform:rotate(180deg)}',
      '.rl-carry ul{list-style:none;margin:0;padding:0 10px 10px}',
      '.rl-carry label{display:flex;align-items:flex-start;gap:10px;min-height:44px;padding:10px 8px;border-radius:14px;font-size:14px;cursor:pointer}',
      '.rl-carry label:hover{background:var(--rl-s2)}',
      '.rl-carry input{width:20px;height:20px;margin:1px 0 0;flex:none;accent-color:var(--rl-pri)}',
      '.rl-carry input:checked+span{text-decoration:line-through;color:var(--rl-ink2)}',
      '.rl-off{display:inline-block;font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px;background:var(--warning-soft,#FEF3C7);color:var(--warm-ink,#92400E);margin:0 0 10px}',
      '.rl.rl-in .rl-rail{animation:rl-in .45s var(--rl-ease) both}',
      '@keyframes rl-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}',
      '@media (prefers-reduced-motion:reduce){.rl.rl-in .rl-rail{animation:none}.rl-carry summary .rl-chev{transition:none}}',
      'html[data-motion="reduced"] .rl.rl-in .rl-rail{animation:none}',
      ':root[data-display="amoled"] .rl-stat,:root[data-display="amoled"] .rl-seg,:root[data-display="amoled"] .rl-span{border:1px solid var(--rl-line)}',
      '@media (max-width:380px){.rl-stat b{font-size:16px}.rl-cd b{font-size:19px}.rl-row{grid-template-columns:48px 22px minmax(0,1fr)}.rl-more,.rl-other{margin-left:78px}}',
      '@media (min-width:760px){.rl-wide{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}.rl-wide .rl-carry{margin-top:0}}'
    ].join('\n');
    (D.head || ROOT).appendChild(st);
  }

  /* ---------------------------------------------------------- views */
  function strip(m) {
    var tot = +m.distanceKm || 0, h = '';
    if (!tot) return '';
    var first = null, last = null, amd = null;
    m.line.forEach(function (x) {
      if (x.km == null) return;
      var p = Math.max(0, Math.min(100, x.km / tot * 100));
      if (x.kind === 'halt') {
        h += '<span class="rl-tk rl-h" style="left:' + p + '%"></span><span class="rl-em" style="left:' + p + '%" aria-hidden="true">' + (HALT_ICON[x.halt] || '•') + '</span>';
      } else {
        h += '<span class="rl-tk" style="left:' + p + '%"></span>';
        if (first === null) first = x;
        last = x;
        if (/chiloda|ahmedabad/i.test(x.name)) amd = { x: x, p: p };
      }
    });
    var lab = '';
    if (first) lab += '<span class="rl-lab" style="left:-6px">' + esc(short(first.name)) + '</span>';
    if (amd && amd.p > 26 && amd.p < 74) lab += '<span class="rl-lab" style="left:' + amd.p + '%;transform:translateX(-50%)">Ahmedabad</span>';
    if (last && last !== first) lab += '<span class="rl-lab" style="right:-6px">' + esc(short(last.name)) + '</span>';
    return '<div class="rl-strip" aria-hidden="true"><span class="rl-track"></span>' + h + lab + '</div>';
  }
  function short(n) { return String(n || '').replace(/\s*\(.*?\)\s*/g, '').replace(/^S Hari Parking,?\s*/i, ''); }

  function rowHtml(x, m, i, n, s) {
    var isHalt = x.kind === 'halt';
    var time = x.time ? (isHalt || x.approx ? s.approx : '') + esc(x.time) + (x.dayPlus > 0 ? '<sup>+' + (x.dayPlus | 0) + '</sup>' : '') : '';
    var cls = 'rl-row' + (isHalt ? ' rl-halt rl-hr' : ' rl-on') + (i === 0 ? ' rl-origin' : '') + (i === n - 1 ? ' rl-end' : '');
    var body;
    if (isHalt) {
      var kind = (s.hk || {})[x.halt] || '';
      body = '<span class="rl-hc"><i aria-hidden="true">' + (HALT_ICON[x.halt] || '•') + '</i><span class="rl-hct"><b>' + esc(kind) + '</b> · ' + esc(fill(s.near, [x.name]))
        + '<span class="rl-hcm">' + (x.haltMin ? '<em>⏱ ' + esc(fill(s.haltFor, [x.haltMin])) + '</em>' : '')
        + (x.km != null ? '<em>' + kmf(x.km) + ' ' + esc(s.km) + '</em>' : '') + '</span></span></span>';
    } else {
      var tag = '';
      if (i === 0) tag = '<span class="rl-tag">' + esc(s.start) + '</span>';
      else if (i === n - 1) tag = '<span class="rl-tag">' + esc(s.end) + '</span>';
      body = '<span class="rl-nm">' + esc(short(x.name)) + (x.border ? flag('IN') : '')
        + (x.km != null ? '<span class="rl-kmc">' + kmf(x.km) + ' ' + esc(s.km) + '</span>' : '') + tag + '</span>'
        + (x.border ? '<span class="rl-sub">' + esc(s.border) + '</span>' : (x.landmark && x.landmark.length < 60 && short(x.name).toLowerCase().indexOf(String(x.landmark).toLowerCase()) < 0 ? '<span class="rl-sub">' + esc(x.landmark) + '</span>' : ''));
    }
    return '<li class="' + cls + '"><time class="' + (x.time ? '' : 'rl-dim') + '">' + time + '</time><span class="rl-dot" aria-hidden="true"></span><div class="rl-body">' + body + '</div></li>';
  }

  function railHtml(m, s, expanded, dir) {
    /* Stops with neither a time nor a km (an office pickup off the main line)
       have no honest place in the sequence: listed under the rail instead. */
    var placed = [], other = [];
    m.line.forEach(function (x) {
      if (x.kind === 'stop' && !x.time && x.km == null && !x.border) other.push(x); else placed.push(x);
    });
    var n = placed.length, rows = [], hidden = 0;
    /* Collapse a long middle run of pickups (or drops) on small screens. */
    var role = dir === 'toNepal' ? 'board' : 'drop';
    var idx = [];
    placed.forEach(function (x, i) { if (x.kind === 'stop' && x.role === role && !x.border) idx.push(i); });
    var hide = {};
    if (!expanded && idx.length > 5) {
      idx.slice(2, idx.length - 1).forEach(function (i) { hide[i] = true; hidden++; });
    }
    var moreAt = -1;
    placed.forEach(function (x, i) {
      if (hide[i]) { if (moreAt < 0) moreAt = rows.length; return; }
      rows.push(rowHtml(x, m, i, n, s));
    });
    var html = '<ol class="rl-rail">' + rows.slice(0, moreAt < 0 ? rows.length : moreAt).join('') + '</ol>';
    if (hidden) html += '<button type="button" class="rl-more" data-rl-more aria-expanded="false">＋ ' + esc(fill(dir === 'toNepal' ? s.more : s.moreDrop, [idx.length])) + '</button><ol class="rl-rail">' + rows.slice(moreAt).join('') + '</ol>';
    else {
      html = '<ol class="rl-rail">' + rows.join('') + '</ol>';
      if (expanded && idx.length > 5) html += '<button type="button" class="rl-more" data-rl-more aria-expanded="true">－ ' + esc(s.less) + '</button>';
    }
    if (other.length) html += '<p class="rl-other">' + esc(s.other) + ': ' + other.map(function (x) { return esc(short(x.name)); }).join(', ') + '</p>';
    return html;
  }

  function carryHtml(s, key) {
    var list = [];
    try { list = ((window.SHG_BOOT || {}).settings || {}).travel_checklist || []; } catch (e) { list = []; }
    if (typeof list === 'string') { try { list = JSON.parse(list); } catch (e) { list = []; } }
    if (!Array.isArray(list) || !list.length) return '';
    var done = {};
    try { done = JSON.parse(localStorage.getItem('shg:carry') || '{}') || {}; } catch (e) { done = {}; }
    var l = lang(), n = 0;
    var items = list.slice(0, 12).map(function (it, i) {
      var txt = String(it || ''), tr = CARRY[txt];
      var shown = l !== 'en' && tr && tr[l] ? tr[l] : txt;
      var on = !!done[i];
      if (on) n++;
      return '<li><label><input type="checkbox" data-rl-carry="' + i + '"' + (on ? ' checked' : '') + '><span>' + esc(shown) + '</span></label></li>';
    }).join('');
    return '<details class="rl-carry"' + (key === 'trip' ? ' open' : '') + '><summary><span class="rl-ci" aria-hidden="true">🎒</span><span>' + esc(s.carry)
      + '<small data-rl-carry-count>' + esc(n ? fill(s.carryDone, [list.length, n]) : s.carrySub) + '</small></span><span class="rl-chev" aria-hidden="true">▾</span></summary><ul>' + items + '</ul></details>';
  }

  /* ---------------------------------------------------------- next departure */
  function nextDep(el, dir) {
    var st = el._rl;
    if (!el.querySelector('[data-rl-next]')) return;
    var s = L(), today = istToday(0), tom = istToday(1), token = st.token = (st.token || 0) + 1;
    function paint(info) {
      var slot = el.querySelector('[data-rl-next]');
      if (!slot || token !== st.token) return;     // a newer draw owns the card
      st.next = info;
      if (!info) { slot.innerHTML = '<div class="rl-nrow"><span class="rl-bus" aria-hidden="true">🚌</span><div class="rl-nt"><b>' + esc(s.noBus) + '</b></div></div><span class="rl-tt">🕒 ' + esc(s.tt) + '</span>'; return; }
      slot.innerHTML = '<div class="rl-nrow"><span class="rl-bus" aria-hidden="true">🚌</span><div class="rl-nt"><small>' + esc(s.next) + ' · ' + esc(info.day === 0 ? s.today : s.tomorrow) + '</small><b>'
        + esc(short(info.from)) + ' · ' + esc(info.time) + '</b><small>→ ' + esc(short(info.to)) + '</small></div>'
        + '<div class="rl-cd"><small>' + esc(s.leavesIn) + '</small><b data-rl-cd>' + cd(info.at) + '</b></div></div>'
        + '<span class="rl-tt">🕒 ' + esc(s.tt) + (s.ttEn ? ' · ' + esc(s.ttEn) : '') + '</span>';
    }
    function from(data, date, day) {
      var r = pickRoute(data, dir);
      if (!r || !r.depTime || r.sellable === false) return null;
      var at = istMs(date, r.depTime);
      return at && at > Date.now() ? { at: at, time: r.depTime, from: r.from, to: r.to, day: day } : null;
    }
    if (st.offline) { paint(null); return; }
    getTT(today).then(function (d) {
      var hit = from(d, today, 0);
      if (hit) return paint(hit);
      /* Today's coach has gone: tomorrow at the timetable clock straight
         away, then confirmed (retime / no service) by tomorrow's board. */
      var r = pickRoute(d, dir), at = r && r.depTime ? istMs(tom, r.depTime) : null;
      if (at) paint({ at: at, time: r.depTime, from: r.from, to: r.to, day: 1 });
      return getTT(tom).then(function (d2) { paint(from(d2, tom, 1)); });
    }).catch(function () { if (!st.next) paint(null); });
  }
  function cd(at) {
    var x = Math.max(0, Math.floor((at - Date.now()) / 1000));
    return pad(Math.floor(x / 3600)) + ':' + pad(Math.floor(x % 3600 / 60)) + ':' + pad(x % 60);
  }
  var ticking = 0;
  function tick() {
    if (ticking) return;
    ticking = setInterval(function () {
      if (D.hidden) return;
      var any = false;
      Array.prototype.forEach.call(D.querySelectorAll('[data-routeline]'), function (el) {
        var st = el._rl, b = el.querySelector('[data-rl-cd]');
        if (!st || !st.next || !b) return;
        any = true;
        if (st.next.at <= Date.now()) { st.next = null; nextDep(el, st.dir); return; }
        b.textContent = cd(st.next.at);
      });
      if (!any) { clearInterval(ticking); ticking = 0; }
    }, 1000);
  }

  /* ---------------------------------------------------------- render */
  function draw(el, m, opts) {
    var s = L(), st = el._rl, dir = st.dir, key = el.getAttribute('data-routeline') || 'home';
    var spans = (m.spans || []).map(function (sp) {
      return '<span class="rl-span">' + esc(short(sp.from)) + ' → ' + esc(short(sp.to)) + ' ≈ ' + kmf(sp.km) + ' ' + esc(s.km) + '</span>';
    }).join('');
    var dur = durText(m.durationMin);
    var last = m.line.length ? m.line[m.line.length - 1] : null;
    var first = m.line.length ? m.line[0] : null;
    var hasBorder = (last && last.border) || (first && first.border);
    var anyApprox = m.line.some(function (x) { return x.kind === 'halt' && x.approx; });
    var across = hasBorder ? '<p class="rl-across"><span>' + flag('IN') + '<span>Rupaidiha · ' + esc(s.border) + '</span></span><span>' + flag('NP') + '<span>' + esc(s.across) + '</span></span></p>' : '';
    var left = '<div class="rl-stats">'
      + '<div class="rl-stat rl-acc"><b>' + (m.distanceKm ? s.approx + ' ' + kmf(m.distanceKm) : '—') + '</b><small>' + esc(s.km) + '</small></div>'
      + '<div class="rl-stat"><b>' + (m.stops | 0) + ' · ' + (m.halts | 0) + '</b><small>' + esc(s.stops) + ' · ' + esc(s.halts) + '</small></div>'
      + '<div class="rl-stat"><b>' + esc(dur || '—') + '</b><small>' + (dur ? esc(s.hoursLong) + ' · ' : '') + esc(s.journey) + '</small></div></div>'
      + (spans ? '<div class="rl-spans">' + spans + '</div>' : '')
      + '<div class="rl-next" data-rl-next aria-live="polite"><div class="rl-nrow"><span class="rl-bus" aria-hidden="true">🚌</span><div class="rl-nt"><small>' + esc(s.next) + '</small><b>' + esc(short(m.from)) + ' · ' + esc(m.depTime || '') + '</b></div></div></div>'
      + strip(m);
    var right = (dir === 'toIndia' ? across : '') + railHtml(m, s, !!st.expanded, dir) + (dir === 'toNepal' ? across : '')
      + (anyApprox ? '<p class="rl-note">' + esc(s.approxNote) + '</p>' : '')
      + (m.durationApprox !== false && !m.arrTime ? '<p class="rl-note">🕒 ' + esc(s.arrival) + ' · ' + esc(s.tt) + '</p>' : '');
    var wide = !opts.compact || key === 'trip';
    el.innerHTML = '<div class="rl' + (reduced() ? '' : ' rl-in') + '" lang="' + lang() + '">'
      + '<div class="rl-top"><div class="rl-seg" role="group" aria-label="' + esc(s.dirAria) + '">'
      + '<button type="button" data-rl-dir="toNepal" aria-pressed="' + (dir === 'toNepal') + '">' + flag('NP') + '<span>' + esc(s.go) + '</span></button>'
      + '<button type="button" data-rl-dir="toIndia" aria-pressed="' + (dir === 'toIndia') + '">' + flag('IN') + '<span>' + esc(s.back) + '</span></button></div></div>'
      + (m.offline ? '<span class="rl-off">' + esc(s.offline) + '</span>' : '')
      + (wide ? '<div class="rl-wide"><div>' + left + carryHtml(s, key) + '</div><div>' + right + '</div></div>' : left + right + carryHtml(s, key))
      + '</div>';
    st.offline = !!m.offline;
    nextDep(el, dir);
    tick();
  }

  function render(el, opts) {
    if (!el || el.nodeType !== 1) return Promise.resolve();
    css();
    opts = opts || {};
    var st = el._rl || (el._rl = { dir: null, expanded: false });
    if (opts.dir === 'toNepal' || opts.dir === 'toIndia') st.dir = opts.dir;
    if (!st.dir) {
      var a = el.getAttribute('data-dir');
      if (a !== 'toNepal' && a !== 'toIndia') { try { a = localStorage.getItem('shg:rlDir'); } catch (e) { a = null; } }
      st.dir = a === 'toIndia' ? 'toIndia' : 'toNepal';
    }
    st.opts = { compact: opts.compact !== false };
    wire(el);
    return getTT('').then(function (data) {
      var r = pickRoute(data, st.dir);
      if (!r) {
        var other = pickRoute(data, st.dir === 'toNepal' ? 'toIndia' : 'toNepal');
        if (!other) throw new Error('no route');
        draw(el, model(null, st.dir, true), st.opts);
        return;
      }
      draw(el, model(r, st.dir, false), st.opts);
    }).catch(function () {
      draw(el, model(null, st.dir, true), st.opts);
    });
  }

  function wire(el) {
    if (el._rlWired) return;
    el._rlWired = true;
    el.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target : null;
      if (!t) return;
      var b = t.closest('[data-rl-dir]');
      if (b && el.contains(b)) {
        var d = b.getAttribute('data-rl-dir');
        if (d === el._rl.dir) return;
        el._rl.dir = d;
        el._rl.expanded = false;
        try { localStorage.setItem('shg:rlDir', d); } catch (x) { /* per-viewer convenience only */ }
        feel('select');
        render(el, el._rl.opts).then(function () { var nb = el.querySelector('[data-rl-dir="' + d + '"]'); if (nb) nb.focus({ preventScroll: true }); });
        return;
      }
      var mo = t.closest('[data-rl-more]');
      if (mo && el.contains(mo)) {
        el._rl.expanded = !el._rl.expanded;
        feel('tap');
        render(el, el._rl.opts);
      }
    });
    el.addEventListener('change', function (e) {
      var c = e.target;
      if (!c || !c.hasAttribute || !c.hasAttribute('data-rl-carry')) return;
      var done = {};
      try { done = JSON.parse(localStorage.getItem('shg:carry') || '{}') || {}; } catch (x) { done = {}; }
      done[c.getAttribute('data-rl-carry')] = c.checked ? 1 : 0;
      try { localStorage.setItem('shg:carry', JSON.stringify(done)); } catch (x) { /* per-viewer convenience only */ }
      feel(c.checked ? 'toggleOn' : 'toggleOff');
      var boxes = el.querySelectorAll('[data-rl-carry]'), n = 0;
      Array.prototype.forEach.call(boxes, function (b) { if (b.checked) n++; });
      var cnt = el.querySelector('[data-rl-carry-count]'), s = L();
      if (cnt) cnt.textContent = n ? fill(s.carryDone, [boxes.length, n]) : s.carrySub;
    });
  }

  function mountAll(force) {
    Array.prototype.forEach.call(D.querySelectorAll('[data-routeline]'), function (el) {
      if (!force && el._rl && el.querySelector('.rl')) return;
      render(el, { compact: (el.getAttribute('data-routeline') || 'home') === 'home' });
    });
  }

  function boot() {
    mountAll(false);
    /* the home view becoming active again: (re)mount and refresh the countdown */
    var home = D.getElementById('view-home');
    if (home && 'MutationObserver' in window) {
      var was = home.classList.contains('active');
      new MutationObserver(function () {
        var is = home.classList.contains('active');
        if (is && !was) mountAll(false);
        was = is;
      }).observe(home, { attributes: true, attributeFilter: ['class'] });
    }
    /* language switch: redraw in the new language */
    if ('MutationObserver' in window) {
      var last = lang(), pend = 0;
      new MutationObserver(function () {
        var l = lang();
        if (l === last) return;
        last = l;
        clearTimeout(pend);
        pend = setTimeout(function () { mountAll(true); }, 40);
      }).observe(ROOT, { attributes: true, attributeFilter: ['lang'] });
    }
    window.addEventListener('online', function () { CACHE = {}; mountAll(true); });
  }

  window.shgRouteLine = {
    render: render,
    mountAll: mountAll,
    refresh: function () { CACHE = {}; mountAll(true); }
  };
  if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
