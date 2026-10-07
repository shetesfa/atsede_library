/* =========================================================
   ATSEDE LIBRARY — sw.js
   Offline shell caching + Web Push display.
   ========================================================= */

const CACHE_NAME = 'atsede-v16';
const OFFLINE_URL = './offline.php';
const PRECACHE = [
  './',
  './index.php',
  './scan.php',
  './offline.php',
  './shelf_3d.php',
  './search.php',
  './qr.php',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/offline-engine.js',
  './assets/js/jsQR.min.js',
  './assets/js/qrcode.min.js',
  './assets/icons/icon-512.png',
  './assets/icons/icon-192.png',
  './assets/shelf_canvas.jpg',
  './assets/shelf_3d.jpg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      for (const item of PRECACHE) {
        try {
          await cache.add(item);
        } catch (err) {
          // Graceful fallback for non-fatal precache misses
        }
      }
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first for navigations; on offline, always serve the matching cached page (Same View Always)
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, copy));
        return res;
      }).catch(async () => {
        // 1. Try exact match from cache
        const cached = await caches.match(req);
        if (cached) return cached;

        const url = new URL(req.url);

        // 2. Specific routes fallback to their cached equivalents
        if (url.pathname.includes('scan.php')) {
          const scanCached = await caches.match('./scan.php');
          if (scanCached) return scanCached;
        }
        if (url.pathname.includes('shelf_3d.php')) {
          const shelfCached = await caches.match('./shelf_3d.php');
          if (shelfCached) return shelfCached;
        }
        if (url.pathname.includes('search.php')) {
          const searchCached = await caches.match('./search.php');
          if (searchCached) return searchCached;
        }

        // 3. Root navigation fallback to index.php (Always same view as online!)
        const indexCached = await caches.match('./index.php') || await caches.match('./');
        if (indexCached) return indexCached;

        return caches.match(OFFLINE_URL);
      })
    );
    return;
  }

  if (req.destination === 'style' || req.destination === 'script' || req.destination === 'image') {
    event.respondWith(
      caches.match(req).then((cached) => cached || fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, copy));
        return res;
      }).catch(() => cached))
    );
  }
});

// ---------- Push notifications (works even when the app/tab is closed) ----------
self.addEventListener('push', (event) => {
  let data = { title: 'Atsede Library', body: 'You have a new update.', link: './index.php' };
  if (event.data) {
    try { data = { ...data, ...event.data.json() }; } catch (e) { data.body = event.data.text(); }
  }
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: './assets/icons/icon-512.png',
      badge: './assets/icons/icon-512.png',
      vibrate: [100, 50, 100],
      data: { link: data.link || './index.php' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const link = event.notification.data && event.notification.data.link ? event.notification.data.link : './index.php';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then((winList) => {
      for (const win of winList) {
        if (win.url.includes(link) && 'focus' in win) return win.focus();
      }
      if (clients.openWindow) return clients.openWindow(link);
    })
  );
});
