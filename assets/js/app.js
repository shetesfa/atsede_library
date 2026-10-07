/* =========================================================
   ATSEDE LIBRARY — app.js
   Toasts, bottom-sheet modals, theme toggle, PWA install,
   push notification subscription.
   ========================================================= */

// ---------- Toasts ----------
function toast(message, type = 'info', duration = 3200) {
  let stack = document.getElementById('toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'toast-stack';
    document.body.appendChild(stack);
  }
  const el = document.createElement('div');
  el.className = `toast-msg ${type}`;
  const icons = { success: 'bi-check-circle', danger: 'bi-x-circle', warning: 'bi-exclamation-triangle', info: 'bi-info-circle' };
  
  const icon = document.createElement('i');
  icon.className = `bi ${icons[type] || icons.info}`;
  
  const span = document.createElement('span');
  span.textContent = message; // Safe against XSS
  
  el.appendChild(icon);
  el.appendChild(span);
  stack.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; el.style.transition = '.25s'; setTimeout(() => el.remove(), 250); }, duration);
}

// ---------- Bottom sheets / modals ----------
function openSheet(id) {
  const o = document.getElementById(id);
  if (o) { o.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeSheet(id) {
  const o = document.getElementById(id);
  if (o) { o.classList.remove('show'); document.body.style.overflow = ''; }
}
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('sheet-overlay')) {
    e.target.classList.remove('show');
    document.body.style.overflow = '';
  }
});

// ---------- Theme (light/dark) ----------
(function () {
  const saved = localStorage.getItem('atsede_theme');
  if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
})();
function toggleTheme() {
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  if (isDark) {
    document.documentElement.removeAttribute('data-theme');
    localStorage.setItem('atsede_theme', 'light');
  } else {
    document.documentElement.setAttribute('data-theme', 'dark');
    localStorage.setItem('atsede_theme', 'dark');
  }
}

// ---------- Service worker + PWA install ----------
let deferredInstallPrompt = null;

function isStandaloneApp() {
  return window.matchMedia('(display-mode: standalone)').matches
    || window.matchMedia('(display-mode: fullscreen)').matches
    || window.navigator.standalone === true;
}

function isIosDevice() {
  return /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isMobileDevice() {
  return window.matchMedia('(max-width: 768px)').matches || isIosDevice();
}

function canShowInstallUi() {
  return !isStandaloneApp() && (deferredInstallPrompt || isIosDevice() || isMobileDevice());
}

function showInstallUi() {
  return;
}

function hideInstallUi() {
  ['install-banner', 'pwa-install-sidebar', 'pwa-install-more', 'pwa-install-bottom', 'home-install-card', 'pwa-install-btn'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
}

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    const swUrl = window.APP_ROOT + 'sw.js?v=16';
    navigator.serviceWorker.register(swUrl, { scope: window.APP_ROOT }).then((reg) => {
      reg.update();
    }).catch(() => {});
  });
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;
  showInstallUi();
});

window.addEventListener('appinstalled', () => {
  deferredInstallPrompt = null;
  hideInstallUi();
});

document.addEventListener('DOMContentLoaded', () => {
  if (isStandaloneApp()) {
    hideInstallUi();
    ['profile-install-btn', 'pwa-install-btn', 'pwa-install-sidebar', 'pwa-install-more', 'pwa-install-bottom', 'home-install-card'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
  } else if (isIosDevice() || isMobileDevice()) {
    showInstallUi();
  }
  if (window.VAPID_PUBLIC_KEY || document.getElementById('notif-dot')) {
    setTimeout(autoEnableNotifications, 800);
  }
  localStorage.setItem('inapp_alerts_enabled', '1');
});

function installApp() {
  const banner = document.getElementById('install-banner');

  if (deferredInstallPrompt) {
    deferredInstallPrompt.prompt();
    deferredInstallPrompt.userChoice.then((choiceResult) => {
      if (choiceResult.outcome === 'accepted') {
        hideInstallUi();
        deferredInstallPrompt = null;
      }
    });
    return;
  }

  if (banner) banner.style.display = 'none';
  const iosPanel = document.getElementById('install-help-ios');
  const androidPanel = document.getElementById('install-help-android');
  if (iosPanel && androidPanel) {
    const onIos = isIosDevice();
    iosPanel.style.display = onIos ? 'block' : 'none';
    androidPanel.style.display = onIos ? 'none' : 'block';
  }
  openSheet('install-help-sheet');
}

function dismissInstallBanner() {
  const banner = document.getElementById('install-banner');
  if (banner) banner.style.display = 'none';
  sessionStorage.setItem('install_dismissed', '1');
}

// ---------- Push notifications (force-on for members) ----------
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
}

async function enablePushNotifications(vapidPublicKey) {
  const key = vapidPublicKey || window.VAPID_PUBLIC_KEY || '';
  if (!key) return;
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    localStorage.setItem('inapp_alerts_enabled', '1');
    return;
  }
  try {
    if (Notification.permission === 'default') {
      await Notification.requestPermission();
    }
    if (Notification.permission !== 'granted') {
      localStorage.setItem('inapp_alerts_enabled', '1');
      return;
    }
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(key),
      });
    }
    await fetch(window.APP_BASE + 'ajax/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub),
    });
    localStorage.setItem('push_auto_enabled', '1');
  } catch (err) {
    console.error('Push subscription failed:', err);
    localStorage.setItem('inapp_alerts_enabled', '1');
  }
}

function autoEnableNotifications() {
  if (!window.VAPID_PUBLIC_KEY) {
    localStorage.setItem('inapp_alerts_enabled', '1');
    return;
  }
  enablePushNotifications(window.VAPID_PUBLIC_KEY);
}

// ---------- Notification bell: poll unread count + trigger in-app toasts ----------
let lastSeenNotifId = parseInt(localStorage.getItem('last_seen_notif_id') || '0', 10);
const inAppAlertsEnabled = localStorage.getItem('inapp_alerts_enabled') === '1';

function pollNotifications() {
  const dot = document.getElementById('notif-dot');
  if (!dot) return;
  fetch(window.APP_BASE + 'ajax/latest_notifications.php')
    .then((r) => r.json())
    .then((d) => {
      if (d.notifications && d.notifications.length > 0) {
        const maxId = Math.max(...d.notifications.map(n => n.id));
        
        dot.style.display = 'flex';
        dot.textContent = d.notifications.length > 9 ? '9+' : d.notifications.length;
        
        // Show toasts for new notifications if:
        // - user has opted in to in-app alerts, OR
        // - they are on mobile where push isn't available
        const isMobile = window.matchMedia('(max-width: 768px)').matches;
        const isHttp = location.protocol !== 'https:' && location.hostname !== 'localhost';
        const shouldToast = inAppAlertsEnabled || (isMobile && isHttp);
        
        if (shouldToast && lastSeenNotifId > 0) {
          d.notifications.forEach((n) => {
            if (n.id > lastSeenNotifId) {
              toast(`🔔 ${n.title}: ${n.message}`, 'info', 7000);
            }
          });
        } else if (lastSeenNotifId > 0) {
          // Even on desktop — show subtle toasts for truly new items
          d.notifications.forEach((n) => {
            if (n.id > lastSeenNotifId) {
              toast(`${n.title}: ${n.message}`, 'info', 5000);
            }
          });
        }
        
        lastSeenNotifId = maxId;
        localStorage.setItem('last_seen_notif_id', maxId.toString());
      } else {
        dot.style.display = 'none';
      }
    })
    .catch(() => {});
}

if (document.getElementById('notif-dot')) {
  pollNotifications();
  setInterval(pollNotifications, 15000); // Every 15 seconds
}

// ---------- Generic confirm-action helper ----------
function confirmAction(message, formEl) {
  if (window.confirm(message)) formEl.submit();
  return false;
}

// ---------- Search debounce helper ----------
function debounce(fn, wait = 350) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}
