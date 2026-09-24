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
    if (d.length === 13 && d.indexOf('977') === 0) return '+977 ' + d.slice(3, 8) + ' ' + d.slice(8);   // Nepal mobiles: 98xxx xxxxx
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
          /* a 10-digit Nepal mobile (98x/97x/96x) written without its code gets +977 */
          if (d.length === 10 && /^9[6-8]/.test(d) && /nepalgunj|npj|nepal/i.test(n.label || '')) d = '977' + d;
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
    var rows = callRows();
    /* Two groups: 🇳🇵 Nepalgunj branch (the +977 numbers) and 🇮🇳 India offices.
       A Nepali-language visitor sees the NPJ staff first (owner, 23 Sep 2026). */
    var np = rows.filter(function (r) { return /(^|[^0-9])977\d{9,10}/.test(r.href.replace(/\+/, '')); });
    var rest = rows.filter(function (r) { return np.indexOf(r) < 0; });
    var neFirst = (typeof LANG === 'string' && LANG === 'ne');
    var row = function (r) {
      var ext = r.k === 'wa' || r.k === 'ceo' ? ' target="_blank" rel="noopener noreferrer"' : '';
      return '<a class="cs-item" href="' + esc(r.href) + '"' + ext + '><span class="cs-tile ' + r.k + '">' + r.ico + '</span><span><b>' + esc(r.b) + '</b><small>' + esc(r.s) + '</small></span><span class="cs-go">›</span></a>';
    };
    var grp = function (lbl, arr) { return arr.length ? '<div class="cs-grp">' + esc(lbl) + '</div>' + arr.map(row).join('') : ''; };
    var npHtml = grp('🇳🇵 ' + tr('csNpj', 'Nepalgunj branch · NPJ staff'), np);
    var inHtml = grp('🇮🇳 ' + tr('csIndia', 'India · offices & booking desk'), rest);
    list.innerHTML = neFirst ? npHtml + inHtml : inHtml + npHtml;
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

  /* ---------------------------------------------------------------
   *  CHECKOUT — one smart form: Passengers (name · gender toggle) first,
   *  Contact second; the gender <select> stays the source of truth and
   *  drives a 3-way toggle. Rows are re-rendered by renderCheckout, so a
   *  MutationObserver decorates whatever appears.
   * ------------------------------------------------------------- */
  try { if (!(boot().staff && boot().staff.canSell)) document.body.classList.add('ux-guest'); } catch (e) {}
  function tagCheckoutCards() {
    var cards = qa('#coStep1 > .co-card'); if (!cards.length) return;
    cards.forEach(function (c) {
      if (c.querySelector('#paxRows')) c.classList.add('ux-pax');
      else if (c.querySelector('#cAgentCode')) c.classList.add('ux-agent');
    });
    var n = 0;
    ['.ux-pax', ':not(.ux-pax):not(.ux-agent)'].forEach(function (sel) {
      var c = q('#coStep1 > .co-card' + sel); var dot = c && c.querySelector('.stepdot');
      if (dot) dot.textContent = String(++n);
    });
  }
  function bindGender(sel) {
    if (sel.classList.contains('ux-bound')) return;
    sel.classList.add('ux-bound');
    var wrap = document.createElement('div'); wrap.className = 'gtog'; wrap.setAttribute('role', 'group');
    qa('option', sel).forEach(function (o) {
      if (!o.value) return;
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('data-g', o.value); b.textContent = o.textContent;
      b.className = o.value === sel.value ? 'on' : '';
      b.addEventListener('click', function () {
        sel.value = o.value;
        qa('button', wrap).forEach(function (x) { x.classList.toggle('on', x === b); });
        sel.dispatchEvent(new Event('change', { bubbles: true })); sel.dispatchEvent(new Event('input', { bubbles: true }));
        try { shgHaptic('tap'); SFX.pop(); } catch (e) {}
      });
      wrap.appendChild(b);
    });
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    sel.addEventListener('change', function () { qa('button', wrap).forEach(function (x) { x.classList.toggle('on', x.getAttribute('data-g') === sel.value); }); });
  }
  function decorateCheckout() { tagCheckoutCards(); qa('#paxRows .pxGender').forEach(bindGender); }
  try {
    var pr = q('#paxRows'); if (pr) new MutationObserver(decorateCheckout).observe(pr, { childList: true });
    decorateCheckout();
  } catch (e) {}
  window.addEventListener('hashchange', function () { if (location.hash.indexOf('#/checkout') === 0) setTimeout(decorateCheckout, 300); });

  /* ---------------------------------------------------------------
   *  SEAT-HOLD BAR — mirrors the existing pill text into one slim
   *  sticky strip. updateHoldPills is a top-level function declaration
   *  in 05-router (a window binding), so wrapping it here catches every
   *  tick of the same timer; nothing about the hold itself changes.
   * ------------------------------------------------------------- */
  var holdTotalMs = 0;
  function syncHoldBar() {
    var bar = q('#holdBar'), txt = q('#holdBarTxt'), fill = q('#holdBarFill'); if (!bar) return;
    var h = location.hash || '#/';
    var onFlow = h.indexOf('#/seats') === 0 || h.indexOf('#/checkout') === 0;
    var pill = qa('.hold-pill').filter(function (p) { return !p.classList.contains('hide') && p.textContent.trim(); })[0];
    var exp = 0; try { exp = typeof myHoldExpiry === 'function' ? (myHoldExpiry() || 0) : 0; } catch (e) {}
    if (!onFlow || !pill || !exp) { bar.hidden = true; document.body.classList.remove('ux-holdbar'); holdTotalMs = 0; return; }
    var left = Math.max(0, exp - Date.now());
    if (!holdTotalMs || left > holdTotalMs) holdTotalMs = Math.max(left, ((((typeof CONFIG !== 'undefined' && CONFIG.booking) || {}).seatHoldMinutes || 30) * 60000));
    txt.textContent = pill.textContent.replace(/^⏳\s*/, '');
    if (fill) fill.style.transform = 'scaleX(' + (left / holdTotalMs).toFixed(3) + ')';
    bar.classList.toggle('hb-low', left < 3 * 60000);
    bar.hidden = false; document.body.classList.add('ux-holdbar');
  }
  try {
    if (typeof updateHoldPills === 'function') {
      var _uhp = updateHoldPills;
      window.updateHoldPills = function () { var r = _uhp.apply(this, arguments); try { syncHoldBar(); } catch (e) {} return r; };
    }
  } catch (e) {}
  window.addEventListener('hashchange', function () { setTimeout(syncHoldBar, 50); });

  /* ---------------------------------------------------------------
   *  HELP — the bottom-nav "Help" tab and any [data-open-help] open the
   *  Sahayak bot (it used to scroll to the contact section); the FAB
   *  carries a "Help" word beside its icon (localised via bnHelp).
   * ------------------------------------------------------------- */
  function labelHelpFab() { var f = q('#aiFab'); if (f) f.setAttribute('data-help', tr('bnHelp', 'Help')); }
  labelHelpFab();
  document.addEventListener('click', function (e) {
    var l = e.target.closest('.lang-btn'); if (l) setTimeout(labelHelpFab, 50);
    var h = e.target.closest('.bn-item[data-bn="help"], [data-open-help]'); if (!h) return;
    var fab = q('#aiFab'); if (!fab) return;
    e.preventDefault(); e.stopPropagation();
    var panel = q('#aiPanel');
    if (!panel || getComputedStyle(panel).display === 'none') fab.click();
    try { shgHaptic('tap'); } catch (err) {}
  }, true);

  /* ---------------------------------------------------------------
   *  PRIVATE CABIN MARKETING (brief §6) — visual + one nudge; the seat
   *  engine, prices and the mode switch are the existing ones.
   *   - private mode: a gold "Private · Comfort" band over the map with
   *     the per-cabin prices and a "Why private?" pop.
   *   - sharing mode: when the two chosen berths share one cabin, offer
   *     the whole private cabin for its price in one tap (switches the
   *     mode through the existing pill, then re-selects the same beds).
   * ------------------------------------------------------------- */
  function pvtPrices() {
    var P = ((typeof CONFIG !== 'undefined' && CONFIG.cabinPricing) || {}).private || {};
    var s = (P.single_1pax || {}).offline || 0, d = (P.double_2pax || {}).offline || 0;
    return { single: s, double: d };
  }
  function money(n) { try { return typeof inr === 'function' ? inr(n) : '₹' + Number(n).toLocaleString('en-IN'); } catch (e) { return '₹' + n; } }
  function renderPvtBand() {
    var grid = q('#seatGrid'), frame = q('.bus-frame'); if (!grid || !frame) return;
    var band = q('#pvtBand');
    var isPvt = !!(typeof Flow !== 'undefined' && Flow.bookingType === 'private' && Flow.route && Flow.route.type === 'sleeper');
    if (!isPvt) { if (band) band.hidden = true; return; }
    var p = pvtPrices();
    if (!band) {
      band = document.createElement('div'); band.id = 'pvtBand'; band.className = 'pvt-band';
      frame.parentNode.insertBefore(band, frame);
    }
    band.innerHTML = '<span class="pvt-badge">👑 ' + esc(tr('pvtBadge', 'Private · Comfort')) + '</span>'
      + '<span class="pvt-price">' + (p.single ? esc(tr('pvtSingle', 'Single cabin')) + ' <b>' + money(p.single) + '</b>' : '')
      + (p.double ? ' · ' + esc(tr('pvtDouble', 'Double cabin')) + ' <b>' + money(p.double) + '</b>' : '') + '</span>'
      + '<button type="button" class="pvt-why" data-pvt-why aria-expanded="false">' + esc(tr('pvtWhy', 'Why private?')) + '</button>'
      + '<div class="pvt-pop" hidden><b>' + esc(tr('pvtWhyT', 'Your cabin, nobody else')) + '</b><ul>'
      + '<li>🔒 ' + esc(tr('pvtWhy1', 'Full privacy - the door is yours')) + '</li>'
      + '<li>🛏️ ' + esc(tr('pvtWhy2', 'More space, own light and charging')) + '</li>'
      + '<li>👨‍👩‍👧 ' + esc(tr('pvtWhy3', 'Perfect for couples, family, friends')) + '</li></ul></div>';
    band.hidden = false;
  }
  document.addEventListener('click', function (e) {
    var w = e.target.closest('[data-pvt-why]');
    if (w) { var pop = w.parentNode.querySelector('.pvt-pop'); if (pop) { pop.hidden = !pop.hidden; w.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true'); } return; }
    if (!e.target.closest('#pvtBand')) { var op = q('#pvtBand .pvt-pop'); if (op && !op.hidden) { op.hidden = true; var b = q('#pvtBand [data-pvt-why]'); if (b) b.setAttribute('aria-expanded', 'false'); } }
  });
  function renderUpsell() {
    var host = q('#fareRows'); if (!host) return;
    var old = q('#pvtUpsell');
    var ok = false, price = 0, beds = [];
    try {
      if (typeof Flow !== 'undefined' && Flow.bookingType === 'sharing' && Flow.route && Flow.route.type === 'sleeper'
          && Array.isArray(Flow.seats) && Flow.seats.length === 2 && typeof unitKeyJS === 'function') {
        var a = Flow.seats[0], b = Flow.seats[1];
        ok = unitKeyJS(a, Flow.route.type) === unitKeyJS(b, Flow.route.type) && /^[LU]\d+$/i.test(a) && /^[LU]\d+$/i.test(b);
        price = pvtPrices().double; beds = [a, b];
      }
    } catch (e) { ok = false; }
    if (!ok || !price) { if (old) old.remove(); return; }
    if (!old) { old = document.createElement('div'); old.id = 'pvtUpsell'; old.className = 'pvt-upsell'; host.parentNode.insertBefore(old, host.nextSibling); }
    old.innerHTML = '<span class="pu-ico">👑</span><span class="pu-txt"><b>' + esc(tr('pvtUpT', 'Make it a private cabin?')) + '</b><small>'
      + esc(tr('pvtUpS', 'These two berths are one cabin - book it whole, nobody else inside')) + '</small></span>'
      + '<button type="button" class="btn btn-sm pu-btn" data-pvt-upsell="' + esc(beds.join(',')) + '">' + esc(tr('pvtUpBtn', 'Book full cabin')) + ' · ' + money(price) + '</button>';
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-pvt-upsell]'); if (!b) return;
    e.preventDefault();
    var beds = (b.getAttribute('data-pvt-upsell') || '').split(',').filter(Boolean);
    var pill = q('#cabinToggle .bt-pill[data-bt="private"]'); if (!pill) return;
    try { SFX.select(); shgHaptic('select'); } catch (err) {}
    pill.click();                                          // the existing mode switch (releases holds, re-renders)
    var tries = 0;
    (function pick() {
      var grid = q('#seatGrid'); var first = grid && grid.querySelector('.seat[data-id="' + beds[0] + '"]');
      if (!first || first.disabled) { if (++tries < 12) return setTimeout(pick, 250); return; }
      if (!first.classList.contains('sel')) first.click();   // in Double-cabin tier the pair toggles together
      setTimeout(function () {
        var second = grid.querySelector('.seat[data-id="' + beds[1] + '"]');
        if (second && !second.disabled && !second.classList.contains('sel')) second.click();
      }, 120);
    }());
  });
  try {
    if (typeof updateSeatSummary === 'function') {
      var _uss = updateSeatSummary;
      window.updateSeatSummary = function () { var r = _uss.apply(this, arguments); try { renderUpsell(); renderPvtBand(); } catch (e) {} return r; };
    }
  } catch (e) {}
  try { var sg = q('#seatGrid'); if (sg) new MutationObserver(function () { renderPvtBand(); }).observe(sg, { childList: true }); } catch (e) {}

  /* ---------------------------------------------------------------
   *  AI EXTRAS (brief §10) — each behind a public setting, each a
   *  no-op while off, none touch first paint.
   * ------------------------------------------------------------- */
  var flag = function (k) { try { var v = settings()[k]; return v === true || v === 1 || v === '1' || v === 'true'; } catch (e) { return false; } };

  /* 🎤 Voice search on the search card: "Surat to Rupaidiha kal" ->
     direction + town + date through the same controls a finger uses. */
  (function voiceSearch() {
    var mic = q('#sxMic'); var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!mic || !SR || !flag('ux_voice_search_on')) return;
    mic.hidden = false;
    var rec = null;
    function norm(s) { return String(s || '').toLowerCase(); }
    function apply(text) {
      var tx = norm(text), hit = 0;
      /* direction */
      if (/nepal|rupaidiha|rupaidih|रुपैडिहा|रूपैडीहा|नेपाल|jane|जाने/.test(tx)) { if (typeof setSearchDir === 'function') setSearchDir('go'); hit++; }
      else if (/return|wapas|farki|फर्क|वापस|gujarat|गुजरात/.test(tx)) { if (typeof setSearchDir === 'function') setSearchDir('back'); hit++; }
      /* town: the first option whose first word appears in the sentence */
      var sel = q('#pointSel');
      if (sel) {
        var match = qa('option', sel).filter(function (o) { var w = norm(o.value).split(/[ ,]/)[0]; return w.length > 3 && tx.indexOf(w) >= 0; })[0];
        if (match) { sel.value = match.value; sel.dispatchEvent(new Event('change', { bubbles: true })); hit++; }
      }
      /* date */
      var d = null;
      if (/parso|परसो|परसी|day after/.test(tx)) d = 2; else if (/tomorrow|kal\b|काल|कल\b|भोलि|bholi/.test(tx)) d = 1; else if (/today|aaj|आज/.test(tx)) d = 0;
      if (d !== null) {
        var dt = new Date(); dt.setDate(dt.getDate() + d);
        var b = q('#dateStrip30 button[data-iso="' + iso(dt) + '"]:not([disabled])'); if (b) { b.click(); hit++; }
      }
      if (hit) { try { SFX.pop(); shgHaptic('tap'); } catch (e) {} renderSearchChips(); }
      try { toast(hit ? tr('sxMicOk', 'Got it - check and tap Search') : tr('sxMicMiss', 'Did not catch a town or date - try "Surat to Rupaidiha tomorrow"')); } catch (e) {}
    }
    mic.addEventListener('click', function () {
      if (rec) { try { rec.stop(); } catch (e) {} return; }
      try {
        rec = new SR();
        var map = { en: 'en-IN', hi: 'hi-IN', ne: 'ne-NP', gu: 'gu-IN' };
        rec.lang = map[typeof LANG === 'string' ? LANG : 'ne'] || 'en-IN';
        rec.interimResults = false; rec.maxAlternatives = 1;
        mic.classList.add('listening');
        rec.onresult = function (ev) { var out = ''; for (var i = 0; i < ev.results.length; i++) out += ev.results[i][0].transcript; apply(out); };
        rec.onend = function () { mic.classList.remove('listening'); rec = null; };
        rec.onerror = function () { mic.classList.remove('listening'); rec = null; };
        rec.start();
      } catch (e) { mic.classList.remove('listening'); rec = null; }
    });
  }());

  /* 📍 Nearest pickup: one chip that hands the question to the Help bot's
     own GPS routine (it already knows every stop's coordinates). */
  (function nearestStop() {
    if (!flag('ux_nearest_stop_on') || !navigator.geolocation) return;
    var row = q('#qdRow'); if (!row) return;
    var chip = document.createElement('button'); chip.type = 'button'; chip.className = 'chip qd-chip qd-near';
    chip.textContent = '📍 ' + tr('sxNearest', 'Nearest pickup');
    row.insertBefore(chip, row.querySelector('.st-chip'));
    chip.addEventListener('click', function () {
      var fab = q('#aiFab'), inp = q('#aiInput'), send = q('#aiSend'); if (!fab || !inp || !send) return;
      var panel = q('#aiPanel'); if (!panel || getComputedStyle(panel).display === 'none') fab.click();
      setTimeout(function () { inp.value = 'nearest boarding point'; send.click(); }, 250);
    });
  }());

  /* ---------------------------------------------------------------
   *  BOOKED! — a one-shot tick + confetti the moment the ticket page
   *  opens after "Confirm your seat" (the old celebration only played
   *  once staff had verified; the passenger saw nothing at submit).
   * ------------------------------------------------------------- */
  var submitTs = 0;
  document.addEventListener('click', function (e) { if (e.target.closest('#submitBookingBtn')) submitTs = Date.now(); }, true);
  window.addEventListener('hashchange', function () {
    if (location.hash.indexOf('#/ticket/') !== 0 || !submitTs || Date.now() - submitTs > 20000) return;
    submitTs = 0;
    try {
      var ov = document.createElement('div'); ov.className = 'cel'; ov.setAttribute('role', 'status');
      var bits = ''; for (var i = 0; i < 18; i++) bits += '<i style="--x:' + (Math.random() * 100).toFixed(1) + '%;--d:' + (Math.random() * .6).toFixed(2) + 's;--c:' + ['#F07C1F', '#0054A8', '#25D366', '#F2C14E', '#38A8F5'][i % 5] + '"></i>';
      ov.innerHTML = '<div class="cel-card"><div class="cel-tick"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-17"/></svg></div><b>' + esc(tr('celT', 'Seat request sent!')) + '</b><small>' + esc(tr('celS', 'We verify your payment and send the ticket on WhatsApp.')) + '</small></div><div class="cel-bits">' + bits + '</div>';
      document.body.appendChild(ov);
      try { shgHaptic('success'); } catch (err) {}
      setTimeout(function () { ov.classList.add('out'); setTimeout(function () { ov.remove(); }, 400); }, reduced() ? 1200 : 2600);
      ov.addEventListener('click', function () { ov.remove(); });
    } catch (e) {}
  });

  /* ---------------------------------------------------------------
   *  ANIMATION GOVERNOR (24 Sep 2026) — the page carries ~100 looping
   *  decorations. Only the ones on screen may run: every top-level
   *  block of every view (and the fixed chrome) is watched, and gets
   *  .anim-off while it is out of the viewport; a hidden tab pauses
   *  everything. Purely additive - remove this block and nothing else
   *  changes.
   * ------------------------------------------------------------- */
  (function governor() {
    if (!('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { e.target.classList.toggle('anim-off', !e.isIntersecting); });
    }, { rootMargin: '120px 0px' });
    function watch() {
      qa('.view > *, .view > .container > *, .credit-ribbon, .divine-bar, .num-strip, footer, .shg-journey, .ra-wrap').forEach(function (el) {
        if (el._ux_io) return; el._ux_io = true; io.observe(el);
      });
    }
    watch();
    window.addEventListener('hashchange', function () { setTimeout(watch, 400); });
    document.addEventListener('visibilitychange', function () { document.body.classList.toggle('tab-hidden', document.hidden); });
  }());

}());
