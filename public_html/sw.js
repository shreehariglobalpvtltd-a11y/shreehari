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

var VERSION = 'shg-v1';
var SHELL_CACHE = 'shg-shell-' + VERSION;
var TILE_CACHE = 'shg-tiles-' + VERSION;
var MAX_TILES = 1500; // cap on cached map assets (FIFO eviction)

/* Cross-origin hosts whose GET responses are safe, static, cacheable map
   assets. Everything else cross-origin (Valhalla routing, Nominatim/Photon
   geocoding, Overpass, Wikipedia) is intentionally absent → passthrough. */
var TILE_HOSTS = [
  'tiles.openfreemap.org',            // OpenFreeMap vector tiles + glyphs + sprite (day/night)
  'server.arcgisonline.com',          // Esri world imagery (satellite)
  'tile.opentopomap.org',             // OpenTopoMap raster (terrain)
  'unpkg.com'                         // maplibre-gl.js + maplibre-gl.css
];

self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    try {
      var keys = await caches.keys();
      await Promise.all(keys.map(function (k) {
        // drop our own older-version caches; leave anything else alone
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

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return; // never intercept POST/PUT/etc.

  var url;
  try { url = new URL(req.url); } catch (e) { return; }

  // Booking / admin / API surface — pure passthrough, never cached.
  if (url.origin === self.location.origin &&
      (url.pathname.indexOf('/api/') === 0 ||
       url.pathname.indexOf('/admin') === 0 ||
       url.pathname.indexOf('/download-ticket') === 0 ||
       url.pathname.indexOf('/invoice') === 0 ||
       url.pathname.indexOf('/uploads') === 0)) {
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
        return hit || Response.error();
      }
    })());
    return;
  }

  // Same-origin document / SPA navigation → network-first, cache fallback.
  if (req.mode === 'navigate' ||
      (url.origin === self.location.origin && req.destination === 'document')) {
    event.respondWith((async function () {
      try {
        var res = await fetch(req);
        if (res && res.ok && res.type !== 'opaqueredirect') {
          try {
            var cache = await caches.open(SHELL_CACHE);
            await cache.put('/', res.clone()); // keep the last-good app shell
          } catch (e) {}
        }
        return res;
      } catch (e) {
        var cache2 = await caches.open(SHELL_CACHE);
        var shell = await cache2.match('/');
        return shell || (await cache2.match(req)) || Response.error();
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
  }
});
