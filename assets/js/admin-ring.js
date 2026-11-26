/* =====================================================================
 *  assets/js/admin-ring.js — call-style ring for the staff panel (26 Nov 2026, fx1)
 *
 *  Owner: "naya ticket aayo bhane call jastai bajnu paryo, kasaile Seen
 *  thichnu samma". The server side is includes/alertbus.php; this file is
 *  the open panel's half:
 *    - asks /api/alert-feed.php on the panel's EXISTING 15 s poll
 *      (admin_poll_js calls SHGRing.tick(); there is no second poller here -
 *      the 1 s timer below only runs while a card is on screen)
 *    - a full-screen card that rings (WebAudio, nothing downloaded) for
 *      `seconds`, again every `repeat` seconds, until someone taps Seen
 *    - Seen stops it for everybody (POST /api/alert-ack.php)
 *    - on SmartBook / quick ticket / new booking it never goes full screen:
 *      a clerk in the middle of a sale only gets a slim bar
 *    - respects the drawer's sound switch (localStorage shg_admin_sound),
 *      the role's volume and quiet hours, and prefers-reduced-motion
 *    - one tab sounds even when five are open (BroadcastChannel)
 *    - "Ring on this phone": one tap subscribes this handset to web push.
 *      Only that tap starts it (a phone that merely allowed Office chat
 *      notifications is never rung), and "Stop ringing this phone" ends it
 *
 *  Every sound is made here with oscillators (recipes: assets/sound/README.md).
 *  Plain ES5, no library.
 * ===================================================================== */
(function () {
  'use strict';
  if (window.SHGRing) { return; }

  var FEED = '/api/alert-feed.php', ACK = '/api/alert-ack.php', PUSH = '/api/alert-push.php';
  var TAB = String(Date.now()) + '-' + String(Math.floor(Math.random() * 1e6));
  var cfg = { seconds: 60, repeat: 120, max: 3, sound: true, volume: 80, quiet: false, push: { on: false } };
  var rings = [];            // open rings from the server, newest first
  var ringCount = 0;
  var skew = 0;              // server clock minus this device's clock, seconds
  var hidden = {};           // ring id -> true once Seen was tapped here (until the server agrees)
  var mini = {};             // ring id -> ring number that was minimised
  var ticked = {};           // ring id -> ring number already ticked on a selling screen
  var prevFocus = null;      // what had the keyboard before the card opened
  var uiTimer = null, lastFetch = 0, loopTimer = null, looping = '', wake = null;
  var peers = {}, bc = null, softSeen = -1, softTimer = null;
  var pushHave = null;       // does THIS handset ring for the person signed in? null = not known yet
  var api = { active: true };

  function ls(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  function ss(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) {} }
  function soundOn() { return ls('shg_admin_sound') !== '0'; }
  function csrf() { var m = document.querySelector('meta[name="shg-admin-csrf"]'); return m ? (m.getAttribute('content') || '') : ''; }
  function selling() { return !!document.getElementById('smartBook') || /quick-ticket|new-booking/.test(location.pathname); }
  function nowS() { return Date.now() / 1000 + skew; }

  /* ------------------------------------------------------------------
   *  Sound kit - ONE AudioContext for the whole page, woken by the first
   *  tap / key (browsers keep audio locked until the person touches the page).
   * ------------------------------------------------------------------ */
  var ctx = null, master = null;
  function audio() {
    if (ctx) { return ctx; }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) { return null; }
      ctx = new AC();
      master = ctx.createGain();
      master.gain.value = 0.5;
      master.connect(ctx.destination);
    } catch (e) { ctx = null; }
    return ctx;
  }
  function unlock() {
    var c = audio();
    if (c && c.state === 'suspended' && c.resume) { try { c.resume(); } catch (e) {} }
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach(function (ev) {
    window.addEventListener(ev, unlock, { passive: true, capture: true });
  });
  function locked() { return !ctx || ctx.state !== 'running'; }
  function level() { return Math.max(0, Math.min(1, (cfg.volume || 0) / 100)); }

  /* one note: type, frequency (or [from, to] glide), start offset, length, peak gain, optional tremolo Hz */
  function note(type, freq, at, len, peak, trem) {
    var c = audio();
    if (!c || c.state !== 'running') { return; }
    var t = c.currentTime + at, o = c.createOscillator(), g = c.createGain();
    o.type = type;
    if (freq && freq.length) {
      o.frequency.setValueAtTime(freq[0], t);
      o.frequency.linearRampToValueAtTime(freq[1], t + len);
    } else { o.frequency.value = freq; }
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(Math.max(0.0002, peak), t + 0.02);
    g.gain.setValueAtTime(Math.max(0.0002, peak), t + Math.max(0.03, len - 0.06));
    g.gain.exponentialRampToValueAtTime(0.0001, t + len);
    o.connect(g);
    if (trem) {
      var l = c.createOscillator(), lg = c.createGain(), tg = c.createGain();
      l.frequency.value = trem; lg.gain.value = 0.5; tg.gain.value = 0.5;
      l.connect(lg); lg.connect(tg.gain); g.connect(tg); tg.connect(master);
      l.start(t); l.stop(t + len + 0.05);
    } else { g.connect(master); }
    o.start(t); o.stop(t + len + 0.05);
  }
  /* a struck bell: fast attack, long decay */
  function bell(freq, at, len, peak) {
    var c = audio();
    if (!c || c.state !== 'running') { return; }
    var t = c.currentTime + at, o = c.createOscillator(), g = c.createGain();
    o.type = 'sine'; o.frequency.value = freq;
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(Math.max(0.0002, peak), t + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t + len);
    o.connect(g); g.connect(master); o.start(t); o.stop(t + len + 0.05);
  }
  var VOICES = {
    /* new-booking ring: a telephone bell, "brr-brr" then a pause. One call = one 3 s bar. */
    ring: function () {
      [0, 0.55].forEach(function (at) {
        note('sine', 440, at, 0.42, 0.34, 20); note('sine', 480, at, 0.42, 0.34, 20);
      });
    },
    /* payment chime: three rising notes */
    chime: function () { [659, 784, 1047].forEach(function (f, i) { note('triangle', f, i * 0.14, 0.34, 0.3); }); },
    /* agent-ticket tick: one short high blip */
    tick: function () { note('square', 1200, 0, 0.07, 0.12); },
    /* cancel / alert: two falling notes */
    alert: function () { note('triangle', 740, 0, 0.22, 0.3); note('triangle', 494, 0.24, 0.34, 0.3); },
    /* SOS siren: sweep up and down. One call = one 1.6 s bar. */
    siren: function () { note('sawtooth', [600, 1200], 0, 0.8, 0.26); note('sawtooth', [1200, 600], 0.8, 0.8, 0.26); },
    /* report-ready ding: one bell with a long tail */
    ding: function () { bell(1319, 0, 1.2, 0.34); bell(2637, 0, 0.6, 0.1); },
    /* message pop: a quick upward blip */
    pop: function () { note('sine', [420, 880], 0, 0.11, 0.26); }
  };
  function play(name) {
    if (!soundOn() || !cfg.sound || !VOICES[name]) { return false; }
    var c = audio();
    if (!c) { return false; }
    if (c.state === 'suspended' && c.resume) { try { c.resume(); } catch (e) {} }
    if (master) { master.gain.value = 0.6 * level(); }
    try { VOICES[name](); } catch (e) {}
    return c.state === 'running';
  }
  function startLoop(name) {
    if (looping === name) { return; }
    stopLoop();
    looping = name;
    var bar = name === 'siren' ? 1600 : 3000;
    var go = function () {
      play(name);
      try { if (soundOn() && cfg.sound && navigator.vibrate) { navigator.vibrate(name === 'siren' ? [500, 150, 500] : [400, 150, 400]); } } catch (e) {}
    };
    go();
    loopTimer = setInterval(go, bar);
  }
  function stopLoop() {
    if (loopTimer) { clearInterval(loopTimer); loopTimer = null; }
    looping = '';
    try { if (navigator.vibrate) { navigator.vibrate(0); } } catch (e) {}
  }

  /* ---- keep the screen awake while it rings ---- */
  function wakeOn() {
    try {
      if (wake || !navigator.wakeLock || document.visibilityState !== 'visible') { return; }
      navigator.wakeLock.request('screen').then(function (w) {
        wake = w;
        if (w.addEventListener) { w.addEventListener('release', function () { wake = null; }); }
      }).catch(function () {});
    } catch (e) {}
  }
  function wakeOff() { try { if (wake) { wake.release(); } } catch (e) {} wake = null; }

  /* ---- only one tab makes the noise ---- */
  try {
    if (window.BroadcastChannel) {
      bc = new BroadcastChannel('shg-ring');
      bc.onmessage = function (ev) {
        var m = ev.data || {};
        if (m.t === 'here' && m.id) { peers[m.id] = Date.now(); }
        if (m.t === 'ack' && m.ids) { m.ids.forEach(function (id) { hidden[id] = true; }); paint(); }
      };
    }
  } catch (e) { bc = null; }
  function leader() {
    if (!bc) { return true; }
    var now = Date.now(), k;
    for (k in peers) { if (peers.hasOwnProperty(k) && now - peers[k] < 3500 && k < TAB) { return false; } }
    return true;
  }

  /* ------------------------------------------------------------------
   *  The card
   * ------------------------------------------------------------------ */
  var el = null;
  var CSS = ''
    + '#shgRing{position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147483000;display:none;align-items:center;justify-content:center;padding:16px;'
    + 'background:rgba(8,16,36,.72);font-family:inherit}'
    + '#shgRing.on{display:flex}'
    + '#shgRing .rg-card{width:100%;max-width:440px;max-height:100%;overflow:auto;background:var(--card,#fff);color:var(--ink,#12264E);'
    + 'border:1px solid var(--line,#d9e0ee);border-radius:24px;padding:22px 18px 18px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.45);outline:0}'
    + '#shgRing .rg-ico{width:92px;height:92px;margin:2px auto 12px;border-radius:50%;display:grid;place-items:center;font-size:44px;'
    + 'background:#1FA35B;color:#fff}'
    + '#shgRing.sos .rg-ico{background:#C62828}'
    + '#shgRing .rg-t{font-size:21px;font-weight:800;line-height:1.3;margin:0 0 6px;overflow-wrap:anywhere}'
    + '#shgRing .rg-b{font-size:16px;line-height:1.45;margin:0 0 4px;color:var(--ink,#12264E);overflow-wrap:anywhere}'
    + '#shgRing .rg-m{font-size:14px;color:var(--mut,#5b6784);margin:0 0 4px;min-height:20px}'
    + '#shgRing .rg-more{font-size:14px;color:var(--mut,#5b6784);margin:8px 0 0;text-align:left;padding:0;list-style:none}'
    + '#shgRing .rg-more li{padding:6px 0;border-top:1px solid var(--line,#d9e0ee);overflow-wrap:anywhere}'
    + '#shgRing .rg-tap{display:none;margin:10px 0 0;padding:10px;border-radius:12px;background:#FFF3CD;color:#5c4400;font-size:15px;font-weight:700}'
    + '#shgRing.locked .rg-tap{display:block}'
    + '#shgRing .rg-btns{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}'
    + '#shgRing button{font:inherit;cursor:pointer;border-radius:16px;border:0;min-height:56px;font-size:18px;font-weight:800;padding:8px 10px}'
    + '#shgRing .rg-seen{background:#1FA35B;color:#fff}'
    + '#shgRing .rg-open{background:#12264E;color:#fff}'
    + ':root[data-theme="dark"] #shgRing .rg-open{background:#2F5FB3}'
    + '#shgRing .rg-min{grid-column:1 / -1;min-height:44px;font-size:15px;font-weight:700;background:transparent;color:var(--mut,#5b6784);'
    + 'border:1px solid var(--line,#d9e0ee)}'
    + '#shgRing .rg-push{display:none;margin-top:14px;padding-top:12px;border-top:1px solid var(--line,#d9e0ee);font-size:14px;color:var(--mut,#5b6784)}'
    + '#shgRing .rg-push.on{display:block}'
    + '#shgRing .rg-push button{min-height:44px;font-size:15px;margin:8px 4px 0;padding:6px 14px;background:#F07C1F;color:#fff}'
    + '#shgRing .rg-push button.later{background:transparent;color:var(--mut,#5b6784);border:1px solid var(--line,#d9e0ee)}'
    + '#shgRing .rg-stop{display:none;margin-top:12px}'
    + '#shgRing .rg-stop.on{display:block}'
    + '#shgRing .rg-stop button{min-height:44px;font-size:14px;font-weight:700;padding:6px 14px;background:transparent;color:var(--mut,#5b6784);'
    + 'border:1px solid var(--line,#d9e0ee)}'
    + '#shgRing button:focus-visible{outline:3px solid #F07C1F;outline-offset:2px}'
    /* the slim bar: selling screens, between two rings, quiet hours, "minimise" */
    + '#shgRingBar{position:fixed;left:8px;right:8px;top:8px;z-index:2147482990;display:none;align-items:center;gap:8px;max-width:560px;margin:0 auto;'
    + 'padding:8px 8px 8px 12px;border-radius:16px;background:#0a6b3b;color:#fff;box-shadow:0 10px 28px rgba(0,0,0,.35);font-size:15px;font-weight:700}'
    + '#shgRingBar.on{display:flex}'
    + '#shgRingBar.sos{background:#C62828}'
    + '#shgRingBar .rb-t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
    + '#shgRingBar button{font:inherit;cursor:pointer;border:0;border-radius:12px;min-height:44px;min-width:64px;padding:6px 12px;font-size:15px;font-weight:800;'
    + 'background:#fff;color:#0a6b3b}'
    + '#shgRingBar button.rb-open{background:rgba(255,255,255,.18);color:#fff}'
    + '#shgRingSoft{position:fixed;left:8px;right:8px;bottom:12px;z-index:2147482980;display:none;max-width:440px;margin:0 auto;padding:12px 14px;'
    + 'border-radius:14px;background:var(--card,#fff);color:var(--ink,#12264E);border:1px solid var(--line,#d9e0ee);box-shadow:0 10px 28px rgba(0,0,0,.3);'
    + 'font-size:15px;font-weight:700;text-decoration:none;overflow-wrap:anywhere}'
    + '#shgRingSoft.on{display:block}'
    + '#shgRingSoft small{display:block;font-weight:500;color:var(--mut,#5b6784);font-size:13.5px;margin-top:2px}'
    + '@media (max-width:380px){#shgRing .rg-t{font-size:19px}#shgRing .rg-ico{width:76px;height:76px;font-size:36px}#shgRing button{font-size:17px}}'
    + '@media (prefers-reduced-motion:no-preference){'
    + '#shgRing.ringing .rg-ico{animation:shgRingPulse 1s ease-in-out infinite}'
    + '@keyframes shgRingPulse{0%{box-shadow:0 0 0 0 rgba(31,163,91,.55);transform:scale(1)}60%{box-shadow:0 0 0 22px rgba(31,163,91,0);transform:scale(1.06)}'
    + '100%{box-shadow:0 0 0 0 rgba(31,163,91,0);transform:scale(1)}}'
    + '#shgRing.sos.ringing .rg-ico{animation-name:shgRingPulseSos}'
    + '@keyframes shgRingPulseSos{0%{box-shadow:0 0 0 0 rgba(198,40,40,.6);transform:scale(1)}60%{box-shadow:0 0 0 22px rgba(198,40,40,0);transform:scale(1.06)}'
    + '100%{box-shadow:0 0 0 0 rgba(198,40,40,0);transform:scale(1)}}}'
    + '@media print{#shgRing,#shgRingBar,#shgRingSoft{display:none!important}}';

  function mk(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text) { n.textContent = text; }
    return n;
  }
  function build() {
    if (el || !document.body) { return; }
    var st = document.createElement('style');
    st.id = 'shgRingCss'; st.textContent = CSS;
    document.head.appendChild(st);

    var root = mk('div'); root.id = 'shgRing';
    root.setAttribute('role', 'alertdialog'); root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-live', 'assertive'); root.setAttribute('aria-labelledby', 'shgRingT');
    var card = mk('div', 'rg-card'); card.setAttribute('tabindex', '-1');
    var ico = mk('div', 'rg-ico'); ico.setAttribute('aria-hidden', 'true');
    var t = mk('h2', 'rg-t'); t.id = 'shgRingT';
    var b = mk('p', 'rg-b'), m = mk('p', 'rg-m'), more = mk('ul', 'rg-more');
    var tap = mk('div', 'rg-tap', 'आवाज खोल्न यहाँ ट्याप गर्नुहोस् · Tap for sound');
    var btns = mk('div', 'rg-btns');
    var seen = mk('button', 'rg-seen', 'देखेँ · Seen'); seen.type = 'button';
    var open = mk('button', 'rg-open', 'खोल्नुहोस् · Open'); open.type = 'button';
    var min = mk('button', 'rg-min', 'सानो बनाउनुहोस् · Minimise'); min.type = 'button';
    btns.appendChild(seen); btns.appendChild(open); btns.appendChild(min);
    var push = mk('div', 'rg-push');
    push.appendChild(mk('span', '', 'प्यानल बन्द हुँदा पनि यो फोनमा घण्टी बजोस्? · Ring this phone even when the panel is closed?'));
    push.appendChild(document.createElement('br'));
    var pOn = mk('button', '', 'यो फोनमा घण्टी बजाउनुहोस् · Ring on this phone'); pOn.type = 'button';
    var pLater = mk('button', 'later', 'पछि · Later'); pLater.type = 'button';
    push.appendChild(pOn); push.appendChild(pLater);
    /* this handset rings: its owner can switch that off right here (every role sees the card) */
    var stop = mk('div', 'rg-stop');
    var pOff = mk('button', '', '🔕 यो फोनमा घण्टी बन्द गर्नुहोस् · Stop ringing this phone'); pOff.type = 'button';
    stop.appendChild(pOff);
    card.appendChild(ico); card.appendChild(t); card.appendChild(b); card.appendChild(m); card.appendChild(more);
    card.appendChild(tap); card.appendChild(btns); card.appendChild(push); card.appendChild(stop);
    root.appendChild(card);

    var bar = mk('div'); bar.id = 'shgRingBar'; bar.setAttribute('role', 'status'); bar.setAttribute('aria-live', 'polite');
    var bi = mk('span'); bi.setAttribute('aria-hidden', 'true');
    var bt = mk('span', 'rb-t');
    var bSeen = mk('button', 'rb-seen', 'देखेँ'); bSeen.type = 'button'; bSeen.setAttribute('aria-label', 'देखेँ · Seen');
    var bOpen = mk('button', 'rb-open', 'खोल्नुहोस्'); bOpen.type = 'button'; bOpen.setAttribute('aria-label', 'खोल्नुहोस् · Open');
    bar.appendChild(bi); bar.appendChild(bt); bar.appendChild(bSeen); bar.appendChild(bOpen);

    var soft = mk('a'); soft.id = 'shgRingSoft'; soft.setAttribute('role', 'status');

    document.body.appendChild(root); document.body.appendChild(bar); document.body.appendChild(soft);
    el = { root: root, card: card, ico: ico, t: t, b: b, m: m, more: more, seen: seen, open: open, min: min, push: push, stop: stop,
           bar: bar, bi: bi, bt: bt, soft: soft, shown: false };

    seen.addEventListener('click', doSeen); bSeen.addEventListener('click', doSeen);
    open.addEventListener('click', doOpen); bOpen.addEventListener('click', doOpen);
    min.addEventListener('click', doMin);
    tap.addEventListener('click', function () { unlock(); setTimeout(paint, 150); });
    pOn.addEventListener('click', function () { pOn.disabled = true; enablePush(true).then(function () { pOn.disabled = false; paint(); }); });
    pLater.addEventListener('click', function () { lsSet('shg_ring_push_later', String(Date.now() + 3 * 86400000)); paint(); });
    pOff.addEventListener('click', function () { pOff.disabled = true; disablePush().then(function () { pOff.disabled = false; paint(); }); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && el.shown) { doMin(); } });
  }

  function live() { return rings.filter(function (r) { return !hidden[r.id]; }); }
  /* which ring of this alert is sounding now: its number (0 = first), or -1 while it waits for the next one */
  function ringNo(r) {
    var el2 = Math.max(0, nowS() - r.at), k = Math.floor(el2 / cfg.repeat);
    if (k > cfg.max) { return -1; }
    return (el2 - k * cfg.repeat) < cfg.seconds ? k : -1;
  }
  function pushOffer() {
    if (!cfg.push || !cfg.push.on) { return false; }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return false; }
    if (Notification.permission === 'denied') { return false; }
    /* notifications already allowed (say, for Office chat): offer the ring only once we KNOW this handset does not ring yet */
    if (Notification.permission === 'granted' && pushHave !== false) { return false; }
    return !(parseInt(ls('shg_ring_push_later') || '0', 10) > Date.now());
  }

  function paint() {
    var list = live();
    if (!list.length) {
      if (el) {
        if (el.shown && prevFocus && prevFocus.focus) { try { prevFocus.focus({ preventScroll: true }); } catch (e) {} }
        prevFocus = null;
        el.root.className = ''; el.bar.className = ''; el.shown = false;
      }
      stopLoop(); wakeOff();
      if (uiTimer) { clearInterval(uiTimer); uiTimer = null; }
      return;
    }
    build();
    if (!el) { return; }
    if (!uiTimer) { uiTimer = setInterval(uiTick, 1000); }
    var top = list[0], loud = false, sounding = [], i;
    for (i = 0; i < list.length; i++) {
      var k = ringNo(list[i]);
      if (list[i].loud) { loud = true; }
      if (k >= 0 && mini[list[i].id] !== k) { sounding.push(list[i]); if (list[i].loud) { top = list[i]; } }
    }
    var isRinging = sounding.length > 0;
    if (isRinging) { audio(); }     // so "tap for sound" is shown only when the browser really holds the sound back
    if (isRinging && sounding.indexOf(top) < 0) { top = sounding[0]; }
    var quiet = cfg.quiet && !loud;
    var full = isRinging && !selling() && !quiet;
    var n = Math.max(list.length, ringCount - (rings.length - list.length));
    var head = n > 1 ? (n + ' नयाँ सूचना · ' + n + ' new alerts') : top.title;
    var sos = top.sound === 'siren';

    el.ico.textContent = top.icon || '🔔';
    el.t.textContent = head;
    el.b.textContent = n > 1 ? top.title : ((top.channel ? top.channel + ' · ' : '') + (top.body || ''));
    el.m.textContent = n > 1 ? ((top.channel ? top.channel + ' · ' : '') + (top.body || '')) : '';
    while (el.more.firstChild) { el.more.removeChild(el.more.firstChild); }
    for (i = 0; i < list.length && i < 5; i++) {
      if (list[i] !== top) { el.more.appendChild(mk('li', '', list[i].title)); }
    }
    el.push.className = 'rg-push' + (pushOffer() ? ' on' : '');
    el.stop.className = 'rg-stop' + (pushHave === true && cfg.push && cfg.push.on ? ' on' : '');
    el.root.className = (full ? 'on' : '') + (sos ? ' sos' : '') + (isRinging ? ' ringing' : '')
      + (isRinging && soundOn() && cfg.sound && locked() ? ' locked' : '');
    el.bi.textContent = top.icon || '🔔';
    el.bt.textContent = head;
    el.bar.className = (full ? '' : 'on') + (sos ? ' sos' : '');
    /* focus goes to the CARD, never to a button: someone typing in a form must not tap Seen with the space bar */
    if (full && !el.shown) {
      prevFocus = document.activeElement;
      try { el.card.focus({ preventScroll: true }); } catch (e) {}
    } else if (!full && el.shown && prevFocus && prevFocus.focus) {
      try { prevFocus.focus({ preventScroll: true }); } catch (e) {}
      prevFocus = null;
    }
    el.shown = full;

    /* sound: only while a ring is running, only in the tab that leads, never in quiet hours (SOS excepted) */
    var visible = document.visibilityState === 'visible';
    if (isRinging && !quiet && visible && leader() && selling() && !sos) {
      /* a clerk in the middle of a sale: one short tick per ring, no loop */
      stopLoop();
      sounding.forEach(function (r) {
        var k2 = ringNo(r);
        if (ticked[r.id] !== k2) { ticked[r.id] = k2; play('tick'); }
      });
    } else if (isRinging && !quiet && visible && leader()) {
      startLoop(sos ? 'siren' : 'ring');
      wakeOn();
    } else {
      stopLoop();
      if (!isRinging) { wakeOff(); }
    }
  }
  function uiTick() {
    if (bc && document.visibilityState === 'visible') { try { bc.postMessage({ t: 'here', id: TAB }); } catch (e) {} }
    paint();
  }

  function post(body) {
    return fetch(ACK, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(body)
    }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
  }
  function ackShown() {
    var ids = live().map(function (r) { return r.id; });
    ids.forEach(function (id) { hidden[id] = true; });
    if (bc) { try { bc.postMessage({ t: 'ack', ids: ids }); } catch (e) {} }
    stopLoop(); wakeOff(); paint();
    if (!ids.length) { return Promise.resolve(null); }
    /* more open rings than the card can list (a flood): Seen answers all of them, or the rest would ring again */
    return post(ringCount > rings.length ? { all: 1 } : { ids: ids }).then(function (j) {
      if (!j || !j.ok) { ids.forEach(function (id) { delete hidden[id]; }); }   // could not save: let it come back
      return j;
    });
  }
  function doSeen() { ackShown(); }
  function doOpen() {
    var list = live(), go = list.length === 1 && list[0].link ? list[0].link : '/admin/bookings.php';
    if (list.length && list[0].test) { go = list[0].link || go; }
    ackShown().then(function () { location.href = go; });
  }
  function doMin() {
    live().forEach(function (r) { var k = ringNo(r); if (k >= 0) { mini[r.id] = k; } });
    stopLoop(); paint();
  }

  /* ---- a quiet line + one short sound for the alerts that do not ring ---- */
  function showSoft(items) {
    build();
    if (!el || !items.length) { return; }
    var it = items[0];
    el.soft.textContent = it.title;
    var sm = mk('small', '', (it.channel ? it.channel + ' · ' : '') + (it.body || ''));
    el.soft.appendChild(sm);
    if (it.link) { el.soft.setAttribute('href', it.link); } else { el.soft.removeAttribute('href'); }
    el.soft.className = 'on';
    clearTimeout(softTimer);
    softTimer = setTimeout(function () { el.soft.className = ''; }, 9000);
    if (!cfg.quiet && document.visibilityState === 'visible' && leader()) { play(it.sound || 'pop'); }
  }

  function apply(d) {
    if (!d || typeof d !== 'object') { api.active = false; return; }
    api.active = !!(d.on && d.ring);
    if (typeof d.now === 'number') { skew = d.now - Date.now() / 1000; }
    cfg.seconds = d.seconds || 60; cfg.repeat = d.repeat || 120; cfg.max = typeof d.max === 'number' ? d.max : 3;
    cfg.sound = d.sound !== false; cfg.volume = typeof d.volume === 'number' ? d.volume : 80;
    cfg.quiet = !!d.quiet; cfg.push = d.push || { on: false };
    rings = Array.isArray(d.rings) ? d.rings : [];
    ringCount = d.ringCount || rings.length;
    var keep = {}, id;
    rings.forEach(function (r) { keep[r.id] = true; });
    for (id in hidden) { if (hidden.hasOwnProperty(id) && !keep[id]) { delete hidden[id]; } }
    for (id in mini) { if (mini.hasOwnProperty(id) && !keep[id]) { delete mini[id]; } }
    for (id in ticked) { if (ticked.hasOwnProperty(id) && !keep[id]) { delete ticked[id]; } }

    var soft = Array.isArray(d.soft) ? d.soft : [], fresh = [], maxId = softSeen;
    if (softSeen < 0) { softSeen = parseInt(ss('shg_ring_soft') || '-1', 10); maxId = softSeen; }
    soft.forEach(function (s) { if (s.id > maxId) { maxId = s.id; } if (softSeen >= 0 && s.id > softSeen) { fresh.push(s); } });
    if (softSeen < 0 && !soft.length) { maxId = 0; }
    if (maxId !== softSeen) { softSeen = maxId; ssSet('shg_ring_soft', String(maxId)); }
    if (fresh.length) { showSoft(fresh); }

    /* a handset that ALREADY rings follows whoever is signed in now - once per tab and person (shared counter phones).
       The server leaves every other handset alone: only a tap on "Ring on this phone" starts the ring. */
    var me = String(d.me || '');
    if (cfg.push.on && me && ss('shg_ring_push_sync') !== me) {
      ssSet('shg_ring_push_sync', me); pushHave = null;
      enablePush(false).then(paint);
    } else if (pushHave === null && me && ss('shg_ring_push_have') === me + ':1') { pushHave = true; }
    else if (pushHave === null && me && ss('shg_ring_push_have') === me + ':0') { pushHave = false; }
    paint();
  }

  function tick() {
    var t = Date.now();
    if (t - lastFetch < 4000) { return; }
    lastFetch = t;
    fetch(FEED, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.ok && j.data) { apply(j.data); } else { api.active = false; } })
      .catch(function () {});
  }

  /* ------------------------------------------------------------------
   *  "Ring on this phone" - web push for this handset
   *  ask = true: the person tapped the button (may show the browser's question)
   *  ask = false: permission was given earlier - just bind this handset to who is signed in
   * ------------------------------------------------------------------ */
  function b64u(s) {
    var p = '===='.slice(0, (4 - s.length % 4) % 4), raw = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')), o = new Uint8Array(raw.length), i;
    for (i = 0; i < raw.length; i++) { o[i] = raw.charCodeAt(i); }
    return o;
  }
  function setHave(v) {
    pushHave = !!v;
    var me = ss('shg_ring_push_sync') || '';
    if (me) { ssSet('shg_ring_push_have', me + (pushHave ? ':1' : ':0')); }
  }
  function pushPost(body) {
    return fetch(PUSH, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(body)
    }).then(function (r) { return r.ok ? r.json() : null; });
  }
  function mySub() {
    return navigator.serviceWorker.getRegistration('/').then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; });
  }
  function enablePush(ask) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      return Promise.resolve({ ok: false, why: 'unsupported' });
    }
    if (Notification.permission === 'denied') { setHave(false); return Promise.resolve({ ok: false, why: 'blocked' }); }
    if (Notification.permission !== 'granted' && !ask) { setHave(false); return Promise.resolve({ ok: false, why: 'not asked' }); }
    var perm = Notification.permission === 'granted' ? Promise.resolve('granted') : Notification.requestPermission();
    return Promise.resolve(perm).then(function (p) {
      if (p !== 'granted') { return { ok: false, why: p === 'denied' ? 'blocked' : 'dismissed' }; }
      return fetch(PUSH, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (j) {
          var key = j && j.ok && j.data && j.data.enabled ? j.data.publicKey : '';
          if (!key) { return { ok: false, why: 'off' }; }
          return navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then(function () { return navigator.serviceWorker.ready; })
            .then(function (reg) {
              return reg.pushManager.getSubscription().then(function (s) {
                return s || (ask ? reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(key) }) : null);
              });
            })
            .then(function (s) {
              if (!s) { setHave(false); return { ok: false, why: 'no subscription' }; }
              /* ask: 1 only when the person tapped the button - the server switches a handset on for nothing else */
              return pushPost({ action: 'subscribe', ask: ask ? 1 : 0, subscription: s.toJSON() }).then(function (k) {
                if (!k || !k.ok) { return { ok: false, why: 'error' }; }
                var rings = !!(k.data && k.data.ring);
                setHave(rings);
                return rings ? { ok: true } : { ok: false, why: 'not ringing' };
              });
            });
        });
    }).catch(function () { return { ok: false, why: 'error' }; });
  }
  /* "Stop ringing this phone": only the ring ends - the browser subscription (Office chat) is left alone */
  function disablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) { return Promise.resolve({ ok: false, why: 'unsupported' }); }
    return mySub().then(function (s) {
      if (!s) { setHave(false); return { ok: true }; }
      return pushPost({ action: 'unsubscribe', endpoint: s.endpoint }).then(function (k) {
        /* stopped on purpose: the card does not ask "ring this phone?" again for a month */
        if (k && k.ok) { setHave(false); lsSet('shg_ring_push_later', String(Date.now() + 30 * 86400000)); }
        return { ok: !!(k && k.ok) };
      });
    }).catch(function () { return { ok: false, why: 'error' }; });
  }
  /* does this handset ring for the person signed in? (asks the server; resolves true / false) */
  function pushRinging() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window) || Notification.permission !== 'granted') {
      return Promise.resolve(false);
    }
    return mySub().then(function (s) {
      if (!s) { setHave(false); return false; }
      return pushPost({ action: 'status', endpoint: s.endpoint }).then(function (k) {
        if (!k || !k.ok) { return pushHave === true; }
        setHave(!!(k.data && k.data.ring));
        return pushHave;
      });
    }).catch(function () { return false; });
  }
  function pushState() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return 'unsupported'; }
    return Notification.permission;      // default | granted | denied
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { tick(); paint(); } else { stopLoop(); }
  });

  api.tick = tick;
  api.play = function (name) { unlock(); return play(name); };   // the settings page's "hear it" buttons
  api.voices = function () { var k, a = []; for (k in VOICES) { if (VOICES.hasOwnProperty(k)) { a.push(k); } } return a; };
  api.enablePush = enablePush;
  api.disablePush = disablePush;
  api.pushRinging = pushRinging;
  api.pushState = pushState;
  api.refresh = function () { lastFetch = 0; tick(); };
  window.SHGRing = api;

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', tick); } else { tick(); }
})();
