/* =====================================================================
   SHG 12D HYPER-OS ENGINE - 17 Oct 2026 (owner's "12D Hyper-OS" prompt)

   The owner's engine (src/utils/shgHyperEngine.js in the prompt), ported
   to this site's plain-script bundle: no build step here, so it is an IIFE
   that sets window.hyperOS instead of an ES module export.

   What it adds on top of 19-premium.js (SHGFeel), which already ticks and
   buzzes on every tap:
     6D  playRouteSwap()     - the air-whoosh when SmartBook's direction flips
     10D playAiTicketMagic() - the 5-note crystal chord + laser sweep + 360
                               turn when a confirmed ticket appears
     5D  water ripple glow under the finger on SmartBook keys (visual only;
         the tap sound stays SHGFeel's, so nothing plays twice)
     4D  saveInstantState / loadInstantState (localStorage, never throws)
     11D sanitizeInput + acquireSecurityLock helpers, and the
         "🛡️ SHG Vault Encrypted" pill - shown only on HTTPS, which is
         what actually encrypts the booking on its way to the server.

   Deliberate differences from the prompt, so live booking cannot break:
     - ONE off switch: sound follows SHGFeel's 'shg:sound', vibration
       'shg:haptics' (Menu → Sound / Vibration). No second audio key.
     - The book keys are never locked or disabled (SmartBook v5 rule: the
       server's idempotent book already absorbs a double tap). The lock
       helper is offered for new call sites, not forced on old ones.
     - sanitizeInput is NOT run over the booking payload: it strips ' and
       ( ) which real names and addresses contain; the server validates.
     - Motion: the in-app Motion switch (data-motion=reduced) turns the
       laser / 360 / ripple off. The OS battery-saver flag does not (p3j).
   Reverting = removing this one <script> + hyperos.css.
   ===================================================================== */
(function () {
  'use strict';

  var LS_SOUND = 'shg:sound';
  var LS_HAPTIC = 'shg:haptics';
  var STORAGE_KEY = 'shg_12d_instant_vault_v1';

  function readOn(key) {
    try { var v = localStorage.getItem(key); return v === null ? true : v === '1'; } catch (e) { return true; }
  }
  function motionOff() { return document.documentElement.getAttribute('data-motion') === 'reduced'; }

  function HyperEngine() {
    this.ctx = null;
    this.activeLocks = {};
    this.lastAt = 0;
  }
  var P = HyperEngine.prototype;

  Object.defineProperty(P, 'soundEnabled', { get: function () { return readOn(LS_SOUND); } });

  P.initAudio = function () {
    if (!this.ctx) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (AC) { try { this.ctx = new AC(); } catch (e) { this.ctx = null; } }
    }
    if (this.ctx && this.ctx.state === 'suspended') {
      try { var p = this.ctx.resume(); if (p && p.catch) p.catch(function () {}); } catch (e) {}
    }
    return this.ctx;
  };

  /* Same switch as Menu → Sound, so the two can never disagree. */
  P.toggleAudio = function () {
    var on = !this.soundEnabled;
    if (window.SHGFeel && SHGFeel.setSound) SHGFeel.setSound(on);
    else { try { localStorage.setItem(LS_SOUND, on ? '1' : '0'); } catch (e) {} }
    if (on) this.playTouchTick();
    return on;
  };

  P.haptic = function (pattern) {
    if (!readOn(LS_HAPTIC) || motionOff()) return;
    try { if (navigator.vibrate) navigator.vibrate(pattern == null ? 10 : pattern); } catch (e) {}
  };

  /* Plays only from a visible tab, only with sound on, never two inside 45 ms. */
  P._ready = function () {
    if (!this.soundEnabled || document.hidden) return null;
    var now = (window.performance && performance.now) ? performance.now() : Date.now();
    if (now - this.lastAt < 45) return null;
    this.lastAt = now;
    return this.initAudio();
  };

  P._sweep = function (c, type, points, vol, dur) {
    var now = c.currentTime, osc = c.createOscillator(), g = c.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(points[0][1], now);
    for (var i = 1; i < points.length; i++) osc.frequency.exponentialRampToValueAtTime(points[i][1], now + points[i][0]);
    /* 4 ms attack: a tone that starts at full gain clicks */
    g.gain.setValueAtTime(0.0001, now);
    g.gain.linearRampToValueAtTime(vol, now + 0.004);
    g.gain.exponentialRampToValueAtTime(0.001, now + dur);
    osc.connect(g); g.connect(c.destination);
    osc.start(now); osc.stop(now + dur + 0.01);
  };

  // 5D/6D: luxury micro-tick
  P.playTouchTick = function () {
    this.haptic(8);
    var c = this._ready(); if (!c) return;
    this._sweep(c, 'sine', [[0, 360], [0.038, 85]], 0.07, 0.042);
  };

  // 6D: route swap whoosh
  P.playRouteSwap = function () {
    this.haptic([10, 25, 14]);
    var c = this._ready(); if (!c) return;
    this._sweep(c, 'triangle', [[0, 150], [0.13, 620], [0.23, 190]], 0.06, 0.24);
  };

  // 10D: ticket ready - C5 E5 G5 B5 E6
  P.playAiTicketMagic = function () {
    this.haptic([15, 35, 20, 45, 30]);
    var c = this._ready(); if (!c) return;
    [523.25, 659.25, 783.99, 987.77, 1318.51].forEach(function (freq, i) {
      var start = c.currentTime + i * 0.06, osc = c.createOscillator(), g = c.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(freq, start);
      g.gain.setValueAtTime(0.001, start);
      g.gain.linearRampToValueAtTime(0.085, start + 0.018);
      g.gain.exponentialRampToValueAtTime(0.0005, start + 1.25);
      osc.connect(g); g.connect(c.destination);
      osc.start(start); osc.stop(start + 1.3);
    });
  };

  // 4D: instant device memory
  P.saveInstantState = function (key, data) {
    try {
      var json = JSON.stringify(data);
      var payload = { data: data, ts: Date.now(), sig: btoa(unescape(encodeURIComponent(json))).slice(0, 16) };
      localStorage.setItem(STORAGE_KEY + '_' + key, JSON.stringify(payload));
    } catch (e) {}
  };
  P.loadInstantState = function (key, fallback) {
    if (fallback === undefined) fallback = null;
    try {
      var raw = localStorage.getItem(STORAGE_KEY + '_' + key);
      if (!raw) return fallback;
      var parsed = JSON.parse(raw);
      return (parsed && parsed.data != null) ? parsed.data : fallback;
    } catch (e) { return fallback; }
  };

  // 11D: helpers (opt-in - see the header)
  P.sanitizeInput = function (val) {
    if (typeof val !== 'string') return val;
    return val.replace(/[<>'"`;(){}]/g, '').trim();
  };
  P.acquireSecurityLock = function (actionId, cooldownMs) {
    var self = this;
    if (self.activeLocks[actionId]) return false;
    self.activeLocks[actionId] = true;
    setTimeout(function () { delete self.activeLocks[actionId]; }, cooldownMs || 1200);
    return true;
  };

  var hyperOS = new HyperEngine();
  window.hyperOS = hyperOS;

  /* ------------------------------------------------------------------
     WIRING - every hook is delegated or observed; no SmartBook code edited
     ------------------------------------------------------------------ */

  /* 6D: SmartBook direction flip (.sb-dir-b) and the classic search swap */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('#smartBook .sb-dir-b, #swapBtn, .dir-toggle button');
    if (!b || b.classList.contains('on')) return;     /* tapping the active side is not a swap */
    hyperOS.playRouteSwap();
    var card = document.getElementById('sbCard');
    if (card && !motionOff()) {
      card.classList.remove('hx-swap'); void card.offsetWidth; card.classList.add('hx-swap');
      setTimeout(function () { card.classList.remove('hx-swap'); }, 600);
    }
  }, true);

  /* 5D: water ripple glow where the finger lands */
  var RIPPLE = '#smartBook .sb-pay, #smartBook .sb-dir-b, #smartBook .sb-bot-go, #smartBook .sb-tk-acts > *, #smartBook .sb-tk-new, .hx-deck';
  document.addEventListener('pointerdown', function (e) {
    if (motionOff()) return;
    var el = e.target.closest && e.target.closest(RIPPLE);
    if (!el) return;
    var r = el.getBoundingClientRect();
    var s = document.createElement('span');
    s.className = 'hx-ripple';
    s.setAttribute('aria-hidden', 'true');
    s.style.left = (e.clientX - r.left) + 'px';
    s.style.top = (e.clientY - r.top) + 'px';
    if (getComputedStyle(el).position === 'static') el.classList.add('hx-rel');
    el.appendChild(s);
    setTimeout(function () { if (s.parentNode) s.parentNode.removeChild(s); }, 700);
  }, { passive: true });

  /* 10D: a confirmed ticket lands in #sbTicket (desktop) or #sbModalIn (phone).
     Only the ✓ ticket - the "awaiting payment" card has no .sb-tk-pnr. */
  function onTicket(host) {
    var tk = host.querySelector('.sb-tk');
    if (!tk || tk.getAttribute('data-hx') || !tk.querySelector('.sb-tk-pnr')) return;
    tk.setAttribute('data-hx', '1');
    hyperOS.playAiTicketMagic();
    if (motionOff()) return;
    tk.classList.add('shg-holo-foil');
    var bar = document.createElement('span');
    bar.className = 'shg-ai-laser-bar';
    bar.setAttribute('aria-hidden', 'true');
    tk.appendChild(bar);
    setTimeout(function () {
      if (bar.parentNode) bar.parentNode.removeChild(bar);
      tk.classList.add('shg-ticket-360-active');
      setTimeout(function () { tk.classList.remove('shg-ticket-360-active'); }, 1000);
    }, 650);
  }
  function watch(id) {
    var host = document.getElementById(id);
    if (!host || !window.MutationObserver) return;
    new MutationObserver(function () { onTicket(host); }).observe(host, { childList: true });
  }

  /* 11D: the vault pill - only where the connection really is encrypted */
  function vaultPill() {
    var head = document.querySelector('#smartBook .sb-head');
    if (!head || head.querySelector('.hx-vault')) return;
    var secure = window.isSecureContext && location.protocol === 'https:';
    if (!secure) return;
    var p = document.createElement('span');
    p.className = 'hx-vault';
    p.title = 'HTTPS (TLS) - your booking travels encrypted';
    p.innerHTML = '<i aria-hidden="true"></i>🛡️ SHG Vault Encrypted';
    head.appendChild(p);
  }

  function boot() {
    document.documentElement.classList.add('hx-on');
    watch('sbTicket'); watch('sbModalIn');
    vaultPill();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
