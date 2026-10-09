/* =========================================================
   ATSEDE LIBRARY — sw.js
   Full Offline PWA Service Worker:
   - Dynamic Offline Navigation (Never bounces unvisited pages to dashboard!)
   - Independent Covers Cache for 100% offline cover display
   - Background Sync for offline borrow / return / payment
   ========================================================= */

const CACHE_NAME = 'atsede-v21';
const COVERS_CACHE = 'atsede-covers-v2';
const OFFLINE_URL = './offline.php';

const PRECACHE = [
  './',
  './index.php',
  './book.php',
  './search.php',
  './scan.php',
  './shelf_3d.php',
  './login.php',
  './register.php',
  './offline.php',
  './qr.php',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/offline-engine.js',
  './assets/js/jsQR.min.js',
  './assets/js/qrcode.min.js',
  './assets/icons/icon-512.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-maskable-512.png',
  './assets/icons/icon-maskable-192.png',
  './uploads/logo.png',
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
      Promise.all(
        keys
          .filter((k) => k !== CACHE_NAME && k !== COVERS_CACHE)
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// Fetch interception
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // 1. Navigation requests (HTML pages)
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        // Cache successful page response
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(async () => {
        // Offline Fallback Handling:
        // A. Exact URL match (e.g. visited while online)
        const exact = await caches.match(req);
        if (exact) return exact;

        // B. Route-specific Shells (CRITICAL: Never bounce to index.php!)
        if (url.pathname.includes('book.php')) {
          const bookShell = await caches.match('./book.php') || await caches.match('/library/book.php');
          if (bookShell) return bookShell;
        }

        if (url.pathname.includes('search.php')) {
          const searchShell = await caches.match('./search.php') || await caches.match('/library/search.php');
          if (searchShell) return searchShell;
        }

        if (url.pathname.includes('scan.php')) {
          const scanShell = await caches.match('./scan.php') || await caches.match('/library/scan.php');
          if (scanShell) return scanShell;
        }

        if (url.pathname.includes('shelf_3d.php')) {
          const shelfShell = await caches.match('./shelf_3d.php') || await caches.match('/library/shelf_3d.php');
          if (shelfShell) return shelfShell;
        }

        if (url.pathname.includes('login.php')) {
          const loginShell = await caches.match('./login.php') || await caches.match('/library/login.php');
          if (loginShell) return loginShell;
        }

        if (url.pathname.includes('register.php')) {
          const regShell = await caches.match('./register.php') || await caches.match('/library/register.php');
          if (regShell) return regShell;
        }

        if (url.pathname.endsWith('/library/') || url.pathname.endsWith('/library') || url.pathname.endsWith('index.php')) {
          const indexCached = await caches.match('./index.php') || await caches.match('./');
          if (indexCached) return indexCached;
        }

        // C. Universal Offline Fallback (Only if completely unknown route)
        return (await caches.match(OFFLINE_URL)) || (await caches.match('./index.php'));
      })
    );
    return;
  }

  // 2. Images (Book covers, logos, icons, thumbnails)
  if (req.destination === 'image' || url.pathname.includes('/uploads/') || url.hostname.includes('raw.githubusercontent.com')) {
    event.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;

        return fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(COVERS_CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(async () => {
          // Fallback image if totally offline and image not cached
          return (await caches.match('./uploads/logo.png')) || (await caches.match('./assets/icons/icon-512.png'));
        });
      })
    );
    return;
  }

  // 3. Static Assets (CSS, JS, Fonts)
  if (req.destination === 'style' || req.destination === 'script' || req.destination === 'font' || url.pathname.endsWith('.css') || url.pathname.endsWith('.js')) {
    event.respondWith(
      caches.match(req).then((cached) => {
        if (cached) return cached;
        return fetch(req).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => cached);
      })
    );
    return;
  }
});

// ---------- Background Sync API ----------
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-library-events' || event.tag === 'sync-queue') {
    event.waitUntil(
      self.clients.matchAll({ type: 'window' }).then((clients) => {
        clients.forEach((client) => {
          client.postMessage({ type: 'TRIGGER_BACKGROUND_SYNC' });
        });
      })
    );
  }
});

// ---------- Push Notifications ----------
self.addEventListener('push', (event) => {
  let data = { title: 'አጸደ ቤተ-መጻሕፍት', body: 'አዲስ መልእክት ደርሶዎታል።', link: './index.php' };
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
