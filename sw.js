// StudSphere's service worker — exists mainly to satisfy desktop/home-screen
// PWA installability (browsers require a registered service worker with a
// fetch handler). It only ever caches the static app-shell assets listed
// below; every dynamic PHP response (index.php?page=..., actions, AJAX)
// always goes straight to the network — this app is a live inventory/
// collection tool, caching session-scoped, constantly-changing PHP output
// would risk showing stale or wrong stock/collection data offline.
//
// Cache name is derived from this script's OWN url, which index.php's
// renderPwaHeadTags() registers as "sw.js?v=<current app version>" — a
// version bump alone (no edit to this file) changes that url, which is
// exactly what makes the browser refetch/re-activate this worker and
// therefore rotate the cache below. No manual cache-name bump needed here.
const CACHE_VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE_NAME = 'studsphere-shell-' + CACHE_VERSION;
const PRECACHE_URLS = [
  'style.css',
  'app.js',
  'favicon.svg',
  'logo.svg',
  'manifest.json',
  'icon-192.png',
  'icon-512.png',
  'apple-touch-icon.png',
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
    return url.pathname === '/' + path || url.pathname.endsWith('/' + path);
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
