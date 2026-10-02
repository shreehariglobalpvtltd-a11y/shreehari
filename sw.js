/* =====================================================================
 *  sw.js — S Hari Global offline service worker (navigator / PWA).
 *
 *  SAFE BY DESIGN — this controls the whole origin, so it is deliberately
 *  conservative:
 *    • /api/* and /admin/* and every non-GET request are NEVER touched
 *      (pure network passthrough) — booking, payments, admin and the
 *      per-session CSRF are never cached or served stale.
 *    • The app document is NETWORK-FIRST: fresh (correct CSRF) whenever
 *      online, and only falls back to the last-good shell when offline.
 *    • Only static map assets — MapLibre's own tiles / glyphs / sprites /
 *      fonts and the MapLibre library itself — are CACHE-FIRST, with a
 *      hard size cap. That is the actual offline-navigation win.
 *    • Anything not explicitly matched is left as a normal network fetch.
 *
 *  Bump VERSION to invalidate old caches on the next deploy.
 * ===================================================================== */

var VERSION = 'shg-v165';   // 2 Oct 2026: stamp b - the overnight live deploy reused 20261002a for older files
var SHELL_CACHE = 'shg-shell-' + VERSION;
/* How long a returning visitor waits for a fresh document before the
   cached app shell opens instead (see the navigate handler). */
var SHELL_WAIT_MS = 3500;
var TILE_CACHE = 'shg-tiles-' + VERSION;
var ASSET_CACHE = 'shg-assets-' + VERSION;
var MAX_TILES = 1500; // cap on cached map assets (FIFO eviction)

/* The passenger's own ticket, kept for the border.
   DELIBERATELY NOT VERSION-SCOPED. Every other cache here is
   'shg-<kind>-' + VERSION so a deploy drops it — correct for code, fatal
   for this one: bumping VERSION the night before travel would silently
   delete the ticket the passenger is relying on. It is therefore a fixed
   name and is explicitly spared in activate() below. The stored object is
   a rendered PNG of an already-issued ticket, so it cannot go "stale" the
   way a JS bundle can; a reschedule re-issues under the same PNR and
   Ticket::reissue() unlinks the old render, so the next online fetch
   replaces it. */
var TICKET_CACHE = 'shg-tickets-v1';
var TICKET_WAIT_MS = 4000;   // how long a fresh ticket may take before the saved copy answers
var MAX_TICKETS = 10;

/* Our own versioned JS/CSS. Every URL carries ?v=, so a cached entry can
   never go stale: a new build changes the URL and the old cache is dropped
   with the old VERSION. This is what makes a re-open feel instant — the
   ~1 MB of app code is read from disk instead of the network. */
var ASSET_VER = '20261002b';
var PRECACHE = [
  '/assets/img/logo.png?v=' + ASSET_VER,
  '/assets/css/app.css?v=' + ASSET_VER,
  '/assets/css/views.css?v=' + ASSET_VER,
  '/assets/css/premium.css?v=' + ASSET_VER,
  '/assets/js/19-premium.js?v=' + ASSET_VER,
  '/assets/js/01-boot.js?v=' + ASSET_VER,
  '/assets/js/02-config.js?v=' + ASSET_VER,
  '/assets/js/03-accounts.js?v=' + ASSET_VER,
  '/assets/js/04-i18n.js?v=' + ASSET_VER,
  '/assets/js/05-router.js?v=' + ASSET_VER,
  '/assets/js/06-results.js?v=' + ASSET_VER,
  '/assets/js/07-checkout.js?v=' + ASSET_VER,
  '/assets/js/14-counter.js?v=' + ASSET_VER,
  '/assets/js/08-signin.js?v=' + ASSET_VER,

  '/assets/js/10-track.js?v=' + ASSET_VER,
  '/assets/js/11-pdf-ticket.js?v=' + ASSET_VER,

  '/assets/js/13-admin-routes.js?v=' + ASSET_VER,
  /* 5 Sep 2026: the navigator is loaded on demand (#/nav) but pre-cached
     here so the offline navigator still opens with no network. */
  '/assets/js/15-nav.js?v=' + ASSET_VER,
  /* 11 Sep 2026: trip companion + assistant + maps chunk, loaded on idle. */
  '/assets/js/16-lazy.js?v=' + ASSET_VER,
  /* 13 Sep 2026: PWA layer (install sheet, push, border card, split-pay,
     seat-fill chart) + the static page shown when even the shell is gone. */
  '/assets/js/17-pwa.js?v=' + ASSET_VER,
  '/assets/js/18-journey.js?v=' + ASSET_VER,
  '/assets/css/journey.css?v=' + ASSET_VER,
  '/assets/img/bus-side.svg?v=' + ASSET_VER,
  /* 20 Sep 2026: the brand face and the splash portrait, so the very
     first frame of a repeat visit needs no network at all. */
  '/assets/fonts/cinzel-latin.woff2?v=' + ASSET_VER,
  '/assets/img/ceo-sm.webp?v=' + ASSET_VER,
  '/offline.html',
  /* 19 Sep 2026: the counter's offline desk - must open with no signal. */
  '/offline-desk.html'
];

/* Cross-origin hosts whose GET responses are safe, static, cacheable map
   assets. Everything else cross-origin (Valhalla routing, Nominatim/Photon
   geocoding, Overpass, Wikipedia) is intentionally absent → passthrough. */
var TILE_HOSTS = [
  'tiles.openfreemap.org',            // OpenFreeMap vector tiles + glyphs + sprite (day/night)
  'server.arcgisonline.com',          // Esri world imagery (satellite)
  'tile.opentopomap.org',             // OpenTopoMap raster (terrain)
  's3.amazonaws.com',                 // Terrarium elevation tiles (3D terrain, 5 Sep 2026)
  'unpkg.com'                         // maplibre-gl.js + maplibre-gl.css
];

self.addEventListener('install', function (event) {
  /* Warm the asset cache, one entry at a time so a single 404 can never
     fail the whole install (cache.addAll is all-or-nothing). */
  event.waitUntil((async function () {
    try {
      var cache = await caches.open(ASSET_CACHE);
      /* 4 Sep 2026: no more `cache:'reload'` — every URL here is versioned
         and served immutable, so the HTTP cache the page just filled is the
         right source. It used to download the whole bundle a second time on
         every first visit. */
      await Promise.all(PRECACHE.map(function (u) {
        return cache.add(new Request(u)).catch(function () {});
      }));
    } catch (e) {}
    try { await self.skipWaiting(); } catch (e) {}
  })());
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    try {
      var keys = await caches.keys();
      await Promise.all(keys.map(function (k) {
        // drop our own older-version caches; leave anything else alone.
        // TICKET_CACHE is spared on purpose — see its declaration above.
        if (k === TICKET_CACHE) return null;
        if (k.indexOf('shg-') === 0 && k.indexOf(VERSION) === -1) return caches.delete(k);
        return null;
      }));
    } catch (e) {}
    try { await self.clients.claim(); } catch (e) {}
  })());
});

/* FIFO-trim a cache to a maximum number of entries (Cache API preserves
   insertion order, so the first keys are the oldest). */
async function trimCache(name, max) {
  try {
    var c = await caches.open(name);
    var keys = await c.keys();
    if (keys.length > max) {
      for (var i = 0; i < keys.length - max; i++) { await c.delete(keys[i]); }
    }
  } catch (e) {}
}

/* Is this the passenger's ticket IMAGE? Not the PDF, not the invoice. */
function isTicketImage(url) {
  return url.pathname === '/download-ticket.php' && url.searchParams.has('img') && !url.searchParams.has('dup');
}

/* Cache key for a ticket, with the HMAC download key stripped.
   Ticket::downloadToken() is derived from APP_KEY, so if that is ever
   rotated every stored URL would miss and the passenger would silently
   lose their offline ticket. Keying on the PNR alone keeps one entry per
   ticket no matter which link it arrived through (WhatsApp, email, the
   app's own button all carry different query orders). */
function ticketKey(url) {
  var pnr = (url.searchParams.get('pnr') || '').toUpperCase();
  return new Request(url.origin + '/download-ticket.php?pnr=' + encodeURIComponent(pnr) + '&img=1');
}

/* The static offline fallback, precached with the assets (13 Sep 2026):
   what a phone with no network AND no cached shell sees - it reads the
   tickets already saved in this browser instead of the browser's dinosaur. */
async function offlinePage() {
  try {
    var c = await caches.open(ASSET_CACHE);
    return (await c.match('/offline.html')) || null;
  } catch (e) { return null; }
}
function netError() {
  try { if (typeof Response.error === 'function') return Response.error(); } catch (e) {}
  return new Response('', { status: 504, statusText: 'offline' });
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return; // never intercept POST/PUT/etc.

  var url;
  try { url = new URL(req.url); } catch (e) { return; }

  /* The passenger's own ticket image — the ONE carve-out from the
     never-cache rule below, and it is deliberately narrow:
       - same origin, exactly /download-ticket.php, and only with ?img=
       - the PDF and the invoice are untouched and stay passthrough
     Rationale: the stated use case is a border post with no data. Without
     this the app falls back to buildTicketCanvas(), which redraws the OLD
     client-side design from localStorage — so the passenger presents a
     ticket that does not match the one the office issued and WhatsApp'd.
     A mismatched ticket at an international border is worse than none.

     Cache-first, because at the border there is no network to revalidate
     against; when there IS a network the response is refreshed in the
     background so a reissue propagates. */
  /* Network first (19 Sep 2026, owner: 'seat / name / number change garepachhi
     ni ticket puranai aunchha'): cache-first showed the pre-edit ticket on the
     first open after a correction. Online, the fresh render wins and refreshes
     the saved copy; the saved copy answers only when the network fails or is
     slower than TICKET_WAIT_MS (the border with no data still works). */
  if (url.origin === self.location.origin && isTicketImage(url)) {
    event.respondWith((async function () {
      var cache = await caches.open(TICKET_CACHE);
      var key   = ticketKey(url);
      var net   = fetch(req).then(async function (res) {
        if (res && res.ok && (res.headers.get('content-type') || '').indexOf('image/') === 0) {
          await cache.put(key, res.clone());
          await trimCache(TICKET_CACHE, MAX_TICKETS);
        } else if (res && (res.status === 403 || res.status === 410)) {
          /* 1 Oct 2026: the server says this ticket is not (or no longer)
             valid - cancelled, rejected, voided. The saved copy must not be
             shown at the gate, so it is dropped here. */
          try { await cache.delete(key); } catch (e) {}
        }
        return res;
      });
      var hit = await cache.match(key);
      if (!hit) {
        try { return await net; }
        catch (e) { return new Response('', { status: 504, statusText: 'offline' }); }
      }
      event.waitUntil(net.catch(function () {}));
      var slow = new Promise(function (r) { setTimeout(function () { r(null); }, TICKET_WAIT_MS); });
      try {
        var res = await Promise.race([net, slow]);
        // A definite 403/410 (voided / not confirmed) outranks the saved copy.
        return (res && (res.ok || res.status === 403 || res.status === 410)) ? res : hit;
      } catch (e) {
        return hit;
      }
    })());
    return;
  }

  // Booking / admin / API surface — pure passthrough, never cached.
  if (url.origin === self.location.origin &&
      (url.pathname.indexOf('/api/') === 0 ||
       url.pathname.indexOf('/admin') === 0 ||
       url.pathname.indexOf('/download-ticket') === 0 ||
       url.pathname.indexOf('/invoice') === 0 ||
       url.pathname.indexOf('/uploads') === 0)) {
    return;
  }

  // Our own versioned app code → cache-first. Safe because the ?v= in the
  // URL changes on every build, so a cached copy is never the wrong build.
  if (url.origin === self.location.origin && url.pathname.indexOf('/assets/') === 0) {
    event.respondWith((async function () {
      var cache = await caches.open(ASSET_CACHE);
      var hit = await cache.match(req);
      if (hit) return hit;
      try {
        var res = await fetch(req);
        if (res && res.ok) { try { await cache.put(req, res.clone()); } catch (e) {} }
        return res;
      } catch (e) {
        return hit || netError();
      }
    })());
    return;
  }

  // Static map assets (cross-origin) → cache-first, capped.
  if (TILE_HOSTS.indexOf(url.hostname) !== -1) {
    event.respondWith((async function () {
      var cache = await caches.open(TILE_CACHE);
      var hit = await cache.match(req);
      if (hit) return hit;
      try {
        var res = await fetch(req);
        if (res && (res.ok || res.type === 'opaque')) {
          try { await cache.put(req, res.clone()); } catch (e) {}
          trimCache(TILE_CACHE, MAX_TILES);
        }
        return res;
      } catch (e) {
        return hit || netError();
      }
    })());
    return;
  }

  /* The offline desk (19 Sep 2026): fresh from the network when there is one,
     the precached copy when there is not. It is the one page whose whole job
     is to open with no signal, so it must never fall through to a net error. */
  if (url.origin === self.location.origin && url.pathname === '/offline-desk.html') {
    event.respondWith(fetch(req).catch(function () {
      return caches.open(ASSET_CACHE).then(function (c) { return c.match('/offline-desk.html'); })
        .then(function (r) { return r || netError(); });
    }));
    return;
  }

  // Same-origin document / SPA navigation → network-first, cache fallback.
  if (req.mode === 'navigate' ||
      (url.origin === self.location.origin && req.destination === 'document')) {
    /* Only the app itself is the "shell". This used to store EVERY navigated
       document under '/', so after opening verify-ticket.php or
       agent-signup.php an offline start of the app rendered that page
       instead (perf pass, 11 Sep 2026). */
    var isShell = url.origin === self.location.origin &&
                  (url.pathname === '/' || url.pathname === '/index.php');
    event.respondWith((async function () {
      var cache = null;
      try { cache = await caches.open(SHELL_CACHE); } catch (e) {}
      var shellPromise = (isShell && cache) ? cache.match('/').catch(function () { return null; }) : Promise.resolve(null);
      var netPromise = fetch(req).then(function (res) {
        if (isShell && cache && res && res.ok && res.type !== 'opaqueredirect') {
          try { event.waitUntil(cache.put('/', res.clone())); } catch (e) {}
        }
        return res;
      });
      /* Network-first, but not network-ONLY: on a slow border-town 3G the
         document used to be the one thing every open waited on, even
         though the whole bundle was already on disk. If the network has not
         answered within SHELL_WAIT_MS and a last-good shell exists, open on
         the shell now; the fetch above keeps running and refreshes the
         cache for the next open. A first visit (no shell) still waits. */
      try {
        var shell = await shellPromise;
        if (!shell) {
          try { return await netPromise; }
          catch (e0) {
            if (isShell) { var off = await offlinePage(); if (off) return off; }
            throw e0;
          }
        }
        var timer;
        var timeout = new Promise(function (resolve) {
          timer = setTimeout(function () { resolve('timeout'); }, SHELL_WAIT_MS);
        });
        var winner = await Promise.race([
          netPromise.then(function (r) { return r; }, function () { return 'error'; }),
          timeout
        ]);
        clearTimeout(timer);
        if (winner === 'timeout' || winner === 'error') {
          event.waitUntil(netPromise.catch(function () {}));
          return shell;
        }
        /* A 5xx mid-deploy (php-fpm reload, opcache window) used to paint the
           nginx error page over an app that was fully cached. */
        if (winner && winner.status >= 500) return shell;
        return winner;
      } catch (e) {
        var shell2 = cache ? await cache.match('/') : null;
        return shell2 || (cache ? await cache.match(req) : null) || (isShell ? await offlinePage() : null) || netError();
      }
    })());
    return;
  }

  // Everything else → default network passthrough (no respondWith).
});

/* The page can ask the worker to pre-download a batch of tile URLs so a
   planned route works fully offline. Bounded + best-effort; failures are
   swallowed so a flaky tile never blocks the rest. */
self.addEventListener('message', function (event) {
  var data = event.data || {};
  /* Auto-update: the page asks the freshly-installed worker to take over
     right now instead of waiting for every tab to close. Paired with
     clients.claim() in activate and a controllerchange reload on the page,
     this is what makes a new deploy reach an already-open app on its own. */
  if (data.type === 'shg-skip-waiting') {
    self.skipWaiting();
    return;
  }
  if (data.type === 'shg-prefetch' && Array.isArray(data.urls)) {
    event.waitUntil((async function () {
      var cache = await caches.open(TILE_CACHE);
      var done = 0, urls = data.urls;
      for (var i = 0; i < urls.length; i++) {
        try {
          var existing = await cache.match(urls[i]);
          if (!existing) {
            var r = await fetch(urls[i]);
            if (r && (r.ok || r.type === 'opaque')) { await cache.put(urls[i], r.clone()); }
          }
        } catch (e) {}
        done++;
      }
      await trimCache(TILE_CACHE, Math.max(MAX_TILES, urls.length + 200));
      try {
        var list = await self.clients.matchAll();
        list.forEach(function (c) { c.postMessage({ type: 'shg-prefetch-done', count: done, total: urls.length }); });
      } catch (e) {}
    })());
  } else if (data.type === 'shg-clear-tiles') {
    event.waitUntil(caches.delete(TILE_CACHE).catch(function () {}));

  /* Store one ticket for offline use. Population is message-driven ONLY —
     the fetch handler above caches what it is asked for, but nothing walks
     the network opportunistically. That matters on a shared office or
     counter device, where silently hoarding every ticket that passed
     through the browser would be a privacy problem. The page asks, once,
     for the ticket it just confirmed. */
  } else if (data.type === 'shg-cache-ticket' && typeof data.pnr === 'string' && data.pnr) {
    event.waitUntil((async function () {
      try {
        var u = new URL('/download-ticket.php', self.location.origin);
        u.searchParams.set('pnr', data.pnr);
        u.searchParams.set('img', '1');
        u.searchParams.set('view', '1');
        if (data.k) { u.searchParams.set('k', data.k); }

        var res = await fetch(u.toString(), { credentials: 'same-origin' });
        // Only a real image is worth keeping: an HTML error page or the
        // keyless 302 into #/my would otherwise be cached AS the ticket.
        var ok = res && res.ok &&
                 (res.headers.get('content-type') || '').indexOf('image/') === 0;
        if (ok) {
          var cache = await caches.open(TICKET_CACHE);
          await cache.put(ticketKey(u), res.clone());
          await trimCache(TICKET_CACHE, MAX_TICKETS);
        }
        var list = await self.clients.matchAll();
        list.forEach(function (c) {
          c.postMessage({ type: 'shg-ticket-cached', pnr: data.pnr, ok: !!ok });
        });
      } catch (e) {}
    })());

  /* Signing out on a shared device must not leave the last passenger's
     ticket readable by the next one. */
  } else if (data.type === 'shg-clear-tickets') {
    event.waitUntil(caches.delete(TICKET_CACHE).catch(function () {}));
  }
});

/* =====================================================================
 *  WEB PUSH (13 Sep 2026) - delay alerts, reminders, "ticket ready".
 *  The payload is JSON encrypted by includes/webpush.php: {title, body,
 *  url, tag, icon, badge, lang}. A tap focuses the open app (and routes it
 *  to the ticket) or opens a new window on it. pushsubscriptionchange
 *  re-registers a rotated endpoint with the server so a phone never goes
 *  silently quiet. Everything is guarded: a worker running where push is
 *  absent (the test harness, an old WebView) just never fires these.
 * ===================================================================== */
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? (event.data.json() || {}) : {}; }
  catch (e) { try { data = { body: event.data ? event.data.text() : '' }; } catch (e2) { data = {}; } }
  if (!self.registration || typeof self.registration.showNotification !== 'function') return;
  var title = data.title || 'S Hari Global';
  var opts = {
    body: data.body || '',
    icon: data.icon || '/assets/img/icon-192.png',
    badge: data.badge || '/assets/img/icon-192.png',
    tag: data.tag || 'shg',
    renotify: !!data.tag,
    lang: data.lang || 'ne',
    data: { url: data.url || '/#/my' },
    vibrate: [120, 60, 120]
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', function (event) {
  try { event.notification.close(); } catch (e) {}
  var url = (event.notification && event.notification.data && event.notification.data.url) || '/#/my';
  event.waitUntil((async function () {
    try {
      var list = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (var i = 0; i < list.length; i++) {
        var c = list[i];
        if (c.url && c.url.indexOf(self.location.origin) === 0) {
          try { c.postMessage({ type: 'shg-open', url: url }); } catch (e) {}
          if ('focus' in c) { await c.focus(); }
          return;
        }
      }
      if (self.clients.openWindow) { await self.clients.openWindow(url); }
    } catch (e) {}
  })());
});

self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil((async function () {
    try {
      var old  = event.oldSubscription || null;
      var opts = (old && old.options) || (event.newSubscription && event.newSubscription.options) || null;
      var sub  = event.newSubscription
        || (opts && self.registration && self.registration.pushManager ? await self.registration.pushManager.subscribe(opts) : null);
      if (!sub || !old) return;
      await fetch('/api/push.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'resubscribe', oldEndpoint: old.endpoint, subscription: sub.toJSON() })
      });
    } catch (e) {}
  })());
});
