<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>ከበይነ-መረብ ውጭ (Offline Mode) — አጸደ ቤተ-መጻሕፍት</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@600;700&family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root {
    --navy: #0B2545;
    --navy-dark: #07172C;
    --gold: #C59B27;
    --gold-light: #F7E7A9;
    --bg: #F8FAFC;
    --card: #FFFFFF;
    --text: #1E293B;
    --muted: #64748B;
    --border: #E2E8F0;
    --success: #16A34A;
    --warning: #D97706;
    --danger: #DC2626;
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body {
    margin: 0;
    padding: 16px;
    padding-bottom: 80px;
    background: var(--bg);
    color: var(--text);
    font-family: 'Noto Sans Ethiopic', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 14.5px;
  }
  .header-card {
    background: linear-gradient(135deg, var(--navy-dark), var(--navy));
    color: #fff;
    border-radius: 16px;
    padding: 18px;
    text-align: center;
    margin-bottom: 16px;
    box-shadow: 0 4px 15px rgba(11,37,69,0.15);
  }
  .crest {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--gold), #9A7B1C);
    color: #fff;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
  }
  h1 { font-family: 'Noto Serif Ethiopic', serif; font-size: 1.2rem; margin: 0 0 4px; }
  p.subtitle { color: rgba(255,255,255,0.8); font-size: 0.82rem; margin: 0 0 12px; }
  .status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.15);
    padding: 4px 10px;
    border-radius: 99px;
    font-size: 0.76rem;
    font-weight: 600;
  }
  
  /* Tabs */
  .offline-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
    background: #E2E8F0;
    padding: 4px;
    border-radius: 12px;
  }
  .offline-tab {
    flex: 1;
    text-align: center;
    padding: 9px 8px;
    border-radius: 9px;
    font-size: 0.84rem;
    font-weight: 700;
    cursor: pointer;
    border: none;
    background: none;
    color: var(--muted);
    transition: all 0.2s;
  }
  .offline-tab.active {
    background: #fff;
    color: var(--navy);
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
  }

  /* Scanner View */
  .scanner-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    text-align: center;
    margin-bottom: 16px;
  }
  #scanner-box {
    position: relative;
    width: 100%;
    max-width: 290px;
    margin: 0 auto 12px;
    background: #000;
    border-radius: 14px;
    overflow: hidden;
    aspect-ratio: 1/1;
    display: none;
    box-shadow: 0 4px 14px rgba(0,0,0,0.2);
  }
  #offline-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .scan-reticle {
    position: absolute;
    inset: 20px;
    border: 2px dashed var(--gold);
    border-radius: 10px;
    pointer-events: none;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 10px;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    border: none;
    text-decoration: none;
    font-family: inherit;
    transition: all 0.15s;
  }
  .btn-gold { background: var(--gold); color: #fff; }
  .btn-gold:active { background: #b0891f; }
  .btn-navy { background: var(--navy); color: #fff; }
  .btn-outline { background: transparent; border: 1.5px solid var(--border); color: var(--text); }
  .btn-block { display: flex; width: 100%; }

  /* Scanned Result Card */
  .result-card {
    background: #fff;
    border: 1.5px solid var(--gold);
    border-radius: 14px;
    padding: 16px;
    margin-top: 14px;
    text-align: left;
    display: none;
    animation: fadeIn 0.2s ease;
  }

  .search-box {
    margin-bottom: 16px;
    position: relative;
  }
  .search-input {
    width: 100%;
    padding: 12px 14px 12px 42px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-size: 0.92rem;
    font-family: inherit;
    background: var(--card);
  }
  .search-input:focus { outline: none; border-color: var(--gold); }
  .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 1.1rem;
  }
  .section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--navy);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .book-list { display: flex; flex-direction: column; gap: 10px; }
  .book-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .badge {
    font-size: 0.74rem;
    padding: 3px 8px;
    border-radius: 6px;
    font-weight: 700;
    white-space: nowrap;
  }
  .badge-success { background: #DCFCE7; color: #15803D; }
  .badge-warning { background: #FEF3C7; color: #B45309; }
  .badge-muted { background: #F1F5F9; color: #64748B; }
  .empty-state { text-align: center; padding: 24px 16px; color: var(--muted); font-size: 0.88rem; }
  
  @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
</style>
<script>
function checkNetworkAndReload() {
  const badge = document.getElementById('netStatus');
  if (badge) badge.innerHTML = '<i class="bi bi-arrow-repeat"></i> ኔትወርክ እየተፈተሸ ነው...';
  
  // Try online or immediate reload
  if (navigator.onLine) {
    window.location.reload();
    return;
  }
  
  fetch('./index.php?ping=' + Date.now(), { method: 'HEAD', cache: 'no-store' })
    .then(() => {
      if (badge) badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ተገናኝቷል! ገጹ እየተጫነ ነው...';
      setTimeout(() => {
        window.location.href = './index.php';
      }, 300);
    })
    .catch(() => {
      setTimeout(() => {
        if (badge) badge.innerHTML = '<i class="bi bi-wifi-off"></i> አሁንም ከመስመር ውጭ ነዎት <i class="bi bi-arrow-clockwise"></i>';
      }, 700);
    });
}
</script>
</head>
<body>

<!-- Top Brand Bar matching Online Shell (Same View Always) -->
<div style="background:var(--navy-dark);padding:14px 16px;border-radius:14px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;color:#fff;box-shadow:0 4px 14px rgba(0,0,0,0.1);">
  <div style="display:flex;align-items:center;gap:10px;">
    <img src="./uploads/logo.png" alt="አርማ" style="width:38px;height:38px;border-radius:50%;border:1.5px solid var(--gold);object-fit:cover;" onerror="this.style.display='none'">
    <div>
      <div style="font-weight:800;font-size:.95rem;color:#fff;font-family:'Noto Serif Ethiopic',serif;">አጸደ ትጉሃን ሰንበት ትምህርት ቤት</div>
      <div style="font-size:.78rem;color:var(--gold-light);font-weight:600;">ቤተ ይትባረክ ቤተ-መጻሕፍት</div>
    </div>
  </div>
  <a href="javascript:void(0)" onclick="checkNetworkAndReload()" id="netStatus" style="cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;background:rgba(217,119,6,0.2);color:#fbbf24;border:1px solid #f59e0b;padding:5px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;">
    <i class="bi bi-wifi-off"></i> ከመስመር ውጭ ነዎት <i class="bi bi-arrow-clockwise"></i>
  </a>
</div>

<!-- Tabs: Scanner vs Catalog vs 3D Shelf -->
<div class="offline-tabs">
  <button type="button" class="offline-tab active" id="tab-btn-scan" onclick="switchTab('scan')">
    <i class="bi bi-qr-code-scan"></i> ካሜራ ስካነር
  </button>
  <button type="button" class="offline-tab" id="tab-btn-catalog" onclick="switchTab('catalog')">
    <i class="bi bi-collection"></i> መጻሕፍት (<span id="tabCount">0</span>)
  </button>
  <a href="shelf_3d.php" class="offline-tab" style="text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;background:linear-gradient(135deg, #0284C7, #0369A1);color:#fff;">
    <i class="bi bi-box"></i> 3D መደርደሪያ
  </a>
</div>

<!-- TAB 1: SCANNER VIEW -->
<div id="view-scan">
  <div class="scanner-panel">
    <div style="font-weight:700;color:var(--navy);font-size:.95rem;margin-bottom:6px;">
      <i class="bi bi-camera"></i> የመጽሐፍ ቅጂ ስካን ማድረጊያ
    </div>
    <p class="text-muted" style="font-size:.82rem;margin-bottom:12px;">
      የመጽሐፉን QR ኮድ በካሜራው መሃል ያሳዩ፤ ኢንተርኔት ባይኖርም ወዲያውኑ መረጃው ይገኛል።
    </p>

    <!-- Camera Box -->
    <div id="scanner-box">
      <video id="offline-video" playsinline></video>
      <div class="scan-reticle"></div>
    </div>

    <button type="button" id="start-cam-btn" class="btn btn-gold btn-block mb-2" onclick="startOfflineCamera()">
      <i class="bi bi-camera"></i> ካሜራ ክፈት እና ስካን አድርግ
    </button>
    <button type="button" id="stop-cam-btn" class="btn btn-outline btn-block mb-2" style="display:none;" onclick="stopOfflineCamera()">
      <i class="bi bi-camera-video-off"></i> ካሜራ ዝጋ
    </button>

    <div id="scan-status" class="text-muted" style="font-size:.8rem;min-height:1.2em;"></div>

    <!-- Manual Code Fallback -->
    <div style="display:flex;gap:6px;margin-top:12px;">
      <input type="text" id="manualCodeInput" class="search-input" style="padding:8px 12px;" placeholder="የቅጂውን ኮድ በእጅ ያስገቡ (ምሳሌ 01A)...">
      <button type="button" class="btn btn-navy" onclick="lookupManualCode()" style="padding:8px 14px;">ፈልግ</button>
    </div>

    <!-- Scanned Book Details Result -->
    <div id="scannedResultCard" class="result-card">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span id="resCopyTag" class="badge badge-warning" style="font-size:.78rem;"></span>
        <span id="resStatusTag" class="badge"></span>
      </div>
      <h3 id="resTitle" style="font-size:1.05rem;color:var(--navy);margin:4px 0 2px;font-weight:700;"></h3>
      <div id="resAuthor" class="text-muted" style="font-size:.82rem;margin-bottom:6px;"></div>
      
      <div style="background:var(--bg);padding:8px;border-radius:8px;font-size:.8rem;line-height:1.6;margin-bottom:12px;">
        <div><strong>የመደርደሪያ ስም፦</strong> <span id="resShelf">—</span></div>
      </div>

      <!-- Offline Action Buttons -->
      <div id="resActionButtons" style="display:flex;flex-direction:column;gap:8px;"></div>
    </div>
  </div>
</div>

<!-- TAB 2: CATALOG VIEW -->
<div id="view-catalog" style="display:none;">
  <div class="search-box">
    <i class="bi bi-search search-icon"></i>
    <input type="text" class="search-input" id="offlineSearch" placeholder="በስልክዎ የተቀመጡ መጻሕፍትን ፈልጉ..." oninput="filterBooks()">
  </div>

  <div class="section-title">
    <i class="bi bi-archive"></i> በስልክዎ ላይ የተቀመጡ መጻሕፍት (<span id="bookCount">0</span>)
  </div>

  <div class="book-list" id="booksContainer">
    <div class="empty-state">
      <i class="bi bi-hourglass-split" style="font-size:1.8rem;display:block;margin-bottom:6px;"></i>
      መጻሕፍት በመጫን ላይ ናቸው...
    </div>
  </div>
</div>

<script src="./assets/js/offline-engine.js"></script>
<script src="./assets/js/jsQR.min.js"></script>
<script>
let cachedBooks = [];
let cachedMembers = [];
let currentUser = null;
let currentScannedCopy = null;
let currentScannedBook = null;

let videoStream = null;
let scanInterval = null;
let scanCanvas = null;
let scanContext = null;

function switchTab(tab) {
  const scanView = document.getElementById('view-scan');
  const catalogView = document.getElementById('view-catalog');
  const btnScan = document.getElementById('tab-btn-scan');
  const btnCat = document.getElementById('tab-btn-catalog');

  if (tab === 'scan') {
    scanView.style.display = 'block';
    catalogView.style.display = 'none';
    btnScan.classList.add('active');
    btnCat.classList.remove('active');
  } else {
    stopOfflineCamera();
    scanView.style.display = 'none';
    catalogView.style.display = 'block';
    btnCat.classList.add('active');
    btnScan.classList.remove('active');
  }
}

async function initOffline() {
  if (!window.offlineEngine) {
    window.offlineEngine = new OfflineEngine();
  }
  await window.offlineEngine.openDB();
  
  // Load cached books
  cachedBooks = await window.offlineEngine.getCachedBooks();
  document.getElementById('tabCount').textContent = cachedBooks.length;
  renderBooks(cachedBooks);

  // Load cached members
  cachedMembers = await window.offlineEngine.getCachedMembers();

  // Load user info from meta
  const metaUser = await window.offlineEngine.getAll('meta');
  const userSetting = metaUser.find(m => m.key === 'user');
  if (userSetting && userSetting.value) {
    currentUser = userSetting.value;
  }

  // Check URL query for direct code scan
  const urlParams = new URLSearchParams(window.location.search);
  const codeParam = urlParams.get('code');
  if (codeParam) {
    lookupCode(codeParam);
  }
}

// Camera Scanner Logic
async function startOfflineCamera() {
  const box = document.getElementById('scanner-box');
  const video = document.getElementById('offline-video');
  const startBtn = document.getElementById('start-cam-btn');
  const stopBtn = document.getElementById('stop-cam-btn');
  const status = document.getElementById('scan-status');

  try {
    status.textContent = 'ካሜራ እየተከፈተ ነው...';
    videoStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 640 } }
    });
    video.srcObject = videoStream;
    await video.play();

    box.style.display = 'block';
    startBtn.style.display = 'none';
    stopBtn.style.display = 'block';
    status.textContent = 'የመጽሐፉን QR ኮድ በካሜራው መሃል ያሳዩ...';

    // 1. Try Native BarcodeDetector first
    if ('BarcodeDetector' in window) {
      try {
        const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
        scanInterval = setInterval(async () => {
          try {
            const barcodes = await barcodeDetector.detect(video);
            if (barcodes.length > 0) {
              handleOfflineScan(barcodes[0].rawValue);
            }
          } catch (e) {}
        }, 300);
        return;
      } catch (e) {}
    }

    // 2. jsQR Fallback
    if (typeof jsQR !== 'undefined') {
      scanCanvas = document.createElement('canvas');
      scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
      scanInterval = setInterval(() => {
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
          scanCanvas.width = video.videoWidth;
          scanCanvas.height = video.videoHeight;
          scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
          const imageData = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
          const qr = jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: 'dontInvert'
          });
          if (qr && qr.data) {
            handleOfflineScan(qr.data);
          }
        }
      }, 250);
    } else {
      status.innerHTML = '<span class="text-warning">ስካነር አልተገኘም፤ እባክዎ ኮዱን በእጅ ያስገቡ።</span>';
    }
  } catch (err) {
    status.innerHTML = '<span class="text-danger">ካሜራ መክፈት አልተቻለም። እባክዎ ፍቃድ ይስጡ።</span>';
  }
}

function stopOfflineCamera() {
  if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
  if (videoStream) {
    videoStream.getTracks().forEach(t => t.stop());
    videoStream = null;
  }
  const box = document.getElementById('scanner-box');
  const startBtn = document.getElementById('start-cam-btn');
  const stopBtn = document.getElementById('stop-cam-btn');
  const status = document.getElementById('scan-status');

  if (box) box.style.display = 'none';
  if (startBtn) startBtn.style.display = 'block';
  if (stopBtn) stopBtn.style.display = 'none';
  if (status) status.textContent = '';
}

function handleOfflineScan(raw) {
  stopOfflineCamera();
  let code = raw.trim();
  try {
    if (code.includes('code=')) {
      const url = new URL(code.startsWith('http') ? code : 'http://x/' + code);
      if (url.searchParams.has('code')) code = url.searchParams.get('code');
    }
  } catch (e) {}
  lookupCode(code);
}

function lookupManualCode() {
  const code = document.getElementById('manualCodeInput').value.trim();
  if (!code) {
    alert('እባክዎ የቅጂውን ኮድ ያስገቡ።');
    return;
  }
  lookupCode(code);
}

function lookupCode(code) {
  const status = document.getElementById('scan-status');
  status.textContent = 'በስልክዎ ካታሎግ ውስጥ እየተፈለገ ነው...';

  let foundBook = null;
  let foundCopy = null;

  for (const b of cachedBooks) {
    if (b.copies && Array.isArray(b.copies)) {
      for (const cp of b.copies) {
        if (cp.qr_identifier === code || cp.copy_code.toLowerCase() === code.toLowerCase()) {
          foundBook = b;
          foundCopy = cp;
          break;
        }
      }
    }
    if (foundBook) break;
  }

  if (!foundBook) {
    status.innerHTML = `<span style="color:var(--danger);">«${escapeHtml(code)}» የተባለ ቅጂ በስልክዎ ካሽ ውስጥ አልተገኘም።</span>`;
    document.getElementById('scannedResultCard').style.display = 'none';
    return;
  }

  status.innerHTML = `<span style="color:var(--success);">✓ ቅጂው ተገኝቷል!</span>`;
  displayScannedResult(foundBook, foundCopy);
}

function displayScannedResult(book, copy) {
  currentScannedBook = book;
  currentScannedCopy = copy;

  const card = document.getElementById('scannedResultCard');
  document.getElementById('resCopyTag').textContent = 'ID: ' + copy.copy_code;
  document.getElementById('resTitle').textContent = book.title;
  document.getElementById('resAuthor').textContent = 'በ ' + (book.author || 'ያልታወቀ');
  document.getElementById('resRoom').textContent = book.room_name || 'ዋና አዳራሽ';
  document.getElementById('resShelf').textContent = book.shelf_name || 'መደበኛ መደርደሪያ';

  const statusTag = document.getElementById('resStatusTag');
  const isAvailable = (copy.status === 'available');

  if (isAvailable) {
    statusTag.className = 'badge badge-success';
    statusTag.textContent = 'ይገኛል (ነፃ)';
  } else {
    statusTag.className = 'badge badge-warning';
    statusTag.textContent = 'በውሰት ላይ ያለ';
  }

  // Build Action Buttons
  const actBox = document.getElementById('resActionButtons');
  let btns = '';

  if (isAvailable) {
    btns += `
      <button type="button" class="btn btn-gold btn-block" onclick="executeOfflineBorrow()">
        <i class="bi bi-box-arrow-right"></i> ከመስመር ውጭ አበድር / ውሰድ (Offline Borrow)
      </button>
    `;
  } else {
    btns += `
      <button type="button" class="btn btn-navy btn-block" onclick="executeOfflineReturn()">
        <i class="bi bi-arrow-return-left"></i> ከመስመር ውጭ ተቀበል (Offline Return)
      </button>
    `;
  }

  actBox.innerHTML = btns;
  card.style.display = 'block';
}

async function executeOfflineBorrow() {
  if (!currentScannedBook || !currentScannedCopy) return;

  let memberId = 1; // Default
  if (cachedMembers && cachedMembers.length > 0) {
    const memNames = cachedMembers.slice(0, 5).map(m => `${m.member_id}: ${m.full_name}`).join('\n');
    const promptAns = prompt(`መጽሐፉን የሚወስደው አባል መታወቂያ (ID) ያስገቡ፦\n\nከአባላት የተወሰዱ፦\n${memNames}`, '1');
    if (!promptAns) return;
    memberId = parseInt(promptAns) || 1;
  }

  // Queue event in OfflineEngine
  await window.offlineEngine.queueEvent('BORROW', {
    book_id: currentScannedBook.id,
    copy_id: currentScannedCopy.id || 1,
    copy_code: currentScannedCopy.copy_code,
    member_id: memberId,
    timestamp: new Date().toISOString()
  });

  // Update local state in cached book
  currentScannedCopy.status = 'borrowed';
  await window.offlineEngine.put('books', currentScannedBook);

  alert(`✓ ቅጂ [${currentScannedCopy.copy_code}] በኦፍላይን ውሰት ተመዝግቧል!\nኔትዎርክ ሲመለስ በራስ-ሰር ወደ ሰርቨር ይላካል።`);
  displayScannedResult(currentScannedBook, currentScannedCopy);
}

async function executeOfflineReturn() {
  if (!currentScannedBook || !currentScannedCopy) return;

  if (!confirm(`«${currentScannedBook.title}» (ቅጂ ${currentScannedCopy.copy_code}) መመለሱን በኦፍላይን መመዝገብ ይፈልጋሉ?`)) {
    return;
  }

  await window.offlineEngine.queueEvent('RETURN', {
    book_id: currentScannedBook.id,
    copy_id: currentScannedCopy.id || 1,
    copy_code: currentScannedCopy.copy_code,
    timestamp: new Date().toISOString()
  });

  // Update local state in cached book
  currentScannedCopy.status = 'available';
  await window.offlineEngine.put('books', currentScannedBook);

  alert(`✓ ቅጂ [${currentScannedCopy.copy_code}] መመለሱ በኦፍላይን ተመዝግቧል!\nኔትዎርክ ሲመለስ በራስ-ሰር ወደ ሰርቨር ይላካል።`);
  displayScannedResult(currentScannedBook, currentScannedCopy);
}

function renderBooks(list) {
  const container = document.getElementById('booksContainer');
  document.getElementById('bookCount').textContent = list.length;
  
  if (!list || list.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <i class="bi bi-journal-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
        በስልክዎ ላይ የተቀመጠ መጽሐፍ አልተገኘም። አንዴ በመስመር ላይ ሆነው ገጹን ሲከፍቱ በራስ-ሰር ይቀመጣል።
      </div>
    `;
    return;
  }

  container.innerHTML = list.map(b => `
    <div class="book-card" onclick="selectBookForScan(${b.id})" style="cursor:pointer;">
      <div class="book-info">
        <div style="font-weight:700;font-size:.92rem;color:var(--navy);">${escapeHtml(b.title || '')}</div>
        <div style="font-size:.78rem;color:var(--muted);"><i class="bi bi-person"></i> ${escapeHtml(b.author || 'ያልታወቀ')} · <i class="bi bi-geo-alt"></i> ${escapeHtml(b.shelf_name || 'መደርደሪያ')}</div>
      </div>
      <div>
        <span class="badge ${b.is_borrowable == 1 ? 'badge-success' : 'badge-warning'}">
          ${b.is_borrowable == 1 ? 'ይገኛል' : 'የማጣቀሻ'}
        </span>
      </div>
    </div>
  `).join('');
}

function selectBookForScan(bookId) {
  const b = cachedBooks.find(item => item.id == bookId);
  if (b && b.copies && b.copies.length > 0) {
    switchTab('scan');
    displayScannedResult(b, b.copies[0]);
  } else if (b) {
    switchTab('scan');
    displayScannedResult(b, { copy_code: '01A', status: 'available' });
  }
}

function filterBooks() {
  const query = document.getElementById('offlineSearch').value.toLowerCase().trim();
  if (!query) {
    renderBooks(cachedBooks);
    return;
  }
  const filtered = cachedBooks.filter(b => 
    (b.title && b.title.toLowerCase().includes(query)) ||
    (b.author && b.author.toLowerCase().includes(query))
  );
  renderBooks(filtered);
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, m => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[m]);
}

function checkNetworkAndReload() {
  const badge = document.getElementById('netStatus');
  if (badge) badge.innerHTML = '<i class="bi bi-arrow-repeat"></i> ኔትወርክ እየተፈተሸ ነው...';
  
  fetch('./index.php?ping=' + Date.now(), { method: 'HEAD', cache: 'no-store' })
    .then(() => {
      if (badge) badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ተገናኝቷል! ገጹ እየተጫነ ነው...';
      setTimeout(() => {
        window.location.href = './index.php';
      }, 400);
    })
    .catch(() => {
      setTimeout(() => {
        if (badge) badge.innerHTML = '<i class="bi bi-wifi-off"></i> አሁንም ከመስመር ውጭ ነዎት <i class="bi bi-arrow-clockwise"></i>';
      }, 700);
    });
}

window.addEventListener('online', () => {
  const badge = document.getElementById('netStatus');
  if (badge) badge.innerHTML = '<i class="bi bi-wifi"></i> ግንኙነት ተመልሷል! መረጃዎች እየተመሳሰሉ ነው...';
  if (window.offlineEngine) {
    window.offlineEngine.syncQueue();
  }
  setTimeout(() => location.href = './index.php', 1200);
});

document.addEventListener('DOMContentLoaded', initOffline);
</script>

<!-- Bottom Navigation Matching Online Shell (Same View Always) -->
<nav style="position:fixed;bottom:0;left:0;right:0;height:62px;background:#fff;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-around;z-index:1000;box-shadow:0 -2px 10px rgba(0,0,0,0.05);">
  <a href="./index.php" style="display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--navy);font-size:.74rem;font-weight:700;">
    <i class="bi bi-house" style="font-size:1.25rem;"></i> መነሻ
  </a>
  <a href="./scan.php" style="display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--gold);font-size:.74rem;font-weight:700;">
    <i class="bi bi-qr-code-scan" style="font-size:1.25rem;"></i> ስካን
  </a>
  <a href="./shelf_3d.php" style="display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--muted);font-size:.74rem;font-weight:700;">
    <i class="bi bi-box" style="font-size:1.25rem;"></i> 3D መደርደሪያ
  </a>
  <a href="javascript:void(0)" onclick="switchTab('catalog')" style="display:flex;flex-direction:column;align-items:center;text-decoration:none;color:var(--muted);font-size:.74rem;font-weight:700;">
    <i class="bi bi-collection" style="font-size:1.25rem;"></i> ካታሎግ
  </a>
</nav>

</body>
</html>
