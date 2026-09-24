/* =====================================================================
 *  17-pwa.js — the PWA layer + the new passenger features (13 Sep 2026)
 *
 *  Loads last (defer), depends on the globals the earlier files define
 *  ($, t, tf, esc, toast, openModal, routeById, isNepalPoint, depTimestamp,
 *  inr, digits, shgApi, Flow, SrvCatalog, LANG, CONFIG, DB) and exposes:
 *
 *    window.SHGInstall  — the install bottom sheet (Android prompt + iOS steps)
 *    window.SHGPush     — Web Push subscribe / unsubscribe + the ticket card
 *    window.SHGBorder   — Border Crossing Prep card (India → Nepal tickets)
 *    window.SHGSplit    — group booking split-pay (per-friend UPI links)
 *    window.OccChart    — 7-day seat-fill chart on the results board
 *
 *  Every entry point is guarded: a failure inside any of these must never
 *  take the booking flow down. Nothing here touches fares, seats or holds.
 * ===================================================================== */
(function () {
  'use strict';

  var BOOT = window.SHG_BOOT || {};
  var T  = function (k) { return (typeof t === 'function') ? t(k) : k; };
  var TF = function (k, v) { return (typeof tf === 'function') ? tf(k, v) : k; };
  var E  = function (s) { return (typeof esc === 'function') ? esc(s) : String(s == null ? '' : s); };
  var q  = function (s, r) { return (r || document).querySelector(s); };
  var say = function (m) { try { if (typeof toast === 'function') toast(m); } catch (e) {} };
  var beacon = function (n, p) { try { if (typeof window.shgBeacon === 'function') window.shgBeacon(n, p); } catch (e) {} };
  var lsGet = function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } };
  var lsSet = function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} };
  var lsDel = function (k) { try { localStorage.removeItem(k); } catch (e) {} };
  var settings = function () { return (BOOT && BOOT.settings) || {}; };
  var lang = function () { return (typeof LANG !== 'undefined' && LANG) ? LANG : 'ne'; };
  var isStandalone = function () {
    try { return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true; }
    catch (e) { return false; }
  };
  var ua = navigator.userAgent || '';
  var isIOS = /iPhone|iPad|iPod/i.test(ua) && !window.MSStream;
  var isIOSSafari = isIOS && /Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua);

  /* ================================================================
     1. INSTALL SHEET
     Android/desktop Chrome fire `beforeinstallprompt`; we hold the event
     and show our own bottom sheet (benefits in the passenger's language,
     44px targets). iOS has no prompt at all — Safari gets a two-step
     "Share → Add to Home Screen" sheet instead. Dismissed = quiet for
     30 days; installed = never again.
  ================================================================ */
  var SHGInstall = (function () {
    var DKEY = 'shg:installDismiss', VKEY = 'shg:visits';
    var deferred = null, shown = false;
    var visits = (parseInt(lsGet(VKEY) || '0', 10) || 0) + 1;
    lsSet(VKEY, String(visits));

    function quiet() {
      if (isStandalone()) return true;
      var at = +(lsGet(DKEY) || 0);
      return at && (Date.now() - at) < 30 * 86400000;
    }
    function dismiss() { lsSet(DKEY, String(Date.now())); hide(); }
    function hide() {
      var s = q('#pwaSheet'), b = q('#pwaBackdrop');
      if (s) { s.classList.remove('open'); setTimeout(function () { if (s.parentNode) s.parentNode.removeChild(s); }, 320); }
      if (b) { b.classList.remove('open'); setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 320); }
    }
    function sheet(inner) {
      if (q('#pwaSheet')) return;
      var bd = document.createElement('div'); bd.className = 'pwa-backdrop'; bd.id = 'pwaBackdrop';
      var s = document.createElement('div'); s.className = 'pwa-sheet'; s.id = 'pwaSheet';
      s.setAttribute('role', 'dialog'); s.setAttribute('aria-modal', 'true'); s.setAttribute('aria-label', T('pwaInstallT'));
      s.innerHTML = '<div class="pwa-grab" aria-hidden="true"></div>' + inner;
      document.body.appendChild(bd); document.body.appendChild(s);
      requestAnimationFrame(function () { bd.classList.add('open'); s.classList.add('open'); });
      bd.addEventListener('click', dismiss);
      var no = q('#pwaInstallNo'); if (no) no.addEventListener('click', dismiss);
      shown = true;
      beacon('install_prompt', { source: deferred ? 'android' : 'ios' });
    }
    function head() {
      return '<div class="pwa-head"><img src="/assets/img/icon-192.png?v=20260920l" alt="" width="52" height="52">'
        + '<div><b>' + E(T('pwaInstallT')) + '</b><small>' + E(T('pwaInstallP')) + '</small></div></div>';
    }
    function showAndroid() {
      if (!deferred || quiet() || shown) return;
      sheet(head()
        + '<ul class="pwa-benefits"><li>⚡ ' + E(T('pwaB1')) + '</li><li>🎫 ' + E(T('pwaB2')) + '</li><li>🔔 ' + E(T('pwaB3')) + '</li></ul>'
        + '<div class="pwa-actions"><button type="button" class="btn btn-orange" id="pwaInstallGo">📱 ' + E(T('pwaInstallBtn')) + '</button>'
        + '<button type="button" class="btn btn-ghost" id="pwaInstallNo">' + E(T('pwaLater')) + '</button></div>');
      var go = q('#pwaInstallGo');
      if (go) go.addEventListener('click', function () {
        var p = deferred; deferred = null; hide();
        if (!p) return;
        try {
          p.prompt();
          if (p.userChoice) p.userChoice.then(function (c) {
            beacon('install_prompt', { ok: !!(c && c.outcome === 'accepted') });
            if (!c || c.outcome !== 'accepted') lsSet(DKEY, String(Date.now()));
          }).catch(function () {});
        } catch (e) {}
      });
    }
    function showIOS() {
      if (!isIOSSafari || quiet() || shown) return;
      sheet(head()
        + '<ol class="pwa-steps"><li><span class="pwa-ico">' + shareIcon() + '</span>' + E(T('pwaIos1')) + '</li>'
        + '<li><span class="pwa-ico">➕</span>' + E(T('pwaIos2')) + '</li>'
        + '<li><span class="pwa-ico">✅</span>' + E(T('pwaIos3')) + '</li></ol>'
        + '<div class="pwa-actions"><button type="button" class="btn btn-blue" id="pwaInstallNo">' + E(T('pwaGotIt')) + '</button></div>');
    }
    function shareIcon() {
      return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 3l4 4h-3v8h-2V7H8l4-4zm-7 9h2v7h10v-7h2v9H5v-9z"/></svg>';
    }
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault(); deferred = e;
      if (quiet()) return;
      // Not on top of the checkout: the sheet waits for the home screen.
      var later = function () {
        var h = location.hash || '#/';
        if (h === '#/' || h === '#/my') showAndroid(); else setTimeout(later, 15000);
      };
      /* 20 Sep 2026 (brief P5): was a flat 4 s, so a first-time visitor on
         Android - which is nearly everyone on this route - got a modal over
         the booking form before they had read it or typed anything. iOS has
         waited since the sheet was written ("never on first paint"); Android
         now follows the same rule: 25 s on the first visit, 6 s after that. */
      setTimeout(later, visits >= 2 ? 6000 : 25000);
    });
    window.addEventListener('appinstalled', function () {
      lsDel(DKEY); hide(); say('✅ ' + T('pwaDone'));
      beacon('install_prompt', { ok: true, source: 'installed' });
    });
    // iOS: second visit or 25 s of use, whichever comes first — never on first paint.
    if (isIOSSafari && !isStandalone() && !quiet()) {
      setTimeout(function () { if ((location.hash || '#/') === '#/') showIOS(); }, visits >= 2 ? 6000 : 25000);
    }
    return {
      available: function () { return !!deferred || (isIOSSafari && !isStandalone()); },
      show: function () { if (deferred) { shown = false; showAndroid(); } else if (isIOSSafari) { shown = false; showIOS(); } else say(T('pwaNotNow')); },
      hide: hide
    };
  })();
  window.SHGInstall = SHGInstall;

  /* ================================================================
     2. WEB PUSH — subscribe this phone for delay alerts + reminders.
  ================================================================ */
  function b64uToBytes(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  var SHGPush = {
    KEY: 'shg:push',
    supported: function () {
      return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
        && !!(BOOT.push && BOOT.push.on && BOOT.push.key) && location.protocol.indexOf('http') === 0;
    },
    reg: function () { return navigator.serviceWorker.ready; },
    current: function () { return this.reg().then(function (r) { return r.pushManager.getSubscription(); }); },
    state: function () {
      if (!this.supported()) return Promise.resolve('unsupported');
      if (Notification.permission === 'denied') return Promise.resolve('denied');
      return this.current().then(function (s) { return s ? 'on' : 'off'; }).catch(function () { return 'off'; });
    },
    remember: function (pnr) {
      var m = {}; try { m = JSON.parse(lsGet(this.KEY) || '{}'); } catch (e) {}
      m.on = true; m.at = Date.now(); m.pnrs = m.pnrs || [];
      if (pnr && m.pnrs.indexOf(pnr) < 0) m.pnrs.push(pnr);
      lsSet(this.KEY, JSON.stringify(m));
    },
    known: function (pnr) { try { var m = JSON.parse(lsGet(this.KEY) || '{}'); return !!(m.pnrs && m.pnrs.indexOf(pnr) >= 0); } catch (e) { return false; } },
    subscribe: function (pnr, k) {
      var self = this;
      if (!self.supported()) return Promise.reject(new Error('unsupported'));
      return Notification.requestPermission().then(function (perm) {
        if (perm !== 'granted') { var e = new Error('denied'); e.code = 'denied'; throw e; }
        return self.reg();
      }).then(function (reg) {
        return reg.pushManager.getSubscription().then(function (s) {
          return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(BOOT.push.key) });
        });
      }).then(function (sub) {
        var body = { action: 'subscribe', subscription: sub.toJSON(), lang: lang() };
        if (pnr) body.pnr = pnr;
        if (k) body.k = k;
        return shgApi.post('/push.php', body).then(function () { self.remember(pnr); return sub; });
      });
    },
    unsubscribe: function () {
      var self = this;
      return self.current().then(function (sub) {
        if (!sub) return false;
        return shgApi.post('/push.php', { action: 'unsubscribe', endpoint: sub.endpoint }).catch(function () {})
          .then(function () { return sub.unsubscribe(); })
          .then(function () { lsDel(self.KEY); return true; });
      });
    },
    test: function () {
      return this.current().then(function (sub) {
        if (!sub) throw new Error('off');
        return shgApi.post('/push.php', { action: 'test', endpoint: sub.endpoint });
      });
    },
    /* Silent: permission already granted + subscription exists → make sure
       THIS ticket is tied to it (a passenger who allowed alerts on an earlier
       ticket should get this trip's delay too). */
    autoSync: function (b) {
      var self = this;
      if (!b || !b.id || !self.supported() || Notification.permission !== 'granted' || self.known(b.id)) return;
      self.current().then(function (sub) {
        if (!sub) return;
        return shgApi.post('/push.php', { action: 'subscribe', subscription: sub.toJSON(), pnr: b.id, lang: lang() })
          .then(function () { self.remember(b.id); });
      }).catch(function () {});
    },
    /* The card on the ticket screen. Rendered as HTML, then wire() binds. */
    card: function (b) {
      if (!b || (b.status !== 'confirmed' && b.status !== 'pending')) return '';
      // Server push off (no VAPID key): say nothing rather than blame the phone.
      if (!(BOOT.push && BOOT.push.on && BOOT.push.key)) return '';
      // iPhone Safari exposes no Notification until the app is installed: show the install hint.
      if (!('Notification' in window) && !(isIOS && !isStandalone())) return '';
      var now = Date.now();
      var dep = (typeof depTimestamp === 'function') ? depTimestamp(b) : 0;
      if (dep && dep < now - 6 * 3600000) return '';     // the trip is over
      return '<div class="push-card" id="pushCard" data-pnr="' + E(b.id) + '">'
        + '<div class="push-ico" aria-hidden="true">🔔</div>'
        + '<div class="push-txt"><b>' + E(T('pushT')) + '</b><small id="pushSub">' + E(T('pushP')) + '</small></div>'
        + '<div class="push-act"><button type="button" class="btn btn-blue btn-sm" id="pushBtn">' + E(T('pushOn')) + '</button></div>'
        + '</div>';
    },
    wire: function (b) {
      var self = this, card = q('#pushCard'); if (!card) return;
      var btn = q('#pushBtn'), sub = q('#pushSub');
      var paint = function (st) {
        card.setAttribute('data-state', st);
        if (st === 'unsupported') {
          if (isIOS && !isStandalone()) { sub.textContent = T('pushIosHint'); btn.textContent = T('pwaInstallBtn'); btn.onclick = function () { SHGInstall.show(); }; }
          else { sub.textContent = T('pushUnsupported'); btn.style.display = 'none'; }
          return;
        }
        if (st === 'denied') { sub.textContent = T('pushDenied'); btn.style.display = 'none'; return; }
        if (st === 'on') {
          sub.textContent = T('pushOnDone'); btn.textContent = T('pushTest'); btn.className = 'btn btn-ghost btn-sm';
          btn.onclick = function () {
            btn.disabled = true;
            self.test().then(function (r) { say(r && r.sent ? '✅ ' + T('pushTestSent') : '⚠️ ' + ((r && r.error) || T('pushTestFail'))); })
              .catch(function (e) { say('⚠️ ' + (e && e.message || T('pushTestFail'))); })
              .then(function () { btn.disabled = false; });
          };
          var off = q('#pushOffBtn');
          if (!off) {
            off = document.createElement('button'); off.type = 'button'; off.id = 'pushOffBtn'; off.className = 'btn btn-ghost btn-sm';
            off.textContent = T('pushOff'); btn.parentNode.appendChild(off);
            off.onclick = function () { self.unsubscribe().then(function () { off.remove(); paint('off'); say(T('pushOffDone')); }).catch(function () {}); };
          }
          return;
        }
        sub.textContent = T('pushP'); btn.textContent = T('pushOn'); btn.className = 'btn btn-blue btn-sm'; btn.style.display = '';
        btn.onclick = function () {
          btn.disabled = true; btn.textContent = '…';
          self.subscribe(b.id).then(function () { say('✅ ' + T('pushOnDone')); paint('on'); })
            .catch(function (e) {
              if (e && e.code === 'denied') paint('denied');
              else { say('⚠️ ' + ((e && e.message) || T('pushTestFail'))); paint('off'); }
            })
            .then(function () { btn.disabled = false; });
        };
      };
      self.state().then(paint);
      if ('Notification' in window && Notification.permission === 'granted') self.autoSync(b);
    }
  };
  window.SHGPush = SHGPush;

  // The worker asks the page to open a ticket after a notification tap.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (e) {
      var d = e && e.data || {};
      if (d.type === 'shg-open' && typeof d.url === 'string') {
        try { var u = new URL(d.url, location.href); if (u.origin === location.origin) location.href = u.href; } catch (err) {}
      }
    });
  }

  /* ================================================================
     3. BORDER CROSSING PREP — auto-opens 24 h before an India → Nepal
     departure (border_card_hours setting), stays until the trip ends.
  ================================================================ */
  var SHGBorder = {
    hours: function () { var h = parseInt(settings().border_card_hours, 10); return (h > 0 && h < 240) ? h : 24; },
    route: function (b) { return (typeof routeById === 'function' && routeById(b.routeId)) || {}; },
    toNepal: function (b) {
      var r = this.route(b);
      if (typeof isNepalPoint === 'function' && r.to && isNepalPoint(r.to)) return true;
      return /rupaidiha|nepalgunj|jamunaha/i.test(String(b.drop || '') + ' ' + String(r.to || ''));
    },
    phase: function (b) {
      var dep = (typeof depTimestamp === 'function') ? depTimestamp(b) : 0;
      if (!dep) return { show: false };
      var hrs = (dep - Date.now()) / 3600000;
      if (hrs < -40) return { show: false };                        // arrived long ago
      return { show: true, open: hrs <= this.hours(), hrs: hrs };
    },
    checklist: function (b) {
      var items = (typeof CONFIG !== 'undefined' && CONFIG.booking && CONFIG.booking.checklist) || [];
      var stored = {}; try { stored = JSON.parse(lsGet('bc:chk:' + b.id) || '{}'); } catch (e) {}
      return items.map(function (it, i) {
        return '<li><label><input type="checkbox" data-bc="' + i + '"' + (stored[i] ? ' checked' : '') + '><span' + (stored[i] ? ' class="done"' : '') + '>' + E(it) + '</span></label></li>';
      }).join('');
    },
    card: function (b) {
      if (!b || b.status !== 'confirmed' || !this.toNepal(b)) return '';
      var ph = this.phase(b); if (!ph.show) return '';
      var peg = (typeof CONFIG !== 'undefined' && CONFIG.nprPerInr) || 1.6;
      var when = ph.hrs > 0 ? TF('bcIn', { h: Math.max(1, Math.round(ph.hrs)) }) : T('bcSoon');
      var office = (typeof S === 'function' && S().phone) || '+91 91048 01507';
      return '<details class="border-card" id="borderCard" data-pnr="' + E(b.id) + '"' + (ph.open ? ' open' : '') + '>'
        + '<summary><span class="bc-ico">🛂</span><span class="bc-ttl"><b>' + E(T('bcT')) + '</b><small>' + E(T('bcSub')) + ' · ' + E(when) + '</small></span><span class="bc-chev" aria-hidden="true">⌄</span></summary>'
        + '<div class="bc-body">'
        + '<section><h4>📄 ' + E(T('bcDocsT')) + '</h4><ul class="bc-list"><li>🇮🇳 ' + E(T('bcDocIn')) + '</li><li>🇳🇵 ' + E(T('bcDocNp')) + '</li><li>👶 ' + E(T('bcDocKid')) + '</li><li>✅ ' + E(T('bcNoVisa')) + '</li></ul></section>'
        + '<section><h4>🧳 ' + E(T('bcChkT')) + '</h4><ul class="bc-check" id="bcCheck">' + this.checklist(b) + '</ul></section>'
        + '<section><h4>💱 ' + E(T('bcMoneyT')) + '</h4><div class="bc-fx" id="bcFx"><b>' + E(T('bcPeg').replace('1.60', Number(peg).toFixed(2))) + '</b><small id="bcFxLive">' + E(T('bcFxChecking')) + '</small></div><p class="bc-warn">⚠️ ' + E(T('bcNotes')) + '</p></section>'
        + '<section><h4>🛃 ' + E(T('bcCustomsT')) + '</h4><ul class="bc-list"><li>' + E(T('bcCustoms1')) + '</li><li>' + E(T('bcCustoms2')) + '</li></ul></section>'
        + '<section><h4>🚌 ' + E(T('bcCrossT')) + '</h4><ul class="bc-list"><li>' + E(T('bcCross1')) + '</li><li>' + E(T('bcCross2')) + '</li></ul></section>'
        + '<section><h4>🆘 ' + E(T('bcSosT')) + '</h4><p>' + E(T('bcSos')) + ' · <a href="tel:' + E((typeof digits === 'function') ? digits(office) : office) + '">' + E(office) + '</a></p></section>'
        + '<div class="bc-actions"><button type="button" class="btn btn-blue btn-sm" id="bcPrint">🖨️ ' + E(T('bcPrint')) + '</button>'
        + '<a class="btn btn-ghost btn-sm" href="#/nav">🗺️ ' + E(T('bcMap')) + '</a>'
        + '<a class="btn btn-wa btn-sm" id="bcShare" target="_blank" rel="noopener" href="#">💬 ' + E(T('bcShare')) + '</a></div>'
        + '</div></details>';
    },
    wire: function (b) {
      var card = q('#borderCard'); if (!card) return;
      var self = this;
      card.querySelectorAll('input[data-bc]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          var s = {}; try { s = JSON.parse(lsGet('bc:chk:' + b.id) || '{}'); } catch (e) {}
          if (cb.checked) s[cb.getAttribute('data-bc')] = true; else delete s[cb.getAttribute('data-bc')];
          lsSet('bc:chk:' + b.id, JSON.stringify(s));
          var sp = cb.nextElementSibling; if (sp) sp.classList.toggle('done', cb.checked);
        });
      });
      var pr = q('#bcPrint');
      if (pr) pr.addEventListener('click', function () {
        card.open = true;
        document.body.classList.add('print-border');
        var done = function () { document.body.classList.remove('print-border'); window.removeEventListener('afterprint', done); };
        window.addEventListener('afterprint', done);
        setTimeout(function () { try { window.print(); } catch (e) {} setTimeout(done, 1500); }, 60);
      });
      var sh = q('#bcShare');
      if (sh) {
        var r = self.route(b);
        var txt = '🛂 ' + T('bcT') + ' — ' + b.id + '\n' + (r.from || '') + ' → ' + (r.to || '') + ' · ' + (b.date || '') + '\n'
          + '• ' + T('bcDocIn') + '\n• ' + T('bcDocNp') + '\n• ' + T('bcNotes') + '\n• ' + T('bcCross1') + '\n' + location.origin + '/#/ticket/' + b.id;
        sh.href = 'https://wa.me/?text=' + encodeURIComponent(txt);
      }
      self.liveRate();
    },
    /* INR→NPR is a fixed peg (1.60); the live check only confirms it. Best
       effort, 5 s cap, and the card is complete without it. */
    liveRate: function () {
      var el = q('#bcFxLive'); if (!el || !window.fetch || !navigator.onLine) { if (el) el.textContent = ''; return; }
      var ctl = (typeof AbortController === 'function') ? new AbortController() : null;
      var tm = ctl ? setTimeout(function () { ctl.abort(); }, 5000) : null;
      fetch('https://open.er-api.com/v6/latest/INR', { signal: ctl ? ctl.signal : undefined, mode: 'cors' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          var npr = j && j.rates && Number(j.rates.NPR);
          el.textContent = (npr && npr > 1 && npr < 3) ? TF('bcLive', { r: npr.toFixed(3) }) : '';
        })
        .catch(function () { el.textContent = ''; })
        .then(function () { if (tm) clearTimeout(tm); });
    },
    badge: function (b) {
      if (!b || b.status !== 'confirmed' || !this.toNepal(b)) return '';
      var ph = this.phase(b);
      return (ph.show && ph.open) ? '<a class="chip bc-badge" href="#/ticket/' + E(b.id) + '">🛂 ' + E(T('bcBadge')) + '</a>' : '';
    }
  };
  window.SHGBorder = SHGBorder;

  /* ================================================================
     4. SPLIT-PAY — a pending online booking for 2+ passengers can be
     paid by each friend through their own UPI link. One ticket, one
     total; the office confirms once the full fare has arrived.
  ================================================================ */
  var SHGSplit = {
    enabled: function () { var v = settings().split_pay_enabled; return !(v === false || v === 0 || v === '0'); },
    eligible: function (b) {
      if (!b || b.status !== 'pending' || !this.enabled()) return false;
      if (b.codFlag || (b.payment && b.payment.method === 'cod')) return false;
      return ((b.seats || []).length > 1) && (b.total || 0) > 0;
    },
    button: function (b) {
      return this.eligible(b) ? '<button type="button" class="btn btn-ghost" id="splitBtn" data-pnr="' + E(b.id) + '">🤝 ' + E(T('spBtn')) + '</button>' : '';
    },
    wire: function (b) {
      var self = this, btn = q('#splitBtn'); if (!btn) return;
      btn.addEventListener('click', function () { self.open(b); });
    },
    open: function (b) {
      var self = this;
      if (typeof openModal !== 'function') return;
      openModal('<h3 class="m-title">🤝 ' + E(T('spT')) + '</h3><p class="m-sub" id="spSub">' + E(T('spLoading')) + '</p><div id="spBody" class="sp-body"></div>'
        + '<div class="m-actions"><button type="button" class="btn btn-ghost" id="spRefresh">🔄 ' + E(T('spRefresh')) + '</button><button type="button" class="btn btn-blue" id="spClose">' + E(T('spOk')) + '</button></div>');
      var close = q('#spClose'); if (close) close.onclick = function () { closeModal(); };
      var rf = q('#spRefresh'); if (rf) rf.onclick = function () { self.load(b); };
      self.load(b);
    },
    load: function (b) {
      var self = this, body = q('#spBody'), sub = q('#spSub'); if (!body) return;
      shgApi.post('/split-pay.php', { action: 'plan', pnr: b.id, phone: (b.contact && b.contact.phone) || '' })
        .then(function (d) {
          var n = (d.shares || []).length, done = (d.shares || []).filter(function (s) { return s.status !== 'open'; }).length;
          if (sub) sub.textContent = TF('spP', { n: n, a: (typeof inr === 'function') ? inr(d.perHead) : '₹' + d.perHead });
          body.innerHTML = '<div class="sp-progress"><i style="width:' + (n ? Math.round(done * 100 / n) : 0) + '%"></i></div>'
            + '<small class="sp-count">' + E(TF('spProgress', { done: done, n: n })) + '</small>'
            + (d.shares || []).map(function (s) {
                var amt = (typeof inr === 'function') ? inr(s.amount) : '₹' + s.amount;
                var msg = TF('spMsg', { pnr: b.id, a: amt, link: s.link });
                var st = s.status === 'open'
                  ? '<span class="sp-st open">' + E(T('spOpen')) + '</span>'
                  : '<span class="sp-st done">✓ ' + E(TF('spClaimed', { name: s.payerName || '—' })) + (s.utr ? ' · ' + E(s.utr) : '') + '</span>';
                return '<div class="sp-row"><div class="sp-main"><b>' + E(TF('spShare', { n: s.n })) + '</b><span class="sp-amt">' + E(amt) + '</span>' + st + '</div>'
                  + '<div class="sp-btns"><a class="btn btn-wa btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent(msg) + '">💬 ' + E(T('spWa')) + '</a>'
                  + '<button type="button" class="btn btn-ghost btn-sm" data-copy="' + E(s.link) + '">📋 ' + E(T('spCopy')) + '</button></div></div>';
              }).join('')
            + '<p class="sp-note">ℹ️ ' + E(T('spNote')) + '</p>';
          body.querySelectorAll('[data-copy]').forEach(function (bt) {
            bt.addEventListener('click', function () {
              var v = bt.getAttribute('data-copy');
              var ok = function () { say('✅ ' + T('spCopied')); };
              if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(v).then(ok).catch(function () { window.prompt('Link', v); });
              else window.prompt('Link', v);
            });
          });
        })
        .catch(function (e) {
          if (sub) sub.textContent = '';
          body.innerHTML = '<p class="sp-note">⚠️ ' + E((e && e.message) || 'Could not load') + '</p>';
        });
    }
  };
  window.SHGSplit = SHGSplit;

  /* ================================================================
     5. OCCUPANCY CHART — seat fill for the next 7 days of a direction.
     Pure CSS bars (no chart library): green < 50 %, amber 50–80 %,
     red > 80 %. Tapping a day re-runs the search for that date.
  ================================================================ */
  var OccChart = {
    _cache: {},
    render: function (host, from, to, date) {
      if (!host || !from || !to) return;
      var self = this, key = from + '|' + to;
      var hit = self._cache[key];
      var paintNow = function (d) { self.paint(host, d, date); };
      if (hit && (Date.now() - hit.at) < 120000) { paintNow(hit.data); return; }
      host.innerHTML = '<div class="occ-card occ-loading"><small>' + E(T('occLoad')) + '</small></div>';
      var start = (typeof todayISO === 'function') ? todayISO() : new Date().toISOString().slice(0, 10);
      shgApi.get('/occupancy.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&start=' + encodeURIComponent(start) + '&days=7')
        .then(function (d) { self._cache[key] = { at: Date.now(), data: d }; paintNow(d); })
        .catch(function () { host.innerHTML = ''; });
    },
    paint: function (host, d, selected) {
      var days = (d && d.days) || [];
      if (!days.length) { host.innerHTML = ''; return; }
      var loc = lang() === 'hi' ? 'hi-IN' : (lang() === 'ne' ? 'ne-NP' : 'en-IN');
      var bars = days.map(function (x) {
        var dt = new Date(x.date + 'T00:00:00');
        var wd = '', dn = '';
        try { wd = dt.toLocaleDateString(loc, { weekday: 'short' }); dn = dt.toLocaleDateString(loc, { day: 'numeric' }); } catch (e) { wd = x.date.slice(5); }
        var cls = x.off ? 'off' : (x.soldOut ? 'full' : (x.pct >= 80 ? 'high' : (x.pct >= 50 ? 'mid' : 'low')));
        var lbl = x.off ? T('occOff') : (x.soldOut ? T('occFull') : x.pct + '%');
        var h = x.off ? 6 : Math.max(6, Math.min(100, x.soldOut ? 100 : x.pct));
        return '<button type="button" class="occ-bar ' + cls + (x.date === selected ? ' on' : '') + '" data-iso="' + E(x.date) + '"' + (x.off || x.soldOut ? ' disabled' : '') + ' aria-label="' + E(x.date + ' ' + lbl) + '">'
          + '<span class="occ-day">' + E(wd) + '</span><span class="occ-num">' + E(dn) + '</span><span class="occ-meter"><i style="width:' + h + '%"></i></span><span class="occ-pct">' + E(lbl) + '</span></button>';
      }).join('');
      /* No .reveal here: observeReveals() has already run by the time this
         async card lands, so a reveal class would leave it at opacity 0. */
      host.innerHTML = '<div class="occ-card"><div class="occ-head"><b>📊 ' + E(T('occT')) + '</b><small>' + E(T('occP')) + '</small></div>'
        + '<div class="occ-bars">' + bars + '</div>'
        + '<div class="occ-legend"><span class="low">' + E(T('occLow')) + '</span><span class="mid">' + E(T('occMid')) + '</span><span class="high">' + E(T('occHigh')) + '</span></div></div>';
      host.querySelectorAll('.occ-bar:not([disabled])').forEach(function (bt) {
        bt.addEventListener('click', function () {
          var iso = bt.getAttribute('data-iso');
          if (!iso || typeof Flow === 'undefined') return;
          Flow.date = iso;
          var di = q('#dateInput'); if (di) di.value = iso;
          if (typeof markDateChip === 'function') { try { markDateChip(); } catch (e) {} }
          host.querySelectorAll('.occ-bar').forEach(function (o) { o.classList.toggle('on', o === bt); });
          if (typeof SrvCatalog !== 'undefined' && typeof renderResults === 'function') {
            SrvCatalog.load(d.from, d.to, iso).then(function () { if (location.hash === '#/results') renderResults(true); });
            renderResults(true);
          }
          beacon('search', { date_offset: iso, source: 'occupancy' });
        });
      });
    }
  };
  window.OccChart = OccChart;

  /* ================================================================
     6. ROUTE GUIDE — the navigator sheet lists every stop of both runs:
     pickup times from the live timetable, the towns on the way with road
     distance, and the border. Kept in IndexedDB, so it opens with no
     network at all. Tapping 📍 flies the map there once the map is up.
     No invented times: only the timetable's pickup times are printed and
     the arrival stays "confirmed by the office", exactly like the results
     card (the owner's rule for a time we cannot stand behind).
  ================================================================ */
  var IDB = {
    _db: null,
    open: function () {
      var self = this;
      if (self._db) return Promise.resolve(self._db);
      return new Promise(function (res, rej) {
        if (!('indexedDB' in window)) { rej(new Error('no indexedDB')); return; }
        var r;
        try { r = indexedDB.open('shg-offline', 1); } catch (e) { rej(e); return; }
        r.onupgradeneeded = function () { try { r.result.createObjectStore('kv'); } catch (e) {} };
        r.onsuccess = function () { self._db = r.result; res(r.result); };
        r.onerror = function () { rej(r.error); };
      });
    },
    get: function (k) {
      return this.open().then(function (db) {
        return new Promise(function (res) {
          try {
            var g = db.transaction('kv', 'readonly').objectStore('kv').get(k);
            g.onsuccess = function () { res(g.result || null); };
            g.onerror = function () { res(null); };
          } catch (e) { res(null); }
        });
      }).catch(function () { return null; });
    },
    set: function (k, v) {
      return this.open().then(function (db) {
        return new Promise(function (res) {
          try {
            var tx = db.transaction('kv', 'readwrite');
            tx.objectStore('kv').put(v, k);
            tx.oncomplete = function () { res(true); };
            tx.onerror = function () { res(false); };
          } catch (e) { res(false); }
        });
      }).catch(function () { return false; });
    }
  };
  window.SHGIdb = IDB;

  var SHGRoute = {
    data: null, dir: 0, offline: false, painted: false, marker: null, total: null, _stops: [],
    host: function () { return q('#snRouteGuide'); },
    short: function (name) { return String(name || '').split(/\s[-—–]\s|,|·/)[0].trim(); },
    nepal: function (c) {
      return (typeof isNepalPoint === 'function' && isNepalPoint(c)) || /rupaidiha|nepalgunj/i.test(String(c || ''));
    },
    runs: function (d) {
      var self = this, routes = (d && d.routes) || [];
      var go = null, back = null;
      routes.forEach(function (r) {
        if (!go && self.nepal(r.to)) go = r;
        if (!back && self.nepal(r.from)) back = r;
      });
      return [go, back];
    },
    path: function () {
      try { return (typeof TRACKER_PATHS !== 'undefined' && TRACKER_PATHS.via_bahraich) || []; } catch (e) { return []; }
    },
    stops: function (r, outbound) {
      if (!r) return [];
      var self = this, path = this.path(), list = [];
      var pick = (r.boarding || []).map(function (s) {
        return { kind: s.isBorder ? 'border' : 'pickup', name: s.name, lm: s.landmark, time: s.time, lat: s.lat, lng: s.lng };
      });
      var drop = (r.drop || []).map(function (s) {
        return { kind: s.isBorder ? 'border' : 'drop', name: s.name, lm: s.landmark, time: '', lat: s.lat, lng: s.lng };
      });
      var find = function (name) {
        var n = String(name || '').toLowerCase();
        for (var i = 0; i < path.length; i++) { if (n.indexOf(path[i].name.toLowerCase()) >= 0) return path[i]; }
        return null;
      };
      var border = null;
      path.forEach(function (p) { if (p.name === 'Rupaidiha') border = p; });
      if (outbound) {
        var anchor = null, anchorName = '';
        for (var j = pick.length - 1; j >= 0 && !anchor; j--) { anchor = find(pick[j].name); if (anchor) anchorName = self.short(pick[j].name); }
        list = list.concat(pick);
        if (anchor && border) {
          path.forEach(function (p) {
            if (p.landmark && p.km > anchor.km && p.km < border.km) {
              list.push({ kind: 'via', name: p.name, km: p.km - anchor.km, from: anchorName, lat: p.lat, lng: p.lng });
            }
          });
          drop.forEach(function (s) { if (s.kind === 'border') { s.km = border.km - anchor.km; s.from = anchorName; } });
          self.total = { from: anchorName, to: self.short(drop.length ? drop[drop.length - 1].name : 'Rupaidiha'), km: border.km - anchor.km };
        }
        list = list.concat(drop);
      } else {
        list = list.concat(pick);
        var first = null;
        drop.forEach(function (s) { if (!first) first = find(s.name); });
        if (first && border) {
          path.slice().reverse().forEach(function (p) {
            if (p.landmark && p.km < border.km && p.km > first.km) {
              list.push({ kind: 'via', name: p.name, km: border.km - p.km, from: 'Rupaidiha', lat: p.lat, lng: p.lng });
            }
          });
          drop.forEach(function (s) { var pt = find(s.name); if (pt) { s.km = border.km - pt.km; s.from = 'Rupaidiha'; } });
        }
        list = list.concat(drop);
      }
      return list;
    },
    paint: function () {
      var self = this, host = this.host(); if (!host) return;
      var runs = this.data ? this.runs(this.data) : [null, null];
      var shell = function (sub) {
        return '<details class="rg"><summary><span class="rg-ico" aria-hidden="true">🗺️</span><span class="rg-hd"><b>' + E(T('rgT')) + '</b><small>' + E(sub) + '</small></span></summary></details>';
      };
      if (!runs[0] && !runs[1]) { host.innerHTML = shell(this.data ? T('rgNone') : '…'); return; }
      if (!runs[this.dir]) this.dir = runs[0] ? 0 : 1;
      var old = host.querySelector('details.rg');
      var wasOpen = !!(old && old.open);
      this.total = null;
      var run = runs[this.dir];
      var stops = this.stops(run, this.dir === 0);
      this._stops = stops;
      var km = function (n) { return Math.round(n).toLocaleString('en-IN'); };
      var rows = stops.map(function (s, i) {
        var sub = [];
        if (s.kind === 'pickup') sub.push('🟠 ' + T('rgPickup') + (s.time ? ' · ' + s.time : ''));
        else if (s.kind === 'drop') sub.push('🟢 ' + T('rgDrop'));
        else if (s.kind === 'border') sub.push('🛂 ' + T('rgBorder') + (s.time ? ' · ' + s.time : ''));
        else sub.push(T('rgVia'));
        if (s.km != null && s.from) sub.push(TF('rgKm', { km: km(s.km), from: s.from }));
        if (s.lm && s.kind !== 'via' && s.lm !== 'Departure') sub.push(s.lm);
        var go = (s.lat != null && s.lng != null)
          ? '<button type="button" class="rg-go" data-i="' + i + '" aria-label="' + E(T('rgMap') + ': ' + s.name) + '">📍</button>' : '';
        return '<li class="rg-stop ' + s.kind + '" data-name="' + E(String(s.name).toLowerCase()) + '"><span class="rg-dot" aria-hidden="true"></span>'
          + '<div class="rg-txt"><b>' + E(s.name) + '</b><small>' + E(sub.join(' · ')) + '</small></div>' + go + '</li>';
      }).join('');
      var arr = (this.dir === 0) ? ((run && run.arrTime) ? '🏁 ' + run.arrTime : '🏁 ' + T('rgTbc')) : '';
      host.innerHTML = '<details class="rg"' + (wasOpen ? ' open' : '') + '><summary><span class="rg-ico" aria-hidden="true">🗺️</span>'
        + '<span class="rg-hd"><b>' + E(T('rgT')) + '</b><small>' + E(T('rgP')) + '</small></span>'
        + (this.offline ? '<span class="rg-off">' + E(T('rgOffline')) + '</span>' : '') + '</summary>'
        + '<div class="rg-tabs" role="tablist">'
        + (runs[0] ? '<button type="button" class="rg-tab' + (this.dir === 0 ? ' on' : '') + '" data-dir="0" role="tab" aria-selected="' + (this.dir === 0) + '">' + E(T('rgGo')) + '</button>' : '')
        + (runs[1] ? '<button type="button" class="rg-tab' + (this.dir === 1 ? ' on' : '') + '" data-dir="1" role="tab" aria-selected="' + (this.dir === 1) + '">' + E(T('rgBack')) + '</button>' : '')
        + '</div>'
        + (this.total ? '<div class="rg-meta">' + E(TF('rgTotal', { from: this.total.from, to: this.total.to, km: km(this.total.km) })) + '</div>' : '')
        + '<ol class="rg-list">' + rows + '</ol>'
        + (arr ? '<div class="rg-meta">' + E(arr) + '</div>' : '')
        + '<div class="rg-note">🛂 ' + E(T('rgBorderInfo')) + '</div></details>';
      host.querySelectorAll('.rg-tab').forEach(function (b) {
        b.addEventListener('click', function () { self.dir = +b.getAttribute('data-dir'); self.paint(); });
      });
      host.querySelectorAll('.rg-go').forEach(function (b) {
        b.addEventListener('click', function () { var s = self._stops[+b.getAttribute('data-i')]; if (s) self.fly(s); });
      });
    },
    fly: function (s) {
      try {
        if (typeof SN === 'undefined' || !SN || !SN.map || typeof SN.map.flyTo !== 'function') { say('🗺️ ' + s.name); return; }
        SN.map.flyTo({ center: [s.lng, s.lat], zoom: Math.max((SN.map.getZoom && SN.map.getZoom()) || 0, 10), speed: 1.2 });
        if (window.maplibregl) {
          if (this.marker) { try { this.marker.remove(); } catch (e) {} }
          this.marker = new window.maplibregl.Marker({ color: '#FF6B00' }).setLngLat([s.lng, s.lat]).addTo(SN.map);
        }
        var dock = document.querySelector('.sn-dock');
        if (dock) {
          SN.sheetState = 'peek'; dock.setAttribute('data-sheet', 'peek');
          var caret = document.querySelector('#snPeek .pk-caret'); if (caret) caret.textContent = '▴';
          requestAnimationFrame(function () { try { SN.map.resize(); } catch (e) {} });
        }
        var pt = q('#snPeekTitle'), ps = q('#snPeekSub');
        if (pt) pt.textContent = '📍 ' + s.name;
        if (ps) ps.textContent = (s.kind === 'pickup' && s.time) ? T('rgPickup') + ' · ' + s.time
          : ((s.km != null && s.from) ? TF('rgKm', { km: Math.round(s.km).toLocaleString('en-IN'), from: s.from }) : '');
      } catch (e) {}
    },
    focus: function (name) {
      var self = this;
      if (!this.painted) this.render();
      var findLi = function () {
        var host = self.host(); if (!host) return null;
        var n = String(name || '').toLowerCase(), hit = null;
        host.querySelectorAll('.rg-stop').forEach(function (x) {
          x.classList.remove('hl');
          var dn = x.getAttribute('data-name') || '';
          if (!hit && n && dn && (dn.indexOf(n) >= 0 || n.indexOf(dn) >= 0)) hit = x;
        });
        return hit;
      };
      var hit = findLi();
      if (!hit && this.dir !== 0) { this.dir = 0; this.paint(); hit = findLi(); }
      var det = this.host() && this.host().querySelector('details.rg');
      if (det) det.open = true;
      var dock = document.querySelector('.sn-dock');
      if (dock && typeof SN !== 'undefined' && SN) { SN.sheetState = 'half'; dock.setAttribute('data-sheet', 'half'); }
      if (hit) {
        hit.classList.add('hl');
        try { hit.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
        setTimeout(function () { hit.classList.remove('hl'); }, 2500);
      }
    },
    render: function () {
      var self = this, host = this.host(); if (!host) return;
      if (!this.painted) this.paint();
      this.painted = true;
      IDB.get('timetable').then(function (hit) {
        if (hit && hit.data && !self.data) { self.data = hit.data; self.offline = !navigator.onLine; self.paint(); }
      });
      var src;
      try { src = (typeof shgTimetableGet === 'function') ? shgTimetableGet('') : shgApi.get('/timetable.php'); }
      catch (e) { src = Promise.reject(e); }
      Promise.resolve(src).then(function (res) {
        var d = (res && res.data) || res || {};
        if (!d.routes || !d.routes.length) throw new Error('empty timetable');
        self.data = d; self.offline = false; self.paint();
        IDB.set('timetable', { at: Date.now(), data: d });
      }).catch(function () {
        if (self.data) { self.offline = true; self.paint(); return; }
        IDB.get('timetable').then(function (hit) {
          if (hit && hit.data) { self.data = hit.data; self.offline = true; }
          self.paint();
        });
      });
    }
  };
  window.SHGRoute = SHGRoute;
  var routeGuideHook = function () {
    if ((location.hash || '') === '#/nav') { try { SHGRoute.render(); } catch (e) {} }
  };
  window.addEventListener('hashchange', routeGuideHook);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', routeGuideHook); else routeGuideHook();

  /* A #/ticket/ link can paint before this file has run (fast cache): paint
     that ticket once more so the border card and alerts card appear. */
  try {
    var h0 = location.hash || '';
    if (h0.indexOf('#/ticket/') === 0 && q('#ticketCard') && !q('#pushCard') && !q('#borderCard') && typeof renderStatus === 'function') {
      renderStatus(decodeURIComponent(h0.split('/')[2] || ''));
    }
  } catch (e) {}
})();

/* =====================================================================
 *  ContactDial (24 Sep 2026) — the office one tap away on every screen.
 *  Call · WhatsApp · Sahayak chat · "call me back" (an enquiry the office
 *  sees in its inbox). Numbers come from SHG_BOOT.contact, so the desk can
 *  change them in Settings without a deploy. Every hook is optional: a
 *  page without the markup simply has no dial.
 * ===================================================================== */
(function ContactDial() {
  var fab = document.getElementById('ctFab'), sheet = document.getElementById('ctSheet');
  if (!fab || !sheet) return;
  var boot = window.SHG_BOOT || {}, contact = boot.contact || {};
  var phone = String(contact.phone || '').trim(), wa = String(contact.wa || '').replace(/\D/g, '');
  try { if (typeof t === 'function') fab.setAttribute('aria-label', t('ctFabLbl')); } catch (e) {}
  var callA = document.getElementById('ctCall'), waA = document.getElementById('ctWa');
  if (callA) { if (phone) callA.href = 'tel:' + phone.replace(/[^0-9+]/g, ''); else callA.hidden = true; }
  if (waA) {
    if (wa) {
      var hello = (typeof t === 'function') ? t('ctWaHello') : 'Namaste, S Hari Global. ';
      waA.href = 'https://wa.me/' + wa + '?text=' + encodeURIComponent(hello);
    } else { waA.hidden = true; }
  }
  function open() {
    sheet.hidden = false; fab.setAttribute('aria-expanded', 'true');
    var f = document.getElementById('ctCbForm'); if (f) f.hidden = true;
    try { if (navigator.vibrate) navigator.vibrate(8); } catch (e) {}
    var x = document.getElementById('ctClose'); if (x) x.focus();
  }
  function close() { sheet.hidden = true; fab.setAttribute('aria-expanded', 'false'); fab.focus(); }
  fab.addEventListener('click', function () { sheet.hidden ? open() : close(); });
  var closeBtn = document.getElementById('ctClose'); if (closeBtn) closeBtn.addEventListener('click', close);
  sheet.addEventListener('click', function (e) { if (e.target === sheet) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !sheet.hidden) close(); });

  var chat = document.getElementById('ctChat');
  if (chat) chat.addEventListener('click', function () {
    close();
    var ai = document.getElementById('aiFab'); if (ai) ai.click();
  });

  var cbOpen = document.getElementById('ctCbOpen'), form = document.getElementById('ctCbForm');
  if (cbOpen && form) {
    cbOpen.addEventListener('click', function () {
      form.hidden = !form.hidden;
      if (!form.hidden) {
        var u = boot.user || {};
        var n = document.getElementById('ctCbName'), p = document.getElementById('ctCbPhone');
        if (n && !n.value && u.name) n.value = u.name;
        if (p && !p.value && u.phone) p.value = u.phone;
        (n && !n.value ? n : p).focus();
      }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = document.getElementById('ctCbMsg'), btn = document.getElementById('ctCbSend');
      var name = (document.getElementById('ctCbName').value || '').trim();
      var ph = (document.getElementById('ctCbPhone').value || '').replace(/\D/g, '');
      if (name.length < 2 || ph.length < 10) { msg.className = 'ct-note bad'; msg.textContent = (typeof t === 'function') ? t('ctCbNeed') : 'Enter your name and mobile number.'; return; }
      btn.disabled = true; msg.className = 'ct-note'; msg.textContent = '…';
      var body = { name: name, phone: ph, source: 'callback', note: 'Call me back · ' + (location.hash || '#/'), seats: 1 };
      var send = (typeof shgApi !== 'undefined' && shgApi.post)
        ? shgApi.post('/enquiry.php', body)
        : fetch('/api/enquiry.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || ''); return j; });
      send.then(function () {
        msg.className = 'ct-note ok'; msg.textContent = (typeof t === 'function') ? t('ctCbOk') : 'Done — the office will call you back.';
        try { if (typeof toast === 'function') toast('✅ ' + msg.textContent); } catch (e2) {}
        setTimeout(close, 2200);
      }).catch(function () {
        msg.className = 'ct-note bad'; msg.textContent = (typeof t === 'function') ? t('ctCbFail') : 'Could not send — please call the office.';
      }).then(function () { btn.disabled = false; });
    });
  }
})();
