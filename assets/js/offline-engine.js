/* =========================================================
   ATSEDE LIBRARY — offline-engine.js
   True Offline Support with IndexedDB + UUID Sync Queue
   ========================================================= */

class OfflineEngine {
  constructor() {
    this.dbName = 'atsede_offline_db';
    this.dbVersion = 2;
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

  async getCachedMembers() {
    return this.getAll('members');
  }

  async getPendingSyncCount() {
    const all = await this.getAll('sync_queue');
    return all.filter((e) => e.status === 'pending').length;
  }

  // Seed cache from bootstrap endpoint when online
  async refreshCache() {
    if (!navigator.onLine) return;
    try {
      const res = await fetch((window.APP_BASE || './') + 'ajax/offline_bootstrap.php');
      if (!res.ok) return;
      const data = await res.json();
      if (!data.success) return;

      const db = await this.openDB();
      const tx = db.transaction(['books', 'members', 'meta'], 'readwrite');

      if (data.books && Array.isArray(data.books)) {
        const bStore = tx.objectStore('books');
        data.books.forEach((b) => bStore.put(b));
      }

      if (data.members && Array.isArray(data.members)) {
        const mStore = tx.objectStore('members');
        data.members.forEach((m) => mStore.put(m));
      }

      const metaStore = tx.objectStore('meta');
      metaStore.put({ key: 'last_synced_at', value: new Date().toISOString() });
      if (data.settings) {
        metaStore.put({ key: 'settings', value: data.settings });
      }

      tx.oncomplete = () => {
        console.log('Offline cache successfully primed.');
      };
    } catch (err) {
      console.warn('Could not refresh offline cache:', err);
    }
  }

  // Queue an offline event (PAYMENT, BORROW, RETURN)
  async queueEvent(operationType, payload) {
    const uuid = this.generateUUID();
    const event = {
      event_uuid: uuid,
      operation_type: operationType,
      payload: payload,
      device_id: this.deviceId,
      timestamp: new Date().toISOString(),
      status: 'pending'
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
      const res = await fetch((window.APP_BASE || './') + 'ajax/offline_sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          device_id: this.deviceId,
          events: pending
        })
      });

      if (res.status === 401) {
        if (typeof toast === 'function') {
          toast('ክፍለ ጊዜዎ አልቋል፤ እንደገና ይግቡ', 'danger', 5000);
        }
        return;
      }

      if (!res.ok) return;
      const data = await res.json();
      if (data.success && Array.isArray(data.results)) {
        for (const r of data.results) {
          if (r.status === 'synced') {
            await this.delete('sync_queue', r.event_uuid);
          } else if (r.status === 'rejected') {
            // Delete permanently rejected events from queue and persist to failed_events
            await this.delete('sync_queue', r.event_uuid);
            try {
              await this.put('failed_events', {
                event_uuid: r.event_uuid,
                rejected_at: new Date().toISOString(),
                reason: r.message || 'ክስተቱ ውድቅ ተደርጓል'
              });
            } catch (err) {}
            if (typeof toast === 'function') {
              toast(`⚠️ ውድቅ የተደረገ፦ ${r.message || ''}`, 'warning', 5000);
            }
          }
        }
        if (typeof toast === 'function' && data.synced_count > 0) {
          toast(`✓ ${data.synced_count} የኦፍላይን ተግባራት በተሳካ ሁኔታ ተመሳስለዋል።`, 'success', 4000);
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
        badge.style.cssText = 'position:fixed;bottom:75px;right:16px;z-index:999;background:#D4AF37;color:#0F172A;padding:6px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;box-shadow:0 4px 12px rgba(0,0,0,0.25);cursor:pointer;';
        badge.onclick = () => this.syncQueue();
        document.body.appendChild(badge);
      }
      badge.innerHTML = `<i class="bi bi-arrow-repeat"></i> ${count} ያልተመሳሰለ`;
      badge.style.display = 'block';
    } else if (badge) {
      badge.style.display = 'none';
    }
  }

  // Network Banner
  setupNetworkBanner() {
    let banner = document.getElementById('offline-network-banner');
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'offline-network-banner';
      banner.style.cssText = 'display:none;position:sticky;top:0;z-index:1100;padding:8px 12px;text-align:center;font-size:0.8rem;font-weight:600;transition:all 0.3s;cursor:pointer;';
      document.body.prepend(banner);
    }

    const updateStatus = () => {
      if (navigator.onLine) {
        banner.style.background = '#10B981';
        banner.style.color = '#ffffff';
        banner.innerHTML = '<i class="bi bi-wifi"></i> መስመር ላይ ተመልሰዋል (Online) — መረጃዎች እየተመሳሰሉ ነው...';
        banner.style.display = 'block';
        banner.onclick = null;
        this.syncQueue();
        setTimeout(() => {
          banner.style.display = 'none';
        }, 3000);
      } else {
        banner.style.background = '#F59E0B';
        banner.style.color = '#0F172A';
        banner.innerHTML = '<i class="bi bi-wifi-off"></i> <strong>ከመስመር ውጭ ነዎት</strong> — እንደገና ለመሞከር እዚህ ይጫኑ <i class="bi bi-arrow-clockwise"></i>';
        banner.style.display = 'block';
        banner.onclick = () => {
          banner.innerHTML = '<i class="bi bi-arrow-repeat"></i> ኔትወርክ እየተፈተሸ ነው...';
          setTimeout(() => {
            if (navigator.onLine) {
              window.location.reload();
            } else {
              banner.innerHTML = '<i class="bi bi-wifi-off"></i> አሁንም ከመስመር ውጭ ነዎት — እንደገና ለመሞከር እዚህ ይጫኑ <i class="bi bi-arrow-clockwise"></i>';
            }
          }, 600);
        };
      }
    };

    window.addEventListener('online', updateStatus);
    window.addEventListener('offline', updateStatus);

    if (!navigator.onLine) {
      updateStatus();
    }
  }

  init() {
    this.openDB().then(() => {
      this.setupNetworkBanner();
      this.updatePendingBadge();
      if (navigator.onLine) {
        this.refreshCache();
        this.syncQueue();
      }
    });
  }
}

// Global instance
window.offlineEngine = new OfflineEngine();
document.addEventListener('DOMContentLoaded', () => {
  window.offlineEngine.init();
});
