// The Pickliste PWA's own service worker — scope defaults to this script's
// own directory (/pick/), so it controls exactly /pick/* independently of
// the root app's sw.js. Same "static shell only, everything dynamic goes
// straight to the network" philosophy as ../sw.js (see that file's own doc
// comment) — a live picking tool must never show stale/cached stock data,
// so no PHP response is ever cached here, only this handful of static files.
const CACHE_VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE_NAME = 'studsphere-pick-shell-' + CACHE_VERSION;
const PRECACHE_URLS = [
  'manifest.json',
  '../icon-192.png',
  '../icon-512.png',
  '../apple-touch-icon.png',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) { return cache.addAll(PRECACHE_URLS); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (keys) {
        return Promise.all(
          keys.filter(function (key) { return key !== CACHE_NAME; })
              .map(function (key) { return caches.delete(key); })
        );
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }
  const isPrecached = PRECACHE_URLS.some(function (path) {
    return url.pathname.endsWith('/' + path.replace('../', ''));
  });
  if (!isPrecached) {
    return;
  }
  event.respondWith(
    caches.match(request).then(function (cached) {
      return cached || fetch(request);
    })
  );
});
