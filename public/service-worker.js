self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => Promise.all(
      cacheNames
        .filter((cacheName) => cacheName === 'static-v1'
          || cacheName === 'static-v2'
          || (cacheName.startsWith('connezer-static-') && cacheName !== 'connezer-static-v3'))
        .map((cacheName) => caches.delete(cacheName))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode === 'navigate') {
    return;
  }

  const url = new URL(request.url);
  const appPath = new URL(self.registration.scope).pathname;
  if (url.origin !== self.location.origin || !url.pathname.startsWith(`${appPath}assets/`)) {
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open('connezer-static-v3');
    try {
      const response = await fetch(request);
      if (response.ok) {
        await cache.put(request, response.clone());
      }
      return response;
    } catch (error) {
      const cached = await cache.match(request);
      if (cached) {
        return cached;
      }
      throw error;
    }
  })());
});
