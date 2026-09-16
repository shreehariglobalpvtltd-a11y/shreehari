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
    install_prompt: 1, lang_switch: 1, error_boundary: 1
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
