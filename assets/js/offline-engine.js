/* =========================================================
   ATSEDE LIBRARY — offline-engine.js
   True 100% Offline Engine:
   - IndexedDB Storage (Books, Copies, Categories, Members, Queue)
   - Silent Background Caching of All Book Covers
   - Dynamic Offline Client-Side Hydration for book.php & search.php
   - Background Sync Queue for Borrow / Return / Payments
   ========================================================= */

class OfflineEngine {
  constructor() {
    this.dbName = 'atsede_offline_db';
    this.dbVersion = 3;
    this.db = null;
    this.deviceId = this.getDeviceId();
    this.isOnline = navigator.onLine;
  }

  getDeviceId() {
    let id = localStorage.getItem('atsede_device_id');
    if (!id) {
      id = 'dev_' + Math.random().toString(36).substring(2, 12) + '_' + Date.now();
      localStorage.setItem('atsede_device_id', id);
    }
    return id;
  }

  generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  async openDB() {
    if (this.db) return this.db;
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.dbVersion);

      request.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains('books')) {
          db.createObjectStore('books', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('categories')) {
          db.createObjectStore('categories', { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains('members')) {
          db.createObjectStore('members', { keyPath: 'member_id' });
        }
        if (!db.objectStoreNames.contains('sync_queue')) {
          db.createObjectStore('sync_queue', { keyPath: 'event_uuid' });
        }
        if (!db.objectStoreNames.contains('failed_events')) {
          db.createObjectStore('failed_events', { keyPath: 'event_uuid' });
        }
        if (!db.objectStoreNames.contains('meta')) {
          db.createObjectStore('meta', { keyPath: 'key' });
        }
      };

      request.onsuccess = (e) => {
        this.db = e.target.result;
        resolve(this.db);
      };

      request.onerror = (e) => {
        console.error('IndexedDB open error:', e);
        reject(e);
      };
    });
  }

  async put(storeName, item) {
    const db = await this.openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readwrite');
      const store = tx.objectStore(storeName);
      store.put(item);
      tx.oncomplete = () => resolve(true);
      tx.onerror = (e) => reject(e);
    });
  }

  async get(storeName, key) {
    const db = await this.openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readonly');
      const store = tx.objectStore(storeName);
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = (e) => reject(e);
    });
  }

  async getAll(storeName) {
    const db = await this.openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readonly');
      const store = tx.objectStore(storeName);
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = (e) => reject(e);
    });
  }

  async delete(storeName, key) {
    const db = await this.openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, 'readwrite');
      tx.objectStore(storeName).delete(key);
      tx.oncomplete = () => resolve(true);
      tx.onerror = (e) => reject(e);
    });
  }

  async getCachedBooks() {
    return this.getAll('books');
  }

  async getCachedCategories() {
    return this.getAll('categories');
  }

  async getCachedMembers() {
    return this.getAll('members');
  }

  async getPendingSyncCount() {
    const all = await this.getAll('sync_queue');
    return all.filter((e) => e.status === 'pending').length;
  }

  // ---------- Silent Background Priming of Data & Covers ----------
  async refreshCache() {
    if (!navigator.onLine) return;
    try {
      const base = window.APP_BASE || './';
      const res = await fetch(base + 'ajax/offline_bootstrap.php');
      if (!res.ok) return;
      const data = await res.json();
      if (!data.success) return;

      const db = await this.openDB();
      const tx = db.transaction(['books', 'categories', 'members', 'meta'], 'readwrite');

      if (data.books && Array.isArray(data.books)) {
        const bStore = tx.objectStore('books');
        data.books.forEach((b) => bStore.put(b));
      }

      if (data.categories && Array.isArray(data.categories)) {
        const cStore = tx.objectStore('categories');
        data.categories.forEach((c) => cStore.put(c));
      }

      if (data.members && Array.isArray(data.members)) {
        const mStore = tx.objectStore('members');
        data.members.forEach((m) => mStore.put(m));
      }

      const metaStore = tx.objectStore('meta');
      metaStore.put({ key: 'last_synced_at', value: new Date().toISOString() });
      if (data.settings) metaStore.put({ key: 'settings', value: data.settings });
      if (data.user) {
        metaStore.put({ key: 'current_user', value: data.user });
        localStorage.setItem('atsede_offline_user', JSON.stringify(data.user));
      }

      tx.oncomplete = () => {
        // Silently pre-cache book covers in background (no annoying popups)
        if (data.books && Array.isArray(data.books)) {
          this.cacheCoversInBackground(data.books);
        }
      };
    } catch (err) {
      console.warn('Could not refresh offline cache:', err);
    }
  }

  // Silently cache all book cover images with throttled concurrency
  async cacheCoversInBackground(books) {
    if (!('caches' in window)) return;
    try {
      const coversCache = await caches.open('atsede-covers-v2');
      const urlsToCache = [];

      for (const b of books) {
        if (b.cover_url && typeof b.cover_url === 'string') {
          urlsToCache.push(b.cover_url);
        }
      }

      // Add logo and icons
      const root = window.APP_ROOT || '/library/';
      urlsToCache.push(root + 'uploads/logo.png');
      urlsToCache.push(root + 'assets/icons/icon-512.png');

      // Fetch and cache silently with batch concurrency
      const batchSize = 4;
      let cachedCount = 0;

      for (let i = 0; i < urlsToCache.length; i += batchSize) {
        const batch = urlsToCache.slice(i, i + batchSize);
        await Promise.allSettled(
          batch.map(async (url) => {
            try {
              const match = await coversCache.match(url);
              if (!match) {
                const response = await fetch(url, { mode: 'no-cors' });
                if (response) {
                  await coversCache.put(url, response);
                  cachedCount++;
                }
              } else {
                cachedCount++;
              }
            } catch (e) {}
          })
        );
      }

      await this.put('meta', { key: 'cached_covers_count', value: cachedCount });
    } catch (e) {
      console.warn('Background cover caching error:', e);
    }
  }

  // Queue an offline event (PAYMENT, BORROW, RETURN, BORROW_REQUEST)
  async queueEvent(operationType, payload) {
    const uuid = this.generateUUID();
    const event = {
      event_uuid: uuid,
      operation_type: operationType,
      payload: payload,
      device_id: this.deviceId,
      timestamp: new Date().toISOString(),
      status: 'pending',
    };

    await this.put('sync_queue', event);
    this.updatePendingBadge();

    // If online, attempt to sync immediately
    if (navigator.onLine) {
      this.syncQueue();
    }
    return event;
  }

  // Drain sync queue to server
  async syncQueue() {
    if (!navigator.onLine) return;
    const events = await this.getAll('sync_queue');
    const pending = events.filter((e) => e.status === 'pending');
    if (pending.length === 0) {
      this.updatePendingBadge();
      return;
    }

    try {
      const base = window.APP_BASE || './';
      const res = await fetch(base + 'ajax/offline_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          device_id: this.deviceId,
          events: pending,
        }),
      });

      if (res.status === 401) {
        return;
      }

      if (!res.ok) return;
      const data = await res.json();
      if (data.success && Array.isArray(data.results)) {
        for (const r of data.results) {
          if (r.status === 'synced') {
            await this.delete('sync_queue', r.event_uuid);
          } else if (r.status === 'rejected') {
            await this.delete('sync_queue', r.event_uuid);
            try {
              await this.put('failed_events', {
                event_uuid: r.event_uuid,
                rejected_at: new Date().toISOString(),
                reason: r.message || 'ክስተቱ ውድቅ ተደርጓል',
              });
            } catch (err) {}
          }
        }
        this.updatePendingBadge();
        this.refreshCache();
      }
    } catch (err) {
      console.warn('Sync queue failed:', err);
    }
  }

  async updatePendingBadge() {
    const events = await this.getAll('sync_queue');
    const pending = events.filter((e) => e.status === 'pending');
    const count = pending.length;
    let badge = document.getElementById('offline-sync-badge');

    if (count > 0) {
      if (!badge) {
        badge = document.createElement('div');
        badge.id = 'offline-sync-badge';
        badge.style.cssText =
          'position:fixed;bottom:75px;right:16px;z-index:999;background:#D4AF37;color:#0F172A;padding:6px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,0.25);cursor:pointer;';
        badge.onclick = () => this.syncQueue();
        document.body.appendChild(badge);
      }
      badge.innerHTML = `<i class="bi bi-arrow-repeat"></i> ${count} ያልተመሳሰለ`;
      badge.style.display = 'block';
    } else if (badge) {
      badge.style.display = 'none';
    }
  }

  // Network Status Banner
  setupNetworkBanner() {
    let banner = document.getElementById('offline-network-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'offline-network-banner';
      banner.style.cssText =
        'display:none;position:sticky;top:0;z-index:1100;padding:8px 12px;text-align:center;font-size:0.8rem;font-weight:600;transition:all 0.3s;';
      document.body.prepend(banner);
    }

    const updateStatus = () => {
      if (navigator.onLine) {
        banner.style.background = '#10B981';
        banner.style.color = '#ffffff';
        banner.innerHTML = '<i class="bi bi-wifi"></i> መስመር ላይ ተመልሰዋል (Online)';
        banner.style.display = 'block';
        this.syncQueue();
        setTimeout(() => {
          banner.style.display = 'none';
        }, 2200);
      } else {
        banner.style.background = '#F59E0B';
        banner.style.color = '#0F172A';
        banner.innerHTML =
          '<i class="bi bi-wifi-off"></i> <strong>ከመስመር ውጭ ነዎት (Offline Mode)</strong> — መረጃዎች ከስልክዎ/ኮምፒውተርዎ እየሰሩ ነው';
        banner.style.display = 'block';
        this.checkAndHydrateCurrentPage();
      }
    };

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);

    if (!navigator.onLine) {
      updateStatus();
    }
  }

  // ---------- Dynamic Client-Side Hydration for Offline Navigation ----------
  checkAndHydrateCurrentPage() {
    const path = window.location.pathname;

    if (path.includes('book.php')) {
      this.hydrateOfflineBookPage();
    } else if (path.includes('search.php')) {
      this.hydrateOfflineSearchPage();
    }
  }

  async hydrateOfflineBookPage() {
    const urlParams = new URLSearchParams(window.location.search);
    const bookId = parseInt(urlParams.get('id') || '0', 10);
    if (!bookId) return;

    const serverContainer = document.getElementById('book-server-container');
    const offlineContainer = document.getElementById('book-offline-container');
    const notFoundEl = document.getElementById('book-not-found');

    if (!offlineContainer) return;

    // Check if server container has the right book ID rendered
    const serverBookId = serverContainer ? parseInt(serverContainer.getAttribute('data-book-id') || '0', 10) : 0;
    const isServerMismatch = !serverContainer || serverBookId !== bookId;

    // If offline or server mismatch, render directly from IndexedDB
    if (!navigator.onLine || isServerMismatch) {
      const book = await this.get('books', bookId);
      if (book) {
        if (serverContainer) serverContainer.style.display = 'none';
        if (notFoundEl) notFoundEl.style.display = 'none';
        offlineContainer.style.display = 'block';

        const root = window.APP_ROOT || '/library/';
        const fallbackLogo = root + 'uploads/logo.png';
        const coverSrc = book.cover_url || fallbackLogo;
        const authorText = book.author && book.author.trim() ? book.author : 'ጸሃፊው አልተገለጸም';
        const availCount = book.available_copies !== undefined ? book.available_copies : 1;
        const isBorrowable = book.is_borrowable !== 0;

        offlineContainer.innerHTML = `
          <div class="d-flex justify-content-between align-items-center mb-2">
            <a href="javascript:history.back()" class="text-muted" style="font-size:.85rem;display:inline-flex;align-items:center;gap:4px;">
              <i class="bi bi-arrow-left"></i> ወደ ኋላ ተመለስ
            </a>
            <span class="badge badge-warning" style="font-size:0.75rem;"><i class="bi bi-cloud-slash"></i> ኦፍላይን እይታ</span>
          </div>

          <!-- Book Main Card -->
          <div class="card mb-3" style="overflow:hidden;">
            <div class="row g-0">
              <div class="col-4 col-md-3">
                <div class="book-cover" style="aspect-ratio:3/4;width:100%;max-width:200px;margin:0 auto;position:relative;background:#f1f5f9;display:flex;align-items:center;justify-content:center;">
                  <img src="${coverSrc}" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='${fallbackLogo}';">
                </div>
              </div>
              <div class="col-8 col-md-9">
                <div class="card-pad">
                  <div class="d-flex flex-wrap gap-1 mb-2">
                    ${isBorrowable ? '<span class="badge badge-success"><i class="bi bi-check-circle"></i> ይዋሳል</span>' : '<span class="badge badge-danger"><i class="bi bi-x-circle"></i> አይዋስም</span>'}
                    ${availCount > 0 ? `<span class="badge badge-gold"><i class="bi bi-box-seam"></i> ${availCount} ቅጂ ይገኛል</span>` : '<span class="badge badge-muted"><i class="bi bi-clock"></i> አሁን አይገኝም</span>'}
                  </div>

                  <h2 class="font-display" style="font-size:1.25rem;color:var(--navy);margin:0 0 4px;line-height:1.3;">
                    ${this.escapeHtml(book.title)}
                  </h2>
                  <div class="text-muted" style="font-size:.88rem;margin-bottom:8px;">በ ${this.escapeHtml(authorText)}</div>
                  
                  <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="badge badge-navy">${this.escapeHtml(book.category_name || 'መደበኛ')}</span>
                    <span class="shelf-tag"><i class="bi bi-bookshelf"></i> ${this.escapeHtml(book.shelf_name || 'መደርደሪያ')} (${this.escapeHtml(book.position || 'ቦታ')})</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Metadata Grid -->
          <div class="card card-pad mb-3">
            <h4 style="font-size:0.95rem;font-weight:700;margin-bottom:12px;color:var(--navy);"><i class="bi bi-info-circle"></i> የመጽሐፉ መረጃዎች</h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;font-size:0.85rem;">
              <div><strong class="text-muted">ምድብ፦</strong> ${this.escapeHtml(book.category_name || '-')}</div>
              <div><strong class="text-muted">ክፍል / አዳራሽ፦</strong> ${this.escapeHtml(book.room_name || '-')}</div>
              <div><strong class="text-muted">መደርደሪያ፦</strong> ${this.escapeHtml(book.shelf_name || '-')}</div>
              <div><strong class="text-muted">ረድፍ / ቦታ፦</strong> ${this.escapeHtml(book.position || '-')}</div>
              <div><strong class="text-muted">የህትመት ዘመን፦</strong> ${this.escapeHtml(book.publication_year || '-')} ዓ.ም</div>
              <div><strong class="text-muted">አሳታሚ፦</strong> ${this.escapeHtml(book.publisher || '-')}</div>
            </div>
          </div>

          <!-- Copies List -->
          ${book.copies && book.copies.length > 0 ? `
            <div class="card card-pad mb-3">
              <h4 style="font-size:0.95rem;font-weight:700;margin-bottom:12px;color:var(--navy);"><i class="bi bi-collection"></i> ቅጂዎች (${book.copies.length})</h4>
              <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:0.82rem;">
                  <thead><tr><th>የቅጂ ኮድ</th><th>ሁኔታ</th><th>QR መለያ</th></tr></thead>
                  <tbody>
                    ${book.copies.map(c => `
                      <tr>
                        <td><strong>${this.escapeHtml(c.copy_code)}</strong></td>
                        <td><span class="badge ${c.status === 'available' ? 'badge-success' : 'badge-muted'}">${c.status === 'available' ? 'ይገኛል' : 'ተወስዷል'}</span></td>
                        <td><code>${this.escapeHtml(c.qr_identifier || '-')}</code></td>
                      </tr>
                    `).join('')}
                  </tbody>
                </table>
              </div>
            </div>
          ` : ''}

          <!-- Offline Borrow Action -->
          <div class="card card-pad text-center mb-3">
            <p class="text-muted" style="font-size:.85rem;margin-bottom:10px;">
              ከመስመር ውጭ (Offline) ሆነውም የመዋስ ጥያቄ መመዝገብ ይችላሉ፤ ኔትወርክ ሲገናኝ በራስ-ሰር ይላካል።
            </p>
            <button class="btn btn-navy" onclick="window.offlineEngine.requestOfflineBorrow(${book.id}, '${this.escapeHtml(book.title)}')">
              <i class="bi bi-journal-plus"></i> የመዋስ ጥያቄ በኦፍላይን መዝግብ
            </button>
          </div>
        `;
      }
    }
  }

  async hydrateOfflineSearchPage() {
    const serverContainer = document.getElementById('search-server-container');
    const offlineContainer = document.getElementById('search-offline-container');
    if (!offlineContainer) return;

    if (!navigator.onLine) {
      if (serverContainer) serverContainer.style.display = 'none';
      offlineContainer.style.display = 'block';

      const urlParams = new URLSearchParams(window.location.search);
      const q = (urlParams.get('q') || '').trim().toLowerCase();
      const catId = parseInt(urlParams.get('category') || '0', 10);

      const allBooks = await this.getCachedBooks();
      const filtered = allBooks.filter((b) => {
        let match = true;
        if (catId > 0 && b.category_id !== catId) {
          match = false;
        }
        if (q) {
          const t = (b.title || '').toLowerCase();
          const a = (b.author || '').toLowerCase();
          const pos = (b.position || '').toLowerCase();
          const copyMatches = b.copies && b.copies.some((cp) => (cp.copy_code || '').toLowerCase().includes(q) || (cp.qr_identifier || '').toLowerCase().includes(q));
          if (!t.includes(q) && !a.includes(q) && !pos.includes(q) && !copyMatches) {
            match = false;
          }
        }
        return match;
      });

      const root = window.APP_ROOT || '/library/';
      const fallbackLogo = root + 'uploads/logo.png';

      offlineContainer.innerHTML = `
        <div class="section-title d-flex justify-content-between align-items-center mb-3">
          <span>ጠቅላላ ${filtered.length} ውጤቶች (ከመስመር ውጭ የተገኙ)</span>
          <span class="badge badge-warning"><i class="bi bi-cloud-slash"></i> Offline</span>
        </div>
        ${filtered.length === 0 ? `
          <div class="empty-state">
            <i class="bi bi-emoji-frown"></i>
            <h4>ምንም መጽሐፍ አልተገኘም</h4>
            <p>የተለየ ስም ወይም ምድብ ፈልገው እንደገና ይሞክሩ።</p>
          </div>
        ` : `
          <div class="row g-2 g-md-3">
            ${filtered.map(b => {
              const coverSrc = b.cover_url || fallbackLogo;
              const authorText = b.author && b.author.trim() ? b.author : 'ጸሃፊው አልተገለጸም';
              const avail = b.available_copies !== undefined ? b.available_copies : 1;
              return `
                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                  <a href="./book.php?id=${b.id}" class="book-card card-hover" style="text-decoration:none;display:flex;flex-direction:column;height:100%;">
                    <div class="book-cover" style="aspect-ratio:3/4;width:100%;position:relative;background:#f8fafc;display:flex;align-items:center;justify-content:center;">
                      <img src="${coverSrc}" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='${fallbackLogo}';">
                    </div>
                    <div class="book-body" style="flex:1;display:flex;flex-direction:column;justify-content:space-between;padding:8px;">
                      <div>
                        <div class="book-title" style="font-weight:700;font-size:0.85rem;line-height:1.2;margin-bottom:3px;">${this.escapeHtml(b.title)}</div>
                        <div class="book-author text-muted" style="font-size:0.75rem;margin-bottom:3px;">${this.escapeHtml(authorText)}</div>
                        <div class="text-muted" style="font-size:.7rem;">${this.escapeHtml(b.category_name || '')}</div>
                      </div>
                      <div class="book-meta mt-1" style="display:flex;justify-content:space-between;align-items:center;">
                        <span class="badge ${b.is_borrowable ? 'badge-success' : 'badge-danger'}" style="font-size:.65rem;">
                          ${b.is_borrowable ? 'ይዋሳል' : 'አይዋስም'}
                        </span>
                        <span class="badge badge-gold" style="font-size:.65rem;">${avail} ቅጂ</span>
                      </div>
                    </div>
                  </a>
                </div>
              `;
            }).join('')}
          </div>
        `}
      `;
    }
  }

  async requestOfflineBorrow(bookId, title) {
    const userStr = localStorage.getItem('atsede_offline_user');
    const user = userStr ? JSON.parse(userStr) : null;
    if (!user) {
      if (typeof toast === 'function') {
        toast('ለመዋስ መጀመሪያ አንድ ጊዜ መግባት ያስፈልግዎታል', 'warning', 4000);
      }
      return;
    }

    await this.queueEvent('BORROW_REQUEST', {
      book_id: bookId,
      book_title: title,
      user_id: user.id,
      user_name: user.full_name || user.username,
      type: 'borrow',
    });

    if (typeof toast === 'function') {
      toast(`✓ የ"${title}" የውሰት ጥያቄ በኦፍላይን ተመዝግቧል። መስመር ሲገናኝ በራሱ ይላካል!`, 'success', 5000);
    }
  }

  escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  init() {
    this.openDB().then(() => {
      this.setupNetworkBanner();
      this.updatePendingBadge();
      this.checkAndHydrateCurrentPage();

      if (navigator.onLine) {
        this.refreshCache();
        this.syncQueue();
      }

      // Handle Service Worker Background Sync notification
      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
          if (event.data && event.data.type === 'TRIGGER_BACKGROUND_SYNC') {
            this.syncQueue();
          }
        });
      }
    });
  }
}

// Global instance
window.offlineEngine = new OfflineEngine();
document.addEventListener('DOMContentLoaded', () => {
  window.offlineEngine.init();
});
