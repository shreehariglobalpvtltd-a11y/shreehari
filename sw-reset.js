/* =====================================================================
 *  sw-reset.js — SERVICE WORKER KILL SWITCH for the non-www origin.
 *
 *  WHY THIS FILE EXISTS
 *  --------------------
 *  Until 2026-08-27 the site answered on BOTH https://shreehariglobal.in
 *  and https://www.shreehariglobal.in. Phones that opened (or installed
 *  the PWA from) the bare host registered a service worker on THAT
 *  origin. The VPS move then made the bare host a pure `return 301`.
 *
 *  A service worker is scoped per ORIGIN, and the browser fetches the
 *  worker script for its update check with redirect mode "error" — so a
 *  301 on /sw.js is a hard network error and THE UPDATE SILENTLY FAILS,
 *  every time, forever. Those devices are pinned to whatever build they
 *  installed weeks ago: the old worker keeps answering navigations from
 *  its own cache, so no deploy can ever reach them. An installed PWA is
 *  worse — its manifest start_url/scope are relative, so every launch
 *  re-enters on the bare host and hits the same frozen worker. That is
 *  exactly the "everything is stale, especially in the app" report.
 *
 *  This file is served AT /sw.js ON THE NON-WWW ORIGIN ONLY (see the
 *  `location = /sw.js` block in deploy/nginx-shreehariglobal.in.conf).
 *  Because it is a real 200 response, the frozen worker's update check
 *  finally succeeds, byte-compares, sees a different script, and
 *  installs this one — which then deletes every cache and unregisters
 *  itself. The next navigation reaches the network, follows the 301,
 *  and the device lands on www with the current build.
 *
 *  NOTE: there is deliberately NO fetch handler. A worker with no fetch
 *  listener is transparent — requests go straight to the network — so
 *  nothing is served from the dead cache even in the moments before
 *  activate() finishes.
 *
 *  Do NOT point the www origin at this file; www must keep serving the
 *  real sw.js.
 * ===================================================================== */

self.addEventListener('install', function () {
  // Take over without waiting for the old worker's clients to close.
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil((async function () {
    // 1. Drop every cache this origin ever stored (shg-shell-*, shg-assets-*,
    //    shg-tiles-* and anything else a previous build left behind).
    try {
      var keys = await caches.keys();
      await Promise.all(keys.map(function (k) { return caches.delete(k); }));
    } catch (e) {}

    // 2. Remove the registration itself, so this origin is worker-free.
    try { await self.registration.unregister(); } catch (e) {}

    // 3. Reload any open tab/PWA window. With no worker and no cache the
    //    request hits the network and follows the 301 to www.
    try {
      var windows = await self.clients.matchAll({ type: 'window' });
      windows.forEach(function (c) {
        try { c.navigate(c.url); } catch (e) {}
      });
    } catch (e) {}
  })());
});
