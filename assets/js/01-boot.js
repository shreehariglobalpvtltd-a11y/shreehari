/* =====================================================================
 *  01-boot.js — the product beacon.
 *
 *  (This file was the RETIRED homepage hero animator — audit H3, 25 Aug
 *  2026 — kept as a no-op so its <script> tag would not 404. It now earns
 *  its place again, and it is the right home for this: it loads first,
 *  depends on nothing, and defines nothing anyone else needs.)
 *
 *  WHAT IT DOES
 *  Reports, fire-and-forget, where people get stuck: which view they moved
 *  to, which search came back empty, which checkout step they left from,
 *  which sentence the ticket parser could not read. Until now none of that
 *  was recorded anywhere, so every UX decision was a guess.
 *
 *  WHY IT IS WRITTEN THIS DEFENSIVELY
 *  This script runs before the app does. A beacon that throws during boot
 *  does not lose us an analytics row — it takes the whole booking app down
 *  with it on somebody's phone in Surat. So: everything is inside one
 *  try/catch, every entry point re-guards, and a failure at ANY point
 *  disables the beacon for the rest of the visit rather than retrying.
 *  The app never reads a return value from it and never waits on it.
 *
 *  WHAT IT NEVER SENDS
 *  No phone number, no name, no PNR, no free text the customer typed. Only
 *  an allow-listed event name and allow-listed short props — and the
 *  SERVER re-checks both and redacts again (api/events.php), because a
 *  privacy promise that only exists in the client is a promise that any
 *  future edit can quietly break.
 *
 *  The visit id is a random value that lives in sessionStorage: it ties a
 *  handful of events into one visit and dies with the tab. It is not a
 *  person, and nothing joins it to a booking.
 * ===================================================================== */

(function () {
  'use strict';

  var ENDPOINT = '/api/events.php';

  /* Mirrors ALLOWED_EVENTS in api/events.php. Kept here too so a typo
     costs nothing at all rather than a dropped request. */
  var ALLOWED = {
    view: 1, search: 1, search_empty: 1, seat_open: 1, checkout_step: 1,
    checkout_drop: 1, quick_ticket_open: 1, parse_miss: 1, offline_hit: 1,
    install_prompt: 1, lang_switch: 1, error_boundary: 1, landing: 1
  };
  var PROPS = [
    'from', 'to', 'view', 'step', 'field', 'lang', 'direction',
    'date_offset', 'seats', 'ms', 'count', 'ok', 'reason', 'source'
  ];

  var dead = false;     // one failure and we stop for this visit
  var sid = '';

  function visitId() {
    if (sid) return sid;
    try {
      sid = sessionStorage.getItem('shg_vid') || '';
      if (!sid) {
        /* crypto.randomUUID is not on the older Android WebViews this app
           still has to run on, so fall back rather than throwing. */
        if (window.crypto && window.crypto.getRandomValues) {
          var a = new Uint8Array(16);
          window.crypto.getRandomValues(a);
          sid = Array.prototype.map.call(a, function (b) {
            return ('0' + b.toString(16)).slice(-2);
          }).join('');
        } else {
          sid = (Date.now().toString(16) + Math.random().toString(16).slice(2)).slice(0, 32);
        }
        sessionStorage.setItem('shg_vid', sid);
      }
    } catch (e) {
      /* Private mode, or storage disabled. An anonymous event with no visit
         id is still useful; a crash is not. */
      sid = '';
    }
    return sid;
  }

  /** Keep only the allow-listed props, as short scalars. */
  function clean(props) {
    var out = {};
    if (!props) return out;
    for (var i = 0; i < PROPS.length; i++) {
      var k = PROPS[i];
      if (!(k in props)) continue;
      var v = props[k];
      var t = typeof v;
      if (t === 'boolean' || t === 'number') {
        out[k] = v;
      } else if (t === 'string' && v) {
        out[k] = v.slice(0, 60);
      }
      /* Objects and arrays are dropped, not flattened: a nested payload is
         where unreviewed personal data hides. */
    }
    return out;
  }

  /**
   * Report one event. Returns nothing, throws nothing, blocks nothing.
   */
  function beacon(name, props) {
    if (dead) return;
    try {
      if (!ALLOWED[name]) return;

      var body = JSON.stringify({
        name: name,
        sid: visitId(),
        /* The hash is the app's route. It can carry a PNR (#/ticket/SHG-…),
           so only the route SHAPE is sent — the id is replaced before it
           ever leaves the phone. */
        path: (location.hash || '#/').replace(/[A-Za-z0-9-]{6,}/g, '#').slice(0, 160),
        props: clean(props)
      });

      if (navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'application/json' }));
      } else if (window.fetch) {
        fetch(ENDPOINT, {
          method: 'POST',
          body: body,
          headers: { 'Content-Type': 'application/json' },
          keepalive: true,
          credentials: 'same-origin'
        }).catch(function () { /* fire and forget */ });
      }
    } catch (e) {
      /* Whatever it was, do not do it again this visit. */
      dead = true;
    }
  }

  try {
    window.shgBeacon = beacon;

    /* View transitions, taken from the hash rather than by patching the
       router: no other file has to know this exists, and if this listener
       never runs the app is exactly as it was. */
    var last = '';
    function onRoute() {
      try {
        var view = (location.hash || '#/').split('/')[1] || 'home';
        view = view.split('?')[0].slice(0, 30);
        if (view === last) return;
        beacon('view', { from: last, to: view });
        last = view;
      } catch (e) { dead = true; }
    }
    window.addEventListener('hashchange', onRoute);
    onRoute();

    /* A JS error that reaches the top level is the single most valuable
       thing to know about and the least likely to be reported by hand. The
       MESSAGE is deliberately not sent — only that one happened, and from
       which script — because error strings routinely quote user input. */
    window.addEventListener('error', function (ev) {
      try {
        var src = ev && ev.filename ? String(ev.filename).split('/').pop().slice(0, 40) : '';
        beacon('error_boundary', { source: src, reason: 'js' });
      } catch (e) { dead = true; }
    });
  } catch (e) {
    dead = true;
  }
}());

/* ---------------------------------------------------------------------
   Formatter cache (5 Oct 2026). `date.toLocaleDateString(locale, opts)` and
   `number.toLocaleString(locale)` build a brand-new Intl formatter on EVERY
   call - about 2 ms each. The home page's 30-day date strip made 90 of them
   (twice): 227 ms on a desktop, about a second of frozen screen on an
   ordinary phone. One formatter per (locale, options) is kept instead; the
   printed text is identical. Anything unusual falls through to the browser.
   --------------------------------------------------------------------- */
(function () {
  try {
    if (typeof Intl === 'undefined' || !Intl.DateTimeFormat || !Intl.NumberFormat) return;
    var dC = {}, nC = {}, nativeD = Date.prototype.toLocaleDateString, nativeN = Number.prototype.toLocaleString;
    Date.prototype.toLocaleDateString = function (loc, opts) {
      if (typeof loc === 'string' && opts && typeof opts === 'object'
          && (opts.weekday || opts.year || opts.month || opts.day)
          && !opts.dateStyle && !opts.timeStyle && !opts.hour && !opts.minute && !opts.second) {
        try {
          var k = loc + '|' + JSON.stringify(opts);
          return (dC[k] || (dC[k] = new Intl.DateTimeFormat(loc, opts))).format(this);
        } catch (e) {}
      }
      return nativeD.apply(this, arguments);
    };
    Number.prototype.toLocaleString = function (loc, opts) {
      if (typeof loc === 'string') {
        try {
          var k = loc + '|' + (opts ? JSON.stringify(opts) : '');
          return (nC[k] || (nC[k] = new Intl.NumberFormat(loc, opts))).format(this);
        } catch (e) {}
      }
      return nativeN.apply(this, arguments);
    };
  } catch (e) {}
}());

/* ---------------------------------------------------------------------
   Device tier + TV (10 Oct 2026, owner: "feel like smooth 165 Hz
   animation ... best performance, works on TV too, on every device").

   Stamps <html data-tier="low|mid|high"> once per visit, and html.shg-tv
   on a television browser, so assets/css/perf.css can drop the decoration
   that costs frames on a weak phone (blur, stacked shadows, the parallax
   layers, decorative loops) and give a TV its 10-foot type and d-pad focus
   rings. Every tier keeps every feature; only paint cost changes.

   Signals: CPU cores, device memory (Chromium only), the network (Save-Data,
   2G / 3G), reduced motion (the system setting or the app's own switch) and
   a frame-rate probe - one burst of ~20 animation frames taken once per
   visit when the page is idle, kept in sessionStorage. A probe under
   40 fps marks the device low. window.SHG_TIER = { tier, cores, memGB,
   fps, tv } for any script that wants it. Nothing is sent anywhere.
   --------------------------------------------------------------------- */
(function () {
  try {
    var d = document.documentElement, nav = window.navigator || {};
    var KEY = 'shg:tier', FPS_KEY = 'shg:fps';
    var TV_RE = /SmartTV|SMART-TV|BRAVIA|Tizen|Web0S|WebOS|NetCast|AFTT|AFTM|CrKey|Roku|Viera|HbbTV|AndroidTV|Android TV|GoogleTV/;
    var read = function (k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } };
    var write = function (k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* private mode: measured again next load */ } };

    function measure() {
      var conn = nav.connection || nav.mozConnection || nav.webkitConnection || null;
      var cores = +nav.hardwareConcurrency || 0;
      var memGB = +nav.deviceMemory || 0;                 // 0 = not reported (Safari, Firefox)
      var et = conn && conn.effectiveType ? String(conn.effectiveType) : '';
      var save = !!(conn && conn.saveData);
      var still = d.getAttribute('data-motion') === 'reduced';
      try { still = still || !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
      var fps = +read(FPS_KEY) || 0;
      var tv = TV_RE.test(nav.userAgent || '');
      /* Safari reports no memory and rounds its core count down for privacy,
         so a low core count only counts when a memory figure came with it. */
      var tier = 'high';
      if (save || et === 'slow-2g' || et === '2g' || (memGB && memGB <= 2) || (memGB && cores && cores <= 2) || (fps && fps < 40)) tier = 'low';
      else if (tv || still || et === '3g' || (memGB && memGB <= 4) || (cores && cores <= 4)) tier = 'mid';
      return { tier: tier, cores: cores, memGB: memGB, fps: fps, tv: tv };
    }
    function apply(t) {
      d.setAttribute('data-tier', t.tier);
      if (t.tv) d.classList.add('shg-tv'); else d.classList.remove('shg-tv');
      window.SHG_TIER = t;
    }

    var cur = null;
    try { cur = JSON.parse(read(KEY) || 'null'); } catch (e) { cur = null; }
    if (!cur || !/^(low|mid|high)$/.test(String(cur.tier))) {
      cur = measure();
      write(KEY, JSON.stringify(cur));
    }
    apply(cur);

    /* The probe: after load, when the main thread is idle and the tab is on
       screen. The two longest gaps are dropped (a garbage collection or an
       image decode is not the screen's speed); a gap over a second means the
       tab was hidden mid-burst and the reading is thrown away. */
    if (cur.tier !== 'low' && !read(FPS_KEY) && window.requestAnimationFrame) {
      var probe = function () {
        if (document.hidden) return;
        var times = [];
        var step = function (ts) {
          times.push(ts);
          if (times.length < 21) { window.requestAnimationFrame(step); return; }
          var gaps = [];
          for (var i = 1; i < times.length; i++) gaps.push(times[i] - times[i - 1]);
          gaps.sort(function (a, b) { return a - b; });
          if (gaps[gaps.length - 1] > 1000 || document.hidden) return;
          gaps = gaps.slice(0, gaps.length - 2);
          var sum = 0;
          for (var j = 0; j < gaps.length; j++) sum += gaps[j];
          var fps = sum > 0 ? Math.round(1000 * gaps.length / sum) : 0;
          if (!fps) return;
          write(FPS_KEY, String(fps));
          cur.fps = fps;
          if (fps < 40) cur.tier = 'low';
          write(KEY, JSON.stringify(cur));
          apply(cur);
        };
        window.requestAnimationFrame(step);
      };
      var later = function () {
        setTimeout(function () {
          if (window.requestIdleCallback) window.requestIdleCallback(probe, { timeout: 3000 });
          else probe();
        }, 2500);
      };
      if (document.readyState === 'complete') later();
      else window.addEventListener('load', later, { once: true });
    }
  } catch (e) { /* no tier: perf.css treats the page as mid (no low cuts, no high-only rings) */ }
}());
