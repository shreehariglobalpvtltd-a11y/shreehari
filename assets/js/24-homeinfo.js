/* 24-homeinfo.js — 9 Oct 2026: home information sections behaviour (fares card from
   FACTS, India–Nepal timeline teaser, explore/tours teaser). Owner: HOMEINFO work package.

   Markup: app.template.html between the HOMEINFO:START / HOMEINFO:END comments.
   Styles: assets/css/homeinfo.css. Copy: the hx* keys of 04-i18n.js (en / hi / ne).

   Money rule: no rupee figure is typed anywhere in this file. Every fare comes from
   the boot payload the server built from the same rows it charges from —
     1. window.FACTS (or SHG_BOOT.facts).fares  {go, back, range, defaults, private}
        = Fare::board(), when the FACTS work package has shipped it;
     2. else SHG_BOOT.locations.go/back[{name,time,fare}] (route_stops.fare) with a
        missing fare resolved to sharingDir() (fare_to_nepal / fare_to_india), exactly
        like Fare::stopFare() → Fare::dirFares() on the server, and the private cabin
        from CONFIG.cabinPricing.private (merged from the cabin_pricing settings row).
   An admin price change therefore shows here on the next page open, no deploy.

   Timeline + featured places/packages come from /api/explore.php (CONTENT work
   package). Until that answers with JSON the static, accurate markup stays.

   The only global is window.shgHomeInfo {render, fares, model, prefill}. */
(function () {
  'use strict';
  var D = document, ROOT = D.documentElement;
  var ASSET = '?v=20261022z';
  var STATE = { open: { go: false, back: false }, tl: null, feat: null, tlTried: false, featTried: false, io: null };

  /* ---------------------------------------------------------------- helpers */
  function q(s, r) { return (r || D).querySelector(s); }
  function qa(s, r) { return Array.prototype.slice.call((r || D).querySelectorAll(s)); }
  function h(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function lang() {
    try { if (typeof LANG === 'string' && LANG) return LANG; } catch (e) { /* not loaded */ }
    return ROOT.getAttribute('lang') || 'ne';
  }
  function tx(k, fb) {
    try { if (typeof t === 'function') { var s = t(k); if (s != null && s !== k) return String(s); } } catch (e) { /* i18n missing */ }
    return fb == null ? '' : String(fb);
  }
  function txf(k, vars, fb) {
    var s = tx(k, fb);
    Object.keys(vars || {}).forEach(function (v) { s = s.split('{' + v + '}').join(String(vars[v])); });
    return s;
  }
  function num(n) { return Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function money(n) { return '₹' + num(n); }
  function feel(kind) { try { if (window.SHGFeel && typeof window.SHGFeel.play === 'function') window.SHGFeel.play(kind); } catch (e) { /* sound is optional */ } }
  function reducedMotion() {
    if (ROOT.getAttribute('data-motion') === 'reduced') return true;
    try { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) { return false; }
  }
  function boot() { return (window.SHG_BOOT && typeof window.SHG_BOOT === 'object') ? window.SHG_BOOT : {}; }
  function facts() {
    try { var f = window.FACTS || boot().facts; return (f && typeof f === 'object') ? f : null; } catch (e) { return null; }
  }
  function apiBase() {
    var b = boot().apiBase;
    return (typeof b === 'string' && /^\/[A-Za-z0-9_\/-]*$/.test(b)) ? b.replace(/\/+$/, '') : '/api';
  }
  /* CONTENT pages live at /x (en) and /ne/x, /hi/x; /travel-info is one URL with ?lang= */
  function hrefFor(base) {
    var l = lang();
    if (base === '/travel-info') return '/travel-info?lang=' + (l === 'ne' || l === 'hi' ? l : 'en');
    return (l === 'ne' || l === 'hi') ? '/' + l + base : base;
  }
  /* API-supplied links: same-origin paths only (never javascript:, never another site) */
  function safeUrl(u, fb) {
    u = String(u || '').trim();
    if (/^\/(?!\/)[^\s"'<>\\]*$/.test(u)) return u;
    try {
      var a = new URL(u, location.href);
      if (a.origin === location.origin && (a.protocol === 'https:' || a.protocol === 'http:')) return a.pathname + a.search + a.hash;
    } catch (e) { /* bad URL */ }
    return fb;
  }
  /* an API link to a CONTENT page follows the visitor's language: /explore#x → /ne/explore#x
     (10 Oct 2026, BIZ: /business too) */
  function localize(u) {
    var l = lang();
    if (l !== 'ne' && l !== 'hi') return u;
    return /^\/(?:explore|tours|india-nepal|offices|business)(?=[\/?#]|$)/.test(u) ? '/' + l + u : u;
  }
  function flagImg(cc, ht, cls) {
    var np = cc === 'NP';
    var w = np ? Math.round(ht * 66 / 86) : Math.round(ht * 1.5);
    return '<img class="' + (cls || ('hx-flag' + (np ? ' hx-flag-np' : ''))) + '" src="/assets/img/flag-' + (np ? 'np' : 'in') + '.svg' + ASSET
      + '" width="' + w + '" height="' + ht + '" alt="" aria-hidden="true" decoding="async">';
  }
  var IC = {
    arrow: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    down: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    right: '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
    lock: '<svg class="ic" aria-hidden="true"><use href="#i-lock"/></svg>',
    bag: '<svg class="ic" aria-hidden="true"><use href="#i-luggage"/></svg>'
  };
  function mins(hm) { var p = String(hm).split(':'); return (+p[0] || 0) * 60 + (+p[1] || 0); }
  function time12(hm) {
    if (!hm) return '';
    try { if (typeof fmt12h === 'function') { var s = fmt12h(hm); if (s) return s; } } catch (e) { /* router not loaded */ }
    var hh = mins(hm) / 60 | 0, mm = String(mins(hm) % 60).padStart(2, '0');
    return ((hh % 12) || 12) + ':' + mm + ' ' + (hh < 12 ? 'AM' : 'PM');
  }
  function pick(o, base) {
    var l = lang(), suf = l === 'ne' ? 'Ne' : (l === 'hi' ? 'Hi' : '');
    var v = (suf && o[base + suf]) || o[base] || o[base + 'Ne'] || '';
    return String(v).trim().slice(0, 240);
  }

  /* ---------------------------------------------------------- fare model */
  function dirDefaults() {
    var F = facts(), d = F && F.fares && F.fares.defaults;
    var go = d && +d.toNepal > 0 ? Math.round(+d.toNepal) : 0;
    var back = d && +d.toIndia > 0 ? Math.round(+d.toIndia) : 0;
    if (!go || !back) {
      try {
        if (typeof sharingDir === 'function') {
          var s = sharingDir();
          if (!go) go = Math.round(+s.toNepal) || 0;
          if (!back) back = Math.round(+s.toIndia) || 0;
        }
      } catch (e) { /* config not loaded */ }
    }
    if (!go || !back) {
      var st = boot().settings || {};
      if (!go) go = Math.round(+st.fare_to_nepal) || 0;
      if (!back) back = Math.round(+st.fare_to_india) || 0;
    }
    return { go: go, back: back };
  }
  /* one row per stop name; a stop with no fare of its own pays the direction default */
  function rows(list, dflt) {
    var seen = {}, out = [];
    (Array.isArray(list) ? list : []).forEach(function (s) {
      if (!s || !s.name) return;
      var name = String(s.name).trim(), key = name.toLowerCase();
      if (!name || seen[key]) return;
      seen[key] = 1;
      var f = Math.round(Number(s.fare));
      if (!(f > 0)) f = Math.round(Number(dflt)) || 0;
      if (!(f > 0)) return;
      var tm = /^\d{1,2}:\d{2}/.test(String(s.time || '')) ? String(s.time).slice(0, 5) : '';
      out.push({ name: name, time: tm, fare: f });
    });
    return out;
  }
  /* pickups in the order the coach reaches them (times wrap past midnight) */
  function byTime(list) {
    var first = null;
    list.forEach(function (r) { if (first === null && r.time) first = mins(r.time); });
    if (first === null) return list;
    return list.map(function (r, i) { return { r: r, i: i, k: r.time ? (mins(r.time) - first + 1440) % 1440 : 99999 }; })
      .sort(function (a, b) { return (a.k - b.k) || (a.i - b.i); })
      .map(function (x) { return x.r; });
  }
  function range(list, fb, dflt) {
    if (list.length) {
      var a = list.map(function (r) { return r.fare; });
      return { min: Math.min.apply(null, a), max: Math.max.apply(null, a) };
    }
    if (fb && +fb.min > 0) return { min: Math.round(+fb.min), max: Math.round(+fb.max) || Math.round(+fb.min) };
    return dflt > 0 ? { min: dflt, max: dflt } : null;
  }
  function privPrices() {
    var F = facts(), p = F && F.fares && F.fares.private;
    var s = p ? +p.single : 0, d = p ? +p.double : 0;
    if (!(s > 0) || !(d > 0)) {
      try {
        var cp = CONFIG.cabinPricing.private;
        if (!(s > 0)) s = +cp.single_1pax.offline;
        if (!(d > 0)) d = +cp.double_2pax.offline;
      } catch (e) { /* config not loaded */ }
    }
    return { single: s > 0 ? Math.round(s) : 0, double: d > 0 ? Math.round(d) : 0 };
  }
  function nprRate() {
    var F = facts(), v = F ? Number(F.npr) : 0;
    if (!(v > 0)) v = Number(boot().npr);
    if (!(v > 0)) { try { v = Number(CONFIG.nprPerInr); } catch (e) { v = 0; } }
    return v > 0 ? v : 0;
  }
  function fareModel() {
    var F = facts(), f = (F && F.fares) || null, L = boot().locations || {}, dd = dirDefaults();
    var goSrc = f && Array.isArray(f.go) && f.go.length ? f.go : L.go;
    var backSrc = f && Array.isArray(f.back) && f.back.length ? f.back : L.back;
    var go = byTime(rows(goSrc, dd.go)), back = rows(backSrc, dd.back);
    var fr = (f && f.range) || {};
    return {
      src: f && Array.isArray(f.go) && f.go.length ? 'facts' : 'boot',
      go: go, back: back,
      rgo: range(go, fr.go, dd.go), rback: range(back, fr.back, dd.back),
      priv: privPrices(), npr: nprRate()
    };
  }

  /* ------------------------------------------------------- fares render */
  function rowHtml(kind, r) {
    var go = kind === 'go';
    var aria = txf(go ? 'hxBookFrom' : 'hxBookTo', { town: r.name, fare: money(r.fare) }) + (r.time ? ' · ' + time12(r.time) : '');
    return '<li><button type="button" class="hx-row' + (r.time ? '' : ' hx-row-nt') + '" data-hx-dir="' + kind + '" data-hx-town="' + h(r.name) + '" aria-label="' + h(aria) + '">'
      + (r.time ? '<span class="hx-time">' + h(time12(r.time)) + '</span>' : '')
      + '<span class="hx-town">' + h(r.name) + '</span>'
      + '<span class="hx-fare">' + money(r.fare) + '</span>' + IC.right + '</button></li>';
  }
  function dirCard(kind, list, rg, npr, i) {
    var go = kind === 'go', open = !!STATE.open[kind] && list.length > 0, id = 'hxList-' + kind;
    var price = rg.min === rg.max ? money(rg.min) : money(rg.min) + '<span class="hx-dash">–</span>' + money(rg.max);
    var nprTxt = npr > 0 ? '≈ NPR ' + num(rg.min * npr) + (rg.min !== rg.max ? ' – ' + num(rg.max * npr) : '') : '';
    var show = go ? txf('hxShowPickups', { n: list.length }) : txf('hxShowDrops', { n: list.length });
    var out = '<div class="hx-dir hx-dir-' + kind + ' hx-rv" style="--i:' + i + '">'
      + '<div class="hx-dir-top"><span class="hx-dir-flags">' + flagImg(go ? 'IN' : 'NP', 16) + IC.arrow + flagImg(go ? 'NP' : 'IN', 16) + '</span>'
      + '<span class="hx-dir-lbl">' + h(tx(go ? 'hxGo' : 'hxBack')) + '</span></div>'
      + '<p class="hx-dir-route">' + (go ? 'Gujarat → Rupaidiha' : 'Rupaidiha → Gujarat') + '</p>'
      + '<p class="hx-price"><span class="hx-price-v">' + price + '</span><span class="hx-price-u">' + h(tx('hxPerPerson')) + '</span></p>'
      + (nprTxt ? '<p class="hx-npr">' + nprTxt + '</p>' : '');
    if (list.length) {
      out += '<button type="button" class="hx-more" data-hx-toggle="' + kind + '" data-show="' + h(show) + '" aria-expanded="' + open + '" aria-controls="' + id + '">'
        + '<span>' + h(open ? tx('hxHideList') : show) + '</span>' + IC.down + '</button>'
        + '<div class="hx-list-wrap' + (open ? ' is-open' : '') + '" id="' + id + '"><div class="hx-list-clip"><ul class="hx-list">'
        + list.map(function (r) { return rowHtml(kind, r); }).join('')
        + '</ul></div></div>';
    }
    return out + '</div>';
  }
  function privCard(p) {
    return '<div class="hx-priv hx-rv" style="--i:2"><span class="hx-priv-ic">' + IC.lock + '</span>'
      + '<div class="hx-priv-tx"><b>' + h(tx('hxPrivTitle')) + '</b><small>' + h(tx('hxPrivSub')) + '</small></div>'
      + '<div class="hx-priv-prices">'
      + (p.single ? '<span><small>' + h(tx('hxPrivSingle')) + '</small><b>' + money(p.single) + '</b></span>' : '')
      + (p.double ? '<span><small>' + h(tx('hxPrivDouble')) + '</small><b>' + money(p.double) + '</b></span>' : '')
      + '</div></div>';
  }
  function renderFares() {
    var body = q('#hxFaresBody');
    if (!body) return;
    var m = null;
    try { m = fareModel(); } catch (e) { m = null; }
    if (!m || (!m.rgo && !m.rback)) {
      body.innerHTML = '<p class="hx-empty">' + h(tx('hxFaresNone', '')) + '</p>';
      return;
    }
    var out = '';
    if (m.rgo) out += dirCard('go', m.go, m.rgo, m.npr, 0);
    if (m.rback) out += dirCard('back', m.back, m.rback, m.npr, 1);
    if (m.priv.single || m.priv.double) out += privCard(m.priv);
    if (m.npr > 0) out += '<p class="hx-npr-note">' + h(txf('hxNprNote', { npr: (Math.round(m.npr * 100) / 100).toFixed(2) })) + '</p>';
    body.innerHTML = out;
    body.setAttribute('data-src', m.src);
  }
  function toggleList(btn) {
    var kind = btn.getAttribute('data-hx-toggle');
    var wrap = D.getElementById(btn.getAttribute('aria-controls') || '');
    var open = btn.getAttribute('aria-expanded') !== 'true';
    STATE.open[kind] = open;
    btn.setAttribute('aria-expanded', String(open));
    if (wrap) wrap.classList.toggle('is-open', open);
    var sp = btn.querySelector('span');
    if (sp) sp.textContent = open ? tx('hxHideList') : (btn.getAttribute('data-show') || '');
    feel(open ? 'select' : 'deselect');
  }
  /* A fare row → the booking card, pre-filled with that direction and town
     (the same controls a passenger would tap: #dirGo / #dirBack + #pointSel). */
  function prefill(dir, town) {
    dir = dir === 'back' ? 'back' : 'go';
    feel('select');
    try {
      if (typeof setSearchDir === 'function') setSearchDir(dir);
      else { var b = q(dir === 'back' ? '#dirBack' : '#dirGo'); if (b) b.click(); }
    } catch (e) { /* router not ready */ }
    var sel = q('#pointSel');
    if (sel && town) {
      var opts = Array.prototype.slice.call(sel.options || []);
      var hit = opts.filter(function (o) { return o.value === town; })[0];
      if (!hit && typeof townKeyJS === 'function') {
        var k = townKeyJS(town);
        hit = opts.filter(function (o) { return townKeyJS(o.value) === k; })[0];
      }
      if (hit) {
        sel.value = hit.value;
        try { sel.dispatchEvent(new Event('change', { bubbles: true })); }
        catch (e) { try { if (typeof applyDirection === 'function') applyDirection(); } catch (e2) { /* ignore */ } }
      }
    }
    var anchor = D.getElementById('search-anchor');
    if (anchor) {
      try { anchor.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' }); } catch (e) { anchor.scrollIntoView(); }
      anchor.classList.remove('hx-flash');
      void anchor.offsetWidth;
      anchor.classList.add('hx-flash');
      setTimeout(function () { anchor.classList.remove('hx-flash'); }, 1600);
    }
  }
  function wireFares() {
    var sec = q('#homeFares');
    if (!sec || sec._hxWired) return;
    sec._hxWired = true;
    sec.addEventListener('click', function (e) {
      var tg = e.target.closest('[data-hx-toggle]');
      if (tg) { toggleList(tg); return; }
      var row = e.target.closest('[data-hx-town]');
      if (row) prefill(row.getAttribute('data-hx-dir'), row.getAttribute('data-hx-town'));
    });
  }

  /* --------------------------------------------- explore API (CONTENT) */
  function getJSON(path, key) {
    var ck = 'shg:hx:' + key;
    try {
      var c = JSON.parse(sessionStorage.getItem(ck) || 'null');
      if (c && c.t && Date.now() - c.t < 30 * 60000 && c.d) return Promise.resolve(c.d);
    } catch (e) { /* storage blocked */ }
    if (!window.fetch) return Promise.reject(new Error('no fetch'));
    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { try { ctl.abort(); } catch (e) { /* ignore */ } }, 9000) : 0;
    return fetch(apiBase() + path, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctl ? ctl.signal : undefined })
      .then(function (r) {
        clearTimeout(timer);
        var ct = (r.headers && r.headers.get('content-type')) || '';
        if (!r.ok || ct.indexOf('json') < 0) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (j) {
        if (!j || j.ok !== true || !j.data || typeof j.data !== 'object') throw new Error('bad payload');
        try { sessionStorage.setItem(ck, JSON.stringify({ t: Date.now(), d: j.data })); } catch (e) { /* storage full */ }
        return j.data;
      });
  }
  function renderTimeline() {
    var ol = q('#hxTimeline');
    if (!ol || !STATE.tl) return;
    var out = STATE.tl.map(function (ev, i) {
      if (!ev || typeof ev !== 'object') return '';
      var title = pick(ev, 'title');
      if (!title) return '';
      var yr = pick(ev, 'year').slice(0, 16);
      var ic = ev.icon ? String(ev.icon).trim().slice(0, 8) : '';
      if (!/[^\x00-\x7F]/.test(ic)) ic = '';   // an emoji, never an icon name printed as text
      return '<li class="hx-tl-i hx-rv" style="--i:' + i + '">' + (yr ? '<span class="hx-tl-y">' + h(yr) + '</span>' : '')
        + '<span class="hx-tl-t">' + (ic ? '<span class="hx-tl-ic" aria-hidden="true">' + h(ic) + '</span>' : '') + h(title) + '</span></li>';
    }).join('');
    if (out) { ol.innerHTML = out; ol.setAttribute('data-live', '1'); }
  }
  var ART = { pilgrimage: 'heritage', heritage: 'heritage', nature: 'nature', adventure: 'nature', lake: 'nature', hill: 'nature', wildlife: 'wild', city: 'city' };
  var CAT = { pilgrimage: 'hxCatPilgrimage', heritage: 'hxCatHeritage', nature: 'hxCatNature', adventure: 'hxCatAdventure', wildlife: 'hxCatWildlife', city: 'hxCatCity', lake: 'hxCatLake', hill: 'hxCatHill' };
  var AUD = { solo: 'hxAudSolo', couple: 'hxAudCouple', family: 'hxAudFamily', group: 'hxAudGroup', pilgrim: 'hxAudPilgrim' };
  function renderFeatured() {
    var f = STATE.feat;
    if (!f) return;
    var places = (Array.isArray(f.places) ? f.places : []).filter(function (p) { return p && typeof p === 'object' && (p.name || p.nameNe); }).slice(0, 6);
    var box = q('#hxPlaces');
    if (box && places.length) {
      box.innerHTML = places.map(function (p, i) {
        var cat = String(p.category || '').toLowerCase();
        var cc = String(p.country || '').toUpperCase();
        cc = cc === 'IN' || cc === 'NP' ? cc : '';
        var blurb = pick(p, 'blurb');
        /* a same-origin picture (/uploads/… or /assets/…) over the CSS art, which stays as
           the fallback underneath.
           10 Oct 2026 (HD review): the same <img class="hx-card-img"> as the static cards,
           and the honest "Illustration" tag whenever the picture is one - the API says so
           (imageIllus, from the credit line); an /assets/img/hd/ path counts too, so a
           30-minute sessionStorage copy of the old payload is still labelled. A real
           photo the office uploads gets no tag. */
        var img = safeUrl(p.image, '');
        img = /^\/[A-Za-z0-9_.\/-]+\.(?:webp|jpe?g|png)$/i.test(img) ? img : '';
        var ill = !!img && (p.imageIllus === true || img.indexOf('/assets/img/hd/') === 0);
        return '<li class="hx-rv" style="--i:' + i + '"><a class="hx-card" href="' + h(localize(safeUrl(p.url, '/explore'))) + '">'
          + '<span class="hx-card-art hx-art-' + (ART[cat] || 'nature') + '">'
          + (img ? '<img class="hx-card-img" src="' + h(img) + '" width="480" height="320" alt="" loading="lazy" decoding="async">' : '')
          + (cc ? flagImg(cc, cc === 'NP' ? 22 : 18, 'hx-card-flag' + (cc === 'IN' ? ' hx-card-flag-in' : '')) : '')
          + (CAT[cat] ? '<span class="hx-card-cat">' + h(tx(CAT[cat])) + '</span>' : '')
          + (ill ? '<span class="hx-card-ill" aria-hidden="true">' + h(tx('hxIllus', 'Illustration')) + '</span>' : '')
          + '</span><span class="hx-card-tx"><b>' + h(pick(p, 'name')) + '</b>' + (blurb ? '<small>' + h(blurb) + '</small>' : '') + '</span></a></li>';
      }).join('');
    }
    var pk = (Array.isArray(f.packages) ? f.packages : []).filter(function (p) { return p && typeof p === 'object' && (p.title || p.titleNe); }).slice(0, 4);
    var pbox = q('#hxPkgs');
    if (pbox && pk.length) {
      pbox.innerHTML = '<h3 class="hx-h3">' + h(tx('hxHubTours')) + '</h3>' + pk.map(function (p, i) {
        var d = Math.round(+p.days) || 0, n = Math.round(+p.nights);
        var dur = d > 0 ? (p.nights != null && n >= 0 ? txf('hxPkgDays', { d: d, n: n }) : txf('hxPkgDaysOnly', { d: d })) : '';
        /* 10 Oct 2026 (BIZ): the audience chip (solo / couple / family / group / pilgrim) when the package has one */
        var aud = AUD[String(p.audience || '').toLowerCase()] || '';
        return '<a class="hx-pkg hx-rv" style="--i:' + i + '" href="' + h(localize(safeUrl(p.url, '/tours'))) + '">'
          + '<span class="hx-pkg-d">' + (dur ? h(dur) : IC.bag) + '</span>'
          + '<span class="hx-pkg-tx"><b>' + h(pick(p, 'title')) + '</b>' + (aud ? '<span class="hx-pkg-aud">' + h(tx(aud)) + '</span>' : '') + '</span>'
          + '<span class="hx-pkg-go">' + h(tx('hxPkgCta')) + ' →</span></a>';
      }).join('');
      pbox.hidden = false;
    }
  }
  function loadTimeline() {
    if (STATE.tlTried) return;
    STATE.tlTried = true;
    getJSON('/explore.php?what=timeline&limit=6', 'tl').then(function (d) {
      var ev = Array.isArray(d.events) ? d.events.slice(0, 6) : [];
      if (ev.length) { STATE.tl = ev; renderTimeline(); }
    }).catch(function () { /* static fallback stays */ });
  }
  function loadFeatured() {
    if (STATE.featTried) return;
    STATE.featTried = true;
    getJSON('/explore.php?what=featured', 'feat').then(function (d) {
      STATE.feat = d;
      renderFeatured();
    }).catch(function () { /* static cards stay */ });
  }
  /* fetch only when the visitor gets near the section */
  function lazyFetch() {
    var map = [['#homeIndiaNepal', loadTimeline], ['#homeExplore', loadFeatured]];
    if (!('IntersectionObserver' in window)) { map.forEach(function (m) { m[1](); }); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        map.forEach(function (m) { if (q(m[0]) === en.target) m[1](); });
        io.unobserve(en.target);
      });
    }, { rootMargin: '900px 0px' });
    map.forEach(function (m) { var el = q(m[0]); if (el) io.observe(el); });
  }

  /* ----------------------------------------------- #homeRoute safety net */
  /* 22-routeline.js (ROUTE) renders inside [data-routeline="home"]. When its
     content arrives the skeleton goes; if it never arrives (old cached bundle,
     script error) a plain pickup list from the boot payload replaces the
     skeleton so the section never shimmers forever. */
  function routeMount() { return q('#homeRoute [data-routeline="home"]'); }
  function watchRoute() {
    var mount = routeMount();
    if (!mount || !('MutationObserver' in window)) return;
    new MutationObserver(function () {
      var mine = qa('[data-hx-skel],[data-hx-rfb]', mount);
      var theirs = Array.prototype.some.call(mount.children, function (c) { return !c.hasAttribute('data-hx-skel') && !c.hasAttribute('data-hx-rfb'); });
      if (theirs && mine.length) mine.forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
    }).observe(mount, { childList: true });
  }
  function routeFallback() {
    var mount = routeMount();
    if (!mount || !mount.querySelector('[data-hx-skel]')) return;
    var m = null;
    try { m = fareModel(); } catch (e) { m = null; }
    /* only timed pickups: an untimed one (an office pickup off the main line) has no place in the sequence */
    var stops = ((m && m.go) || []).filter(function (s) { return !!s.time; });
    var out = '<ol class="hx-rfb" data-hx-rfb>' + stops.map(function (s) {
      return '<li><span>' + h(s.name) + '</span><span class="hx-time">' + h(time12(s.time)) + '</span></li>';
    }).join('') + '<li class="hx-rfb-end"><span>' + h(tx('hxRouteFb', 'Rupaidiha')) + '</span></li></ol>';
    mount.innerHTML = out;
  }

  /* ------------------------------------------------------ reveal + misc */
  function armReveal() {
    var secs = qa('.hx');
    if (reducedMotion()) return;
    var bot = !!navigator.webdriver;
    if (bot || !('IntersectionObserver' in window)) { secs.forEach(function (s) { s.classList.add('hx-on'); }); return; }
    var io = STATE.io = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        en.target.classList.add('hx-on');
        io.unobserve(en.target);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.01 });
    var vh = window.innerHeight || 800;
    secs.forEach(function (s) {
      var r = s.getBoundingClientRect();
      if (r.height > 0 && r.top < vh * 0.9 && r.bottom > 0) { s.classList.add('hx-on'); return; }
      s.classList.add('hx-armed');
      io.observe(s);
    });
  }
  function syncHrefs() {
    qa('.hx [data-hx-href]').forEach(function (a) { a.setAttribute('href', hrefFor(a.getAttribute('data-hx-href'))); });
  }
  function syncAlt() {
    var img = q('#hxBandImg');
    if (img) img.setAttribute('alt', tx('hxBandAlt', img.getAttribute('alt')));
  }
  function renderAll() {
    try { renderFares(); } catch (e) { /* keep the page alive */ }
    try { renderTimeline(); } catch (e) { /* static fallback stays */ }
    try { renderFeatured(); } catch (e) { /* static cards stay */ }
    try { syncHrefs(); syncAlt(); } catch (e) { /* ignore */ }
  }
  /* language switch: applyStaticLang() stamps <html lang> — redraw the JS-built parts */
  function watchLang() {
    if (!('MutationObserver' in window)) return;
    var last = lang(), pending = 0;
    new MutationObserver(function () {
      var l = lang();
      if (l === last) return;
      last = l;
      clearTimeout(pending);
      pending = setTimeout(renderAll, 30);
    }).observe(ROOT, { attributes: true, attributeFilter: ['lang'] });
  }
  function wireFeel() {
    D.addEventListener('click', function (e) {
      if (!e.target || !e.target.closest) return;
      if (e.target.closest('.hx .hx-tile, .hx .hx-btn, .hx .hx-card, .hx .hx-link, .hx .hx-pkg')) feel('tap');
    }, true);
  }

  function init() {
    if (!q('.hx')) return;
    wireFares();
    renderAll();
    try { armReveal(); } catch (e) { qa('.hx').forEach(function (s) { s.classList.remove('hx-armed'); }); }
    try { lazyFetch(); } catch (e) { /* static fallbacks stay */ }
    try { watchRoute(); } catch (e) { /* ignore */ }
    watchLang();
    wireFeel();
    /* no route component at all → the plain list soon; a component that is just slow → wait longer */
    var later = function () { setTimeout(routeFallback, window.shgRouteLine ? 9000 : 1500); };
    if (D.readyState === 'complete') later(); else window.addEventListener('load', later, { once: true });
  }

  window.shgHomeInfo = {
    render: renderAll,
    fares: renderFares,
    model: function () { try { return fareModel(); } catch (e) { return null; } },
    prefill: prefill
  };
  if (D.readyState === 'loading') D.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
