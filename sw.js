/* =========================================================
   ATSEDE LIBRARY — sw.js
   Offline shell caching + Web Push display.
   ========================================================= */

const CACHE_NAME = 'atsede-v7';
const OFFLINE_URL = './offline.php';
const PRECACHE = [
  './index.php',
  './offline.php',
  './assets/css/style.css?v=7',
  './assets/js/app.js',
  './assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Network-first for navigations (so data stays fresh), fall back to cache/offline page.
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, copy));
        return res;
      }).catch(() => caches.match(req).then((c) => c || caches.match(OFFLINE_URL)))
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
