/* =====================================================================
 *  19-ux.js — UI/UX v3 layer (23 Sep 2026).
 *
 *  Loads LAST. Everything here is additive: a call sheet for the header,
 *  a sound preference over the existing SFX synth, and (later sections)
 *  the home channel cards, search chips, seat legend, sticky hold bar and
 *  help polish. Each block is its own try/catch and reads only globals the
 *  earlier bundles define ($, CONFIG, SFX, toast, t, Flow ...). If any of
 *  them is missing, that block simply does nothing and the app is exactly
 *  as it was — no booking path depends on this file.
 * ===================================================================== */
(function () {
  'use strict';

  var UX = window.SHGUX = window.SHGUX || {};
  var q = function (sel, root) { return (root || document).querySelector(sel); };
  var qa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var boot = function () { return window.SHG_BOOT || {}; };
  var settings = function () { return (boot().settings) || {}; };
  var reduced = function () {
    try { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; }
  };
  var lsGet = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  var lsSet = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); };
  var tr = function (k, fb) { try { var v = typeof t === 'function' ? t(k) : k; return v === k ? (fb || k) : v; } catch (e) { return fb || k; } };
  UX.q = q; UX.qa = qa; UX.esc = esc; UX.tr = tr; UX.reduced = reduced;

  /* ---------------------------------------------------------------
   *  SOUND PREFERENCE — the SFX synth in 13-admin-routes.js already
   *  plays soft UI sounds (click / select / success / notify). This
   *  adds the ONE thing it lacked: a mute switch the traveller owns,
   *  remembered in localStorage. Default ON, exactly as before.
   * ------------------------------------------------------------- */
  var SOUND_KEY = 'shg:sound';
  UX.soundOn = function () { return lsGet(SOUND_KEY) !== 'off'; };
  UX.setSound = function (on) {
    lsSet(SOUND_KEY, on ? 'on' : 'off');
    qa('[data-sound-toggle]').forEach(function (b) {
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('off', !on);
      var lbl = b.querySelector('.st-lbl');
      if (lbl) lbl.textContent = on ? tr('soundOn', 'Sound on') : tr('soundOff', 'Muted');
      var ico = b.querySelector('.st-ico');
      if (ico) ico.textContent = on ? '🔊' : '🔇';
    });
    if (on && typeof SFX !== 'undefined' && SFX.pop) { try { SFX.pop(); } catch (e) {} }
  };
  try {
    if (typeof SFX !== 'undefined' && SFX && typeof SFX === 'object') {
      Object.keys(SFX).forEach(function (k) {
        var orig = SFX[k];
        if (typeof orig !== 'function') return;
        SFX[k] = function () { if (!UX.soundOn()) return; return orig.apply(this, arguments); };
      });
    }
  } catch (e) {}
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sound-toggle]');
    if (!b) return;
    e.preventDefault();
    UX.setSound(!UX.soundOn());
  });

  /* ---------------------------------------------------------------
   *  CALL SHEET — one header button, one list of every way to reach
   *  the company. Numbers come from CONFIG / public settings so the
   *  sheet can never drift from the footer or the ticket.
   * ------------------------------------------------------------- */
  function digits(v) { return String(v || '').replace(/[^0-9]/g, ''); }
  function pretty(d) {
    d = digits(d);
    if (d.length === 12 && d.indexOf('91') === 0) return '+91 ' + d.slice(2, 7) + ' ' + d.slice(7);
    if (d.length === 13 && d.indexOf('977') === 0) return '+977 ' + d.slice(3, 6) + ' ' + d.slice(6);
    return d ? '+' + d : '';
  }
  function callRows() {
    var C = (typeof CONFIG !== 'undefined' && CONFIG) || {}, S = settings();   // CONFIG is a top-level const, not a window property
    var office = digits(S.company_phone || C.phone);
    var wa = digits(S.whatsapp_booking_number || S.company_whatsapp || C.adminWhatsApp);
    var ceo = digits(S.ceo_whatsapp);
    if (!ceo) { var lead = q('.credit-lead[href*="wa.me/"]'); if (lead) ceo = digits((lead.getAttribute('href') || '').split('wa.me/')[1]); }
    var mail = S.company_email || C.email || '';
    var rows = [];
    if (office) rows.push({ k: 'call', ico: '📞', b: tr('csCall', 'Call the office'), s: pretty(office), href: 'tel:+' + office });
    if (wa) rows.push({ k: 'wa', ico: '💬', b: tr('csWa', 'WhatsApp booking desk'), s: pretty(wa), href: 'https://wa.me/' + wa + '?text=' + encodeURIComponent('Namaste 🙏 S Hari Global — I need help with a bus ticket') });
    if (ceo && ceo !== wa) rows.push({ k: 'ceo', ico: '👑', b: tr('csCeo', 'Director on WhatsApp'), s: pretty(ceo), href: 'https://wa.me/' + ceo });
    if (mail) rows.push({ k: 'mail', ico: '✉️', b: tr('csMail', 'Email'), s: mail, href: 'mailto:' + mail });
    /* the admin-managed number strip (Settings > contact numbers) - the same
       list the old header pills showed, minus any number already above */
    try {
      if (typeof contactNumbers === 'function') {
        var seen = {}; rows.forEach(function (r) { seen[digits(r.href.split('?')[0])] = 1; });
        contactNumbers().forEach(function (n) {
          var d = digits(n.num);
          if (!n.show || d.length < 7 || seen[d]) return;
          seen[d] = 1;
          rows.push(n.wa
            ? { k: 'wa', ico: '💬', b: n.label || tr('csWa', 'WhatsApp'), s: pretty(d), href: 'https://wa.me/' + d }
            : { k: 'call', ico: '📞', b: n.label || tr('csCall', 'Call'), s: pretty(d), href: 'tel:+' + d });
        });
      }
    } catch (e) {}
    return rows;
  }
  UX.callRows = callRows;
  function renderCallSheet() {
    var list = q('#callSheetList'); if (!list) return;
    list.innerHTML = callRows().map(function (r) {
      var ext = r.k === 'wa' || r.k === 'ceo' ? ' target="_blank" rel="noopener noreferrer"' : '';
      return '<a class="cs-item" href="' + esc(r.href) + '"' + ext + '><span class="cs-tile ' + r.k + '">' + r.ico + '</span><span><b>' + esc(r.b) + '</b><small>' + esc(r.s) + '</small></span><span class="cs-go">›</span></a>';
    }).join('');
  }
  var lastFocus = null;
  UX.openCallSheet = function () {
    var sh = q('#callSheet'); if (!sh) return;
    renderCallSheet();
    lastFocus = document.activeElement;
    sh.hidden = false;
    document.body.classList.add('sheet-open');
    var first = q('.cs-item', sh); if (first) setTimeout(function () { first.focus(); }, 60);
    try { if (typeof SFX !== 'undefined') SFX.pop(); } catch (e) {}
  };
  UX.closeCallSheet = function () {
    var sh = q('#callSheet'); if (!sh || sh.hidden) return;
    sh.hidden = true;
    document.body.classList.remove('sheet-open');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  };
  document.addEventListener('click', function (e) {
    if (e.target.closest('#navCallBtn') || e.target.closest('[data-open-call]')) { e.preventDefault(); UX.openCallSheet(); return; }
    if (e.target.closest('#callSheetBg') || e.target.closest('#callSheetClose')) { UX.closeCallSheet(); return; }
    if (e.target.closest('#callSheet .cs-item')) { setTimeout(UX.closeCallSheet, 150); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') UX.closeCallSheet(); });

  /* ---------------------------------------------------------------
   *  HOME — booking-channel cards. Their clicks are handled by the
   *  hooks that already exist ([data-wa-open] in 05-router, the
   *  [data-bkmode] delegate in initBookMode); this only adds the
   *  press ripple and a soft pop.
   * ------------------------------------------------------------- */
  document.addEventListener('pointerdown', function (e) {
    var card = e.target.closest('.chan-card'); if (!card || reduced()) return;
    try {
      var r = card.getBoundingClientRect(), d = Math.max(r.width, r.height);
      var rip = document.createElement('span'); rip.className = 'rip';
      rip.style.width = rip.style.height = d + 'px';
      rip.style.left = (e.clientX - r.left - d / 2) + 'px'; rip.style.top = (e.clientY - r.top - d / 2) + 'px';
      card.appendChild(rip); setTimeout(function () { rip.remove(); }, 600);
    } catch (err) {}
  }, { passive: true });
  document.addEventListener('click', function (e) {
    if (e.target.closest('.chan-card')) { try { shgHaptic('select'); SFX.pop(); } catch (err) {} }
  });

  /* ---------------------------------------------------------------
   *  SEARCH CARD — recent + popular route chips, quick dates, swap.
   *  Every chip drives the controls the app already reads
   *  (setSearchDir / #pointSel / the date strip buttons) so results,
   *  fare and checkout see exactly what a manual tap would give.
   * ------------------------------------------------------------- */
  var RECENT_KEY = 'shg:recent';
  var NEP = 'Rupaidiha';
  function townShort(t) { return String(t || '').split(',')[0].replace(/\s*[—–-]\s.*$/, '').trim(); }
  function recentList() { try { var a = JSON.parse(lsGet(RECENT_KEY) || '[]'); return Array.isArray(a) ? a.slice(0, 3) : []; } catch (e) { return []; } }
  function rememberSearch() {
    try {
      var dir = (typeof searchDir !== 'undefined' && searchDir === 'back') ? 'back' : 'go';
      var town = (q('#pointSel') || {}).value || '', date = (q('#dateInput') || {}).value || '';
      if (!town) return;
      var list = recentList().filter(function (r) { return !(r.dir === dir && r.town === town); });
      list.unshift({ dir: dir, town: town, date: date });
      lsSet(RECENT_KEY, JSON.stringify(list.slice(0, 3)));
    } catch (e) {}
  }
  function fmtChipDate(iso) {
    if (!iso) return '';
    try { var d = new Date(iso + 'T00:00:00'); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }); } catch (e) { return iso; }
  }
  function renderSearchChips() {
    var box = q('#sxChips'), sel = q('#pointSel'); if (!box || !sel) return;
    var towns = qa('option', sel).map(function (o) { return o.value; });
    if (!towns.length) { box.hidden = true; return; }
    var dir = (typeof searchDir !== 'undefined' && searchDir === 'back') ? 'back' : 'go';
    var cur = sel.value;
    var html = '';
    var rec = recentList().filter(function (r) { return towns.indexOf(r.town) >= 0 || true; });
    if (rec.length) {
      html += '<span class="sx-lbl">' + esc(tr('sxRecent', 'Recent')) + '</span>';
      rec.forEach(function (r) {
        var lbl = r.dir === 'back' ? NEP + ' → ' + townShort(r.town) : townShort(r.town) + ' → ' + NEP;
        html += '<button type="button" class="sx-chip recent" data-dir="' + r.dir + '" data-town="' + esc(r.town) + '" data-date="' + esc(r.date || '') + '">🕘 ' + esc(lbl) + (r.date ? ' <span class="sx-t">' + esc(fmtChipDate(r.date)) + '</span>' : '') + '</button>';
      });
    }
    /* popular: the first three Gujarat towns of the run outbound, and the first one back */
    var pop = [];
    towns.slice(0, 3).forEach(function (t) { pop.push({ dir: 'go', town: t }); });
    if (towns[0]) pop.push({ dir: 'back', town: towns[0] });
    html += '<span class="sx-lbl">' + esc(tr('sxPopular', 'Popular')) + '</span>';
    pop.forEach(function (r) {
      var on = r.dir === dir && r.town === cur;
      var lbl = r.dir === 'back' ? NEP + ' → ' + townShort(r.town) : townShort(r.town) + ' → ' + NEP;
      html += '<button type="button" class="sx-chip' + (on ? ' on' : '') + '" data-dir="' + r.dir + '" data-town="' + esc(r.town) + '">🔥 ' + esc(lbl) + '</button>';
    });
    box.innerHTML = html; box.hidden = false;
  }
  UX.renderSearchChips = renderSearchChips;
  document.addEventListener('click', function (e) {
    var c = e.target.closest('.sx-chip'); if (!c) return;
    e.preventDefault();
    try {
      var dir = c.getAttribute('data-dir'), town = c.getAttribute('data-town'), date = c.getAttribute('data-date');
      if (typeof setSearchDir === 'function') setSearchDir(dir);
      var sel = q('#pointSel');
      if (sel && town) {
        if (!qa('option', sel).some(function (o) { return o.value === town; })) return;
        sel.value = town; sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (date) { var b = q('#dateStrip30 button[data-iso="' + date + '"]:not([disabled])'); if (b) b.click(); }
      SFX.pop(); shgHaptic('tap');
      renderSearchChips();
    } catch (err) {}
  });
  /* quick dates: tap the matching strip button so 05-router does the rest */
  function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function qdTarget(kind) {
    var strip = q('#dateStrip30'); if (!strip) return null;
    if (kind === 'next') return strip.querySelector('button:not([disabled])');
    var d = new Date(); if (kind === 'tomorrow') d.setDate(d.getDate() + 1);
    return strip.querySelector('button[data-iso="' + iso(d) + '"]');
  }
  function syncQuickDates() {
    qa('.qd-chip[data-qd]').forEach(function (ch) {
      var b = qdTarget(ch.getAttribute('data-qd'));
      ch.disabled = !b || b.disabled;
      ch.classList.toggle('on', !!(b && b.classList.contains('on')));
    });
  }
  document.addEventListener('click', function (e) {
    var ch = e.target.closest('.qd-chip[data-qd]'); if (!ch) return;
    e.preventDefault();
    var b = qdTarget(ch.getAttribute('data-qd'));
    if (b && !b.disabled) { b.click(); try { SFX.pop(); } catch (err) {} }
    setTimeout(syncQuickDates, 30);
  });
  document.addEventListener('change', function (e) {
    if (e.target && (e.target.id === 'dateInput' || e.target.id === 'pointSel')) { setTimeout(syncQuickDates, 0); setTimeout(renderSearchChips, 0); }
  });
  document.addEventListener('click', function (e) {
    if (e.target.closest('#dirGo, #dirBack')) setTimeout(renderSearchChips, 0);
    var sw = e.target.closest('#dirSwap'); if (!sw) return;
    e.preventDefault();
    try {
      sw.classList.toggle('spin');
      var dir = (typeof searchDir !== 'undefined' && searchDir === 'back') ? 'go' : 'back';
      if (typeof setSearchDir === 'function') setSearchDir(dir);
      SFX.pop(); shgHaptic('tap');
      setTimeout(renderSearchChips, 0);
    } catch (err) {}
  });
  var sf = q('#searchForm');
  if (sf) sf.addEventListener('submit', function () { rememberSearch(); try { SFX.click(); } catch (e) {} }, true);
  /* first paint: the strip and the town list are filled by boot, which runs
     after this file; wait for both without touching either. */
  (function waitSearch(n) {
    var sel = q('#pointSel'), strip = q('#dateStrip30');
    if (sel && sel.options.length && strip && strip.children.length) { renderSearchChips(); syncQuickDates(); UX.setSound(UX.soundOn()); return; }
    if (n < 40) setTimeout(function () { waitSearch(n + 1); }, 250);
  }(0));

  /* ---------------------------------------------------------------
   *  SEAT SCREEN — boarding-point dots under the key, 1F/2F labels on
   *  the floor tabs. Reads the selects and tabs 06-results renders;
   *  never touches the grid.
   * ------------------------------------------------------------- */
  var BP_COLOR = { STV: 'var(--bp-srt)', BRH: 'var(--bp-brd)', EMB: 'var(--bp-ebp)', AMD: 'var(--bp-shp)', MSN: 'var(--bp-mhsa)', RPD: 'var(--bp-rpd)', BRC: 'var(--sky-deep)', ANA: 'var(--wa-deep)', NAD: 'var(--bot)', KMJ: 'var(--gold-deep)', AKV: '#0E7490' };
  function renderBpDots() {
    var box = q('#bpDots'), bsel = q('#boardingSel'), dsel = q('#dropSel'); if (!box) return;
    if (!bsel || !bsel.options.length || typeof stopDisplay !== 'function') { box.hidden = true; return; }
    var stops = qa('option', bsel).map(function (o) { return o.value; }).filter(function (v) { return v && !/^other/i.test(v); });
    var drops = dsel ? qa('option', dsel).map(function (o) { return o.value; }).filter(function (v) { return v && !/^other/i.test(v); }) : [];
    var end = drops.length ? drops[drops.length - 1] : '';
    if (stops.length < 2 && !end) { box.hidden = true; return; }
    var html = stops.map(function (v) {
      var d = stopDisplay(v, v), on = v === bsel.value;
      return '<span class="bp-dot' + (on ? ' on' : '') + '" style="--bp-c:' + (BP_COLOR[d.code] || 'var(--brand)') + '"><i></i>' + esc(d.code) + '<small>' + esc(townShort(d.name || v)) + '</small></span>';
    }).join('');
    if (end) { var e2 = stopDisplay(end, end); html += '<span class="bp-dot end" style="--bp-c:' + (BP_COLOR[e2.code] || 'var(--bp-rpd)') + '"><i></i>' + esc(e2.code) + '<small>' + esc(townShort(e2.name || end)) + '</small></span>'; }
    box.innerHTML = html; box.hidden = false;
  }
  UX.renderBpDots = renderBpDots;
  function relabelDeckTabs() {
    qa('#deckTabs .deck-tab').forEach(function (b) {
      var k = b.getAttribute('data-deck');
      var lbl = k === 'L' ? tr('deck1F', '1F · Lower') : k === 'U' ? tr('deck2F', '2F · Upper') : tr('deckAll', 'All');
      if (b.textContent !== lbl) b.textContent = lbl;
    });
  }
  document.addEventListener('change', function (e) { if (e.target && e.target.id === 'boardingSel') renderBpDots(); });
  try {
    var mo = new MutationObserver(function () { relabelDeckTabs(); renderBpDots(); });
    var tabs = q('#deckTabs'); if (tabs) mo.observe(tabs, { childList: true });
    var bsel0 = q('#boardingSel'); if (bsel0) mo.observe(bsel0, { childList: true });
  } catch (e) {}
  window.addEventListener('hashchange', function () { if (location.hash.indexOf('#/seats') === 0) setTimeout(function () { relabelDeckTabs(); renderBpDots(); }, 500); });

}());
