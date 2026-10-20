<?php
/**
 * driver/sw.php - the driver app's own service worker (scope /driver/).
 *
 * Only job: the app OPENS with no network (a border-area dead zone), so a
 * driver can still see the trip and the GPS keeps queueing. Pages are
 * network-first with the last good copy as fallback. The API is never
 * touched: the app itself queues positions and sends them when online.
 * (20 Oct 2026)
 */
declare(strict_types=1);
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
?>
/* SHG driver app service worker */
var CACHE = 'shg-driver-v1';
var SHELL = ['/driver/', '/driver/manifest.php', '/assets/img/icon-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).catch(function () {}));
  self.skipWaiting();
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('shg-driver-') === 0 && k !== CACHE; })
      .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== self.location.origin || url.pathname.indexOf('/api/') === 0) return;
  var isPage = req.mode === 'navigate' && url.pathname.indexOf('/driver/') === 0;
  if (!isPage && SHELL.indexOf(url.pathname) < 0) return;
  e.respondWith(fetch(req).then(function (res) {
    if (res && res.ok) {
      var copy = res.clone();
      caches.open(CACHE).then(function (c) { return c.put(isPage ? '/driver/' : req, copy); }).catch(function () {});
    }
    return res;
  }).catch(function () {
    return caches.match(isPage ? '/driver/' : req).then(function (r) { return r || Response.error(); });
  }));
});
