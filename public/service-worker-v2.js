const staticCacheName = 'connezer-static-v4';

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(staticCacheName);
    await cache.add(new URL('offline.html', self.registration.scope).href);
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => Promise.all(
      cacheNames
        .filter((cacheName) => cacheName === 'static-v1'
          || cacheName === 'static-v2'
          || (cacheName.startsWith('connezer-static-') && cacheName !== staticCacheName))
        .map((cacheName) => caches.delete(cacheName))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const appPath = new URL(self.registration.scope).pathname;
  if (url.origin !== self.location.origin || !url.pathname.startsWith(appPath)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(request);
      } catch (error) {
        const cache = await caches.open(staticCacheName);
        const offlinePage = await cache.match(new URL('offline.html', self.registration.scope).href);
        if (offlinePage) {
          return offlinePage;
        }
        throw error;
      }
    })());
    return;
  }

  if (!url.pathname.startsWith(`${appPath}assets/`)) {
    return;
  }

  event.respondWith((async () => {
    const cache = await caches.open(staticCacheName);
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
