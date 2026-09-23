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

}());
