/* =========================================================
   ATSEDE LIBRARY — sw.js  (v22)
   Full Offline PWA Service Worker:
   - Self-hosted vendor assets (Bootstrap JS, icons, fonts) are precached,
     so the UI is complete with NO network.
   - Navigation fallback ignores the query string (book.php?id=9 -> cached
     book.php shell) and never returns a redirected response (browsers
     reject those for navigations).
   - Independent covers cache for offline cover display.
   - Background Sync for offline borrow / return / payment / add-book.
   ========================================================= */

const CACHE_NAME = 'atsede-v22';
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
  './assets/lib/bootstrap/bootstrap.bundle.min.js',
  './assets/lib/bootstrap-icons/bootstrap-icons.css',
  './assets/lib/bootstrap-icons/fonts/bootstrap-icons.woff2',
  './assets/lib/bootstrap-icons/fonts/bootstrap-icons.woff',
  './assets/lib/fonts/fonts.css',
  './assets/lib/fonts/noto-sans-ethiopic-ethiopic-400-normal.woff2',
  './assets/lib/fonts/noto-sans-ethiopic-ethiopic-500-normal.woff2',
  './assets/lib/fonts/noto-sans-ethiopic-ethiopic-600-normal.woff2',
  './assets/lib/fonts/noto-sans-ethiopic-ethiopic-700-normal.woff2',
  './assets/lib/fonts/noto-serif-ethiopic-ethiopic-500-normal.woff2',
  './assets/lib/fonts/noto-serif-ethiopic-ethiopic-600-normal.woff2',
  './assets/lib/fonts/noto-serif-ethiopic-ethiopic-700-normal.woff2',
  './assets/lib/fonts/ibm-plex-mono-latin-500-normal.woff2',
  './assets/icons/icon-512.png',
  './assets/icons/icon-192.png',
  './assets/icons/icon-maskable-512.png',
  './assets/icons/icon-maskable-192.png',
  './uploads/logo.png',
  './assets/shelf_canvas.jpg',
  './assets/shelf_3d.jpg',
];

// A redirected Response cannot be served to a navigation request.
// Rebuild it as a plain response so it is always safe to return.
async function plain(res) {
  if (!res || !res.redirected) return res;
  const body = await res.clone().blob();
  return new Response(body, {
    status: res.status,
    statusText: res.statusText,
    headers: res.headers,
  });
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      for (const item of PRECACHE) {
        try {
          await cache.add(item);
        } catch (err) {
          // Non-fatal precache miss (e.g. page needs login)
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

  // Never intercept the live API endpoints
  if (url.pathname.includes('/ajax/')) return;

  // 1. Navigation requests (HTML pages)
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).then((res) => {
        // Cache successful page responses (opaque redirects are not ok)
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(async () => {
        // A. Exact URL match (page visited while online)
        const exact = await caches.match(req);
        if (exact) return plain(exact);

        // B. Same page, different query string
        //    (book.php?id=9 -> cached book.php, books.php?page=2 -> books.php)
        const loose = await caches.match(req, { ignoreSearch: true });
        if (loose) return plain(loose);

        // C. Directory URL -> index
        if (url.pathname.endsWith('/')) {
          const idx = await caches.match(url.pathname + 'index.php', { ignoreSearch: true })
            || await caches.match('./index.php');
          if (idx) return plain(idx);
        }

        // D. Universal offline fallback (completely unknown route)
        return plain((await caches.match(OFFLINE_URL)) || (await caches.match('./index.php')));
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
          // opaque (no-cors cross-origin) cover responses are fine to keep
          if (res.ok || res.type === 'opaque') {
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

  // 3. Static Assets (CSS, JS, Fonts) — cache-first, query string ignored
  //    as a fallback so a changed ?v= never leaves the page unstyled offline.
  if (req.destination === 'style' || req.destination === 'script' || req.destination === 'font' ||
      url.pathname.endsWith('.css') || url.pathname.endsWith('.js') || url.pathname.endsWith('.woff2')) {
    event.respondWith(
      caches.match(req).then(async (cached) => {
        if (cached) return cached;
        try {
          const res = await fetch(req);
          if (res.ok || res.type === 'opaque') {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((c) => c.put(req, copy));
          }
          return res;
        } catch (e) {
          const loose = await caches.match(req, { ignoreSearch: true });
          if (loose) return loose;
          throw e;
        }
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
