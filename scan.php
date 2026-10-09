<?php
/**
 * scan.php — Universal Self-Scan & Librarian Book Scanner
 * Fully Offline-Capacitated (Works identically online & offline)
 * Role-Aware: Normal users see book details; Librarians can Borrow & Return.
 * Supports Modern Live Video Camera + Native Photo Capture for Old Phones.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$isLibrarian = ($role === 'librarian' || $role === 'admin');

$pageTitle = 'መጽሐፍ በQR ኮድ ስካን ማድረጊያ';
$activeKey = 'scan';
include __DIR__ . '/includes/header.php';
?>

<div class="section-title" style="margin-top:0;">
  <i class="bi bi-qr-code-scan text-gold"></i> መጽሐፍ ስካን ማድረጊያ (<?= $isLibrarian ? 'የላይብረሪያን ውሰትና መመለስ' : 'የመጽሐፍ መረጃ' ?>)
</div>

<!-- Main Scanner Card -->
<div class="card card-pad mb-3 text-center" style="box-shadow:0 4px 15px rgba(0,0,0,0.05);border-radius:14px;">
  <p class="text-muted" style="font-size:.85rem;margin-bottom:14px;">
    የመጽሐፉን QR ኮድ በካሜራው ስካን ያድርጉ።
  </p>

  <!-- Live Camera Video Box -->
  <div id="scanner-container" style="position:relative;width:100%;max-width:300px;margin:0 auto 12px;background:#000;border-radius:14px;overflow:hidden;aspect-ratio:1/1;display:none;box-shadow:0 8px 24px rgba(0,0,0,0.25);">
    <video id="qr-video" playsinline style="width:100%;height:100%;object-fit:cover;"></video>
    <div style="position:absolute;inset:20px;border:2.5px dashed var(--gold);border-radius:12px;pointer-events:none;"></div>
  </div>

  <!-- Camera Action Buttons -->
  <div style="display:flex;flex-direction:column;gap:8px;max-width:320px;margin:0 auto 12px;">
    <button type="button" id="start-cam-btn" class="btn btn-gold btn-block" onclick="startCamera()">
      <i class="bi bi-camera-video"></i> የቀጥታ ካሜራ ስካነር ክፈት
    </button>
    <button type="button" id="stop-cam-btn" class="btn btn-outline btn-block" style="display:none;" onclick="stopCamera()">
      <i class="bi bi-camera-video-off"></i> ካሜራ ዝጋ
    </button>
    <button type="button" id="torch-btn" class="btn btn-outline btn-block" style="display:none;border-color:var(--gold);color:var(--gold);" onclick="toggleTorch()">
      <i class="bi bi-lightbulb"></i> ፍላሽ አብራ/አጥፋ
    </button>

    <!-- Fallback for Old Phones: Native Camera Photo Snap -->
    <input type="file" id="cameraFileInput" accept="image/*" capture="environment" style="display:none;" onchange="handleOldPhonePhoto(this)">
    <button type="button" class="btn btn-outline btn-block" style="border-color:var(--navy);color:var(--navy);" onclick="document.getElementById('cameraFileInput').click()">
      <i class="bi bi-camera-fill"></i> ፎቶ አንስተው ስካን ያድርጉ (ለማንኛውም አሮጌ ስልክ)
    </button>
  </div>

  <div id="scan-status" class="text-muted mb-2" style="font-size:.82rem;min-height:1.2em;"></div>

  <!-- Manual Code Input Fallback -->
  <div style="position:relative;margin:16px 0;text-align:center;">
    <hr style="border:0;border-top:1px solid var(--line);">
    <span style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#fff;padding:0 10px;font-size:.78rem;color:var(--muted);">ወይም የቅጂውን ኮድ በእጅ ያስገቡ</span>
  </div>

  <div style="display:flex;gap:6px;max-width:340px;margin:0 auto;">
    <input type="text" id="manualCodeInput" class="input" style="flex:1;" placeholder="ምሳሌ፦ 01A ወይም ATS-COPY-0001">
    <button type="button" class="btn btn-navy" onclick="lookupManualCode()">ፈልግ</button>
  </div>
</div>

<!-- ================= SCANNED BOOK DETAILS CARD ================= -->
<div id="scannedBookCard" class="card card-pad mb-3" style="display:none;border-left:4px solid var(--gold);animation:fadeIn 0.25s ease;">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <span id="cardCopyCode" class="shelf-tag" style="margin:0;font-weight:800;font-size:.86rem;color:var(--navy);"></span>
    <span id="cardStatusBadge" class="badge"></span>
  </div>

  <h2 id="cardTitle" class="font-display" style="font-size:1.2rem;color:var(--navy);margin:0 0 4px;line-height:1.35;"></h2>
  <div id="cardAuthor" class="text-muted" style="font-size:.86rem;margin-bottom:10px;"></div>

  <!-- Location & Specs -->
  <div style="background:var(--slate-50);border:1px solid var(--line);border-radius:10px;padding:10px;font-size:.82rem;line-height:1.7;margin-bottom:14px;">
    <div><i class="bi bi-bookshelf text-gold"></i> <strong>የመደርደሪያ ስም፦</strong> <span id="cardShelf">—</span></div>
    <div><i class="bi bi-pin-map text-danger"></i> <strong>ትክክለኛ ቦታ / ረድፍ፦</strong> <span id="cardPosition">—</span></div>
    <div id="cardCategoryWrap"><i class="bi bi-bookmark text-success"></i> <strong>ምድብ፦</strong> <span id="cardCategory">—</span></div>
  </div>

  <!-- Role-specific Actions Box -->
  <div id="cardActionBox" style="display:flex;flex-direction:column;gap:8px;"></div>
</div>


<!-- Librarian Offline Borrow Modal -->
<div id="librarianBorrowModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.7);z-index:9999;align-items:center;justify-content:center;padding:16px;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);">
  <div style="background:#fff;border-radius:16px;max-width:360px;width:100%;padding:20px;box-shadow:0 10px 30px rgba(0,0,0,0.3);position:relative;">
    <div style="font-weight:800;color:var(--navy);font-size:1rem;margin-bottom:8px;">
      <i class="bi bi-journal-arrow-up text-gold"></i> መጽሐፍ ለአባል ማበደር
    </div>
    <p class="text-muted" style="font-size:.82rem;margin-bottom:12px;" id="borrowModalBookInfo"></p>
    
    <label style="font-size:.8rem;font-weight:700;color:var(--navy);display:block;margin-bottom:4px;">መጽሐፉን የሚወስደው አባል፦</label>
    <div id="memberSelectContainer" style="margin-bottom:14px;">
      <input type="text" id="memberSearchInput" class="input mb-2" placeholder="አባል በስም ወይም በስልክ ይፈልጉ..." oninput="filterModalMembers(this.value)">
      <select id="modalMemberSelect" class="input" style="max-height:120px;" size="4"></select>
    </div>

    <div class="d-flex gap-2">
      <button type="button" class="btn btn-outline btn-block" onclick="closeBorrowModal()"><?= __('cancel') ?></button>
      <button type="button" class="btn btn-gold btn-block" onclick="submitBorrowAction()">አረጋግጥና አበድር</button>
    </div>
  </div>
</div>

<script src="<?= $base ?>assets/js/jsQR.min.js"></script>
<script>
const USER_ROLE = "<?= $role ?>";
const IS_LIBRARIAN = <?= $isLibrarian ? 'true' : 'false' ?>;

let videoStream = null;
let scanInterval = null;
let scanCanvas = null;
let scanContext = null;

let currentBook = null;
let currentCopy = null;
let cachedBooksList = [];
let cachedMembersList = [];

// Initialize IndexedDB and Caches
document.addEventListener('DOMContentLoaded', async () => {
  if (window.offlineEngine) {
    await window.offlineEngine.openDB();
    cachedBooksList = await window.offlineEngine.getCachedBooks();
    cachedMembersList = await window.offlineEngine.getCachedMembers();
  }

  // Check URL query parameter (e.g. scan.php?code=01A)
  const urlParams = new URLSearchParams(window.location.search);
  const codeParam = urlParams.get('code');
  if (codeParam) {
    performLookup(codeParam);
  }
});

// 1. Live Camera Scanner
let isTorchOn = false;
let isProcessingCode = false;

function playScanSuccessFeedback() {
  try {
    if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (AudioCtx) {
      const audioCtx = new AudioCtx();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, audioCtx.currentTime);
      gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.12);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.12);
    }
  } catch (e) {}
}

async function toggleTorch() {
  if (!videoStream) return;
  const track = videoStream.getVideoTracks()[0];
  if (!track || !track.getCapabilities || !track.getCapabilities().torch) {
    alert('መሳሪያዎ የፍላሽ መቆጣጠሪያን አይደግፍም።');
    return;
  }
  try {
    isTorchOn = !isTorchOn;
    await track.applyConstraints({ advanced: [{ torch: isTorchOn }] });
    const torchBtn = document.getElementById('torch-btn');
    if (torchBtn) {
      torchBtn.classList.toggle('btn-gold', isTorchOn);
      torchBtn.classList.toggle('btn-outline', !isTorchOn);
    }
  } catch (e) {
    console.error('Torch error:', e);
  }
}

async function startCamera() {
  const container = document.getElementById('scanner-container');
  const video = document.getElementById('qr-video');
  const startBtn = document.getElementById('start-cam-btn');
  const stopBtn = document.getElementById('stop-cam-btn');
  const torchBtn = document.getElementById('torch-btn');
  const status = document.getElementById('scan-status');

  try {
    status.textContent = 'ካሜራ እየተከፈተ ነው...';
    videoStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 640 } }
    });
    video.srcObject = videoStream;
    await video.play();

    // Check torch capability
    const track = videoStream.getVideoTracks()[0];
    if (track && track.getCapabilities && track.getCapabilities().torch) {
      if (torchBtn) torchBtn.style.display = 'block';
    }

    container.style.display = 'block';
    startBtn.style.display = 'none';
    stopBtn.style.display = 'block';
    status.textContent = 'የመጽሐፉን QR ኮድ በካሜራው መሃል አድርገው ያሳዩ...';

    // 1. Native BarcodeDetector
    if ('BarcodeDetector' in window) {
      try {
        const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
        scanInterval = setInterval(async () => {
          if (isProcessingCode) return;
          try {
            const barcodes = await barcodeDetector.detect(video);
            if (barcodes.length > 0) {
              handleDetectedCode(barcodes[0].rawValue);
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
        if (isProcessingCode) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
          scanCanvas.width = video.videoWidth;
          scanCanvas.height = video.videoHeight;
          scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
          const imageData = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
          const qr = jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: 'dontInvert'
          });
          if (qr && qr.data) {
            handleDetectedCode(qr.data);
          }
        }
      }, 250);
    } else {
      status.innerHTML = '<span class="text-warning">ስካነር አልተገኘም፤ ከታች ፎቶ በማንሳት ይጠቀሙ።</span>';
    }
  } catch (err) {
    status.innerHTML = '<span class="text-danger">የቀጥታ ካሜራ መክፈት አልተቻለም። ከታች «ፎቶ አንስተው ስካን ያድርጉ» የሚለውን ይጫኑ።</span>';
  }
}

function stopCamera() {
  if (scanInterval) { clearInterval(scanInterval); scanInterval = null; }
  if (videoStream) {
    videoStream.getTracks().forEach(track => track.stop());
    videoStream = null;
  }
  const container = document.getElementById('scanner-container');
  const startBtn = document.getElementById('start-cam-btn');
  const stopBtn = document.getElementById('stop-cam-btn');
  const torchBtn = document.getElementById('torch-btn');
  const status = document.getElementById('scan-status');

  if (container) container.style.display = 'none';
  if (startBtn) startBtn.style.display = 'block';
  if (stopBtn) stopBtn.style.display = 'none';
  if (torchBtn) { torchBtn.style.display = 'none'; isTorchOn = false; }
  if (status) status.textContent = '';
  isProcessingCode = false;
}

// 2. Old Phones Fallback: Handle Native Camera Photo
function handleOldPhonePhoto(input) {
  if (!input.files || input.files.length === 0) return;
  const file = input.files[0];
  const status = document.getElementById('scan-status');
  status.textContent = 'ፎቶው እየተመረመረ ነው...';

  const reader = new FileReader();
  reader.onload = function(e) {
    const img = new Image();
    img.onload = function() {
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d');
      canvas.width = img.width;
      canvas.height = img.height;
      ctx.drawImage(img, 0, 0, img.width, img.height);
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      
      if (typeof jsQR !== 'undefined') {
        const qr = jsQR(imageData.data, imageData.width, imageData.height, {
          inversionAttempts: 'attemptBoth'
        });
        if (qr && qr.data) {
          status.innerHTML = '<span class="text-success">✓ QR ኮዱ በፎቶው ላይ ተገኝቷል!</span>';
          handleDetectedCode(qr.data);
        } else {
          status.innerHTML = '<span class="text-danger">በፎቶው ላይ QR ኮድ አልተገኘም። እባክዎ እንደገና በቅርበት ፎቶ ያንሱ።</span>';
        }
      }
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

async function handleDetectedCode(scannedRaw) {
  if (isProcessingCode) return;
  isProcessingCode = true;

  playScanSuccessFeedback();
  stopCamera(); // 1 scan እንዳደረገ ካሜራው ወዲያውኑ ይዘጋል

  let code = scannedRaw.trim();
  try {
    if (code.includes('code=')) {
      const url = new URL(code.startsWith('http') ? code : 'http://x/' + code);
      if (url.searchParams.has('code')) code = url.searchParams.get('code');
    }
  } catch (e) {}

  await performLookup(code);
}

function lookupManualCode() {
  const code = document.getElementById('manualCodeInput').value.trim();
  if (!code) {
    alert('እባክዎ የቅጂውን ኮድ ያስገቡ።');
    return;
  }
  stopCamera();
  performLookup(code);
}

// 3. Unified Fast Lookup (Online & Offline Seamless Fallback)
async function performLookup(code) {
  const status = document.getElementById('scan-status');
  status.textContent = 'መጽሐፉ በመፈለግ ላይ ነው...';

  let foundBook = null;
  let foundCopy = null;

  // First, check local IndexedDB cache (Instant)
  if (cachedBooksList && cachedBooksList.length > 0) {
    // 1. Strict qr_identifier resolution first
    for (const b of cachedBooksList) {
      if (b.copies && Array.isArray(b.copies)) {
        for (const cp of b.copies) {
          if (cp.qr_identifier && cp.qr_identifier.toLowerCase() === code.toLowerCase()) {
            foundBook = b;
            foundCopy = cp;
            break;
          }
        }
      }
      if (foundBook) break;
    }

    // 2. Fallback to copy_code resolution
    if (!foundBook) {
      for (const b of cachedBooksList) {
        if (b.copies && Array.isArray(b.copies)) {
          for (const cp of b.copies) {
            if (cp.copy_code && cp.copy_code.toLowerCase() === code.toLowerCase()) {
              foundBook = b;
              foundCopy = cp;
              break;
            }
          }
        }
        if (foundBook) break;
      }
    }
  }

  // If found locally, immediately navigate for users or render for librarians!
  if (foundBook && foundCopy) {
    status.innerHTML = '<span class="text-success">✓ መጽሐፉ ተገኝቷል!</span>';
    const targetBookId = foundBook.id || foundBook.book_id;
    if (!IS_LIBRARIAN) {
      // ለተጠቃሚው ድጋሚ ሳይጠይቅ ወዲያውኑ ወደ መጽሐፉ ሙሉ ገጽ ይወስደዋል
      window.location.href = '<?= $base ?>book.php?id=' + targetBookId;
      return;
    }
    renderBookCard(foundBook, foundCopy);
    return;
  }

  // If not found locally and online, fetch from server
  if (navigator.onLine) {
    try {
      const res = await fetch('<?= $base ?>qr.php?code=' + encodeURIComponent(code) + '&json=1');
      if (res.ok) {
        const data = await res.json();
        if (data.success && data.copy) {
          status.innerHTML = '<span class="text-success">✓ መጽሐፉ ተገኝቷል!</span>';
          if (!IS_LIBRARIAN) {
            // ለተጠቃሚው ድጋሚ ሳይጠይቅ ወዲያውኑ ወደ መጽሐፉ ሙሉ ገጽ ይወስደዋል
            window.location.href = '<?= $base ?>book.php?id=' + data.book.id;
            return;
          }
          renderBookCard(data.book, data.copy);
          return;
        }
      }
    } catch (e) {}
  }

  status.innerHTML = `<span class="text-danger">«${escapeHtml(code)}» የተባለ ቅጂ አልተገኘም።</span>`;
  document.getElementById('scannedBookCard').style.display = 'none';
  isProcessingCode = false;
}

// 4. Render Details & Role Actions (Normal User vs Librarian)
function renderBookCard(book, copy) {
  currentBook = book;
  currentCopy = copy;

  const card = document.getElementById('scannedBookCard');
  document.getElementById('cardCopyCode').textContent = 'ID: ' + copy.copy_code;
  document.getElementById('cardTitle').textContent = book.title;
  document.getElementById('cardAuthor').textContent = 'በ ' + (book.author || 'ያልታወቀ ደራሲ');
  document.getElementById('cardShelf').textContent = book.shelf_name || 'መደበኛ መደርደሪያ';
  document.getElementById('cardPosition').textContent = copy.position || book.position || 'መደበኛ ረድፍ';
  document.getElementById('cardCategory').textContent = book.category_name || 'አጠቃላይ';

  const badge = document.getElementById('cardStatusBadge');
  const isAvailable = (copy.status === 'available');

  if (isAvailable) {
    badge.className = 'badge badge-success';
    badge.textContent = 'ለመዋስ ዝግጁ (ነፃ)';
  } else {
    badge.className = 'badge badge-warning';
    badge.textContent = 'በውሰት ላይ ያለ';
  }

  // Render Role-Specific Action Buttons
  const actBox = document.getElementById('cardActionBox');
  let btns = '';

  if (IS_LIBRARIAN) {
    // Librarian / Admin View
    if (isAvailable) {
      btns += `
        <button type="button" class="btn btn-gold btn-block" onclick="openBorrowModal()">
          <i class="bi bi-box-arrow-right"></i> ለአባል አበድር (Borrow / Issue)
        </button>
      `;
    } else {
      btns += `
        <button type="button" class="btn btn-navy btn-block" onclick="executeReturnAction()">
          <i class="bi bi-arrow-return-left"></i> የተመለሰ መጽሐፍ ተቀበል (Return)
        </button>
      `;
    }
  } else {
    // Normal User / Member / Guest View
    if (USER_ROLE === 'member') {
      if (isAvailable) {
        btns += `
          <button type="button" class="btn btn-gold btn-block" onclick="requestMemberBorrow()">
            <i class="bi bi-journal-plus"></i> የመዋስ ጥያቄ ላክ (Borrow Request)
          </button>
        `;
      } else {
        btns += `
          <div style="font-size:.82rem;color:var(--muted);text-align:center;padding:6px;background:#fff;border-radius:8px;border:1px solid var(--line);">
            <i class="bi bi-clock"></i> ይህ ቅጂ በአሁኑ ሰዓት በውሰት ላይ ስለሆነ ለመዋስ አይገኝም።
          </div>
        `;
      }
    } else {
      // Guest
      btns += `
        <a href="<?= $base ?>login.php" class="btn btn-navy btn-block">
          <i class="bi bi-box-arrow-in-right"></i> ይህንን መጽሐፍ ለመዋስ ይግቡ (Login)
        </a>
      `;
    }
  }

  // View Online/Full Details Link
  btns += `
    <a href="<?= $base ?>book.php?id=${book.id}" class="btn btn-outline btn-block btn-sm" style="margin-top:2px;">
      <i class="bi bi-info-circle"></i> ሙሉ የመጽሐፉን ገጽ ይመልከቱ
    </a>
  `;

  actBox.innerHTML = btns;
  card.style.display = 'block';
  card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// 5. Librarian Borrow Actions
function openBorrowModal() {
  document.getElementById('borrowModalBookInfo').textContent = `«${currentBook.title}» (ቅጂ ${currentCopy.copy_code})`;
  populateModalMembers(cachedMembersList);
  document.getElementById('librarianBorrowModal').style.display = 'flex';
}

function closeBorrowModal() {
  document.getElementById('librarianBorrowModal').style.display = 'none';
}

function populateModalMembers(list) {
  const sel = document.getElementById('modalMemberSelect');
  sel.innerHTML = '';
  if (!list || list.length === 0) {
    sel.innerHTML = '<option value="">ምንም አባል አልተገኘም</option>';
    return;
  }
  list.forEach(m => {
    const opt = document.createElement('option');
    opt.value = m.member_id;
    opt.textContent = `${m.full_name} (${m.phone || 'ስልክ የለም'})`;
    sel.appendChild(opt);
  });
  if (sel.options.length > 0) sel.selectedIndex = 0;
}

function filterModalMembers(query) {
  const q = query.trim().toLowerCase();
  const filtered = cachedMembersList.filter(m => 
    (m.full_name && m.full_name.toLowerCase().includes(q)) ||
    (m.phone && m.phone.includes(q))
  );
  populateModalMembers(filtered);
}

async function submitBorrowAction() {
  if (!currentCopy || !currentCopy.id) {
    alert('የመጽሐፍ ቅጂ መረጃ አልተገኘም፤ እባክዎ እንደገና ስካን ያድርጉ።');
    return;
  }
  const sel = document.getElementById('modalMemberSelect');
  const memberId = sel.value;
  if (!memberId) {
    alert('እባክዎ አባል ይምረጡ።');
    return;
  }
  const memberName = sel.options[sel.selectedIndex].text;
  closeBorrowModal();

  if (window.offlineEngine) {
    await window.offlineEngine.queueEvent('BORROW', {
      book_id: currentBook.id,
      copy_id: parseInt(currentCopy.id) || 0,
      qr_identifier: currentCopy.qr_identifier || '',
      copy_code: currentCopy.copy_code,
      member_id: parseInt(memberId),
      timestamp: new Date().toISOString()
    });

    // Update local copy state
    currentCopy.status = 'borrowed';
    await window.offlineEngine.put('books', currentBook);

    if (navigator.onLine) {
      try {
        const syncResult = await window.offlineEngine.syncWithServer();
        if (syncResult && syncResult.synced_count > 0) {
          alert(`✓ «${currentBook.title}» ለአባል [${memberName}] በአገልጋይ ተረጋግጦ ተሰጥቷል!`);
          renderBookCard(currentBook, currentCopy);
          return;
        } else if (syncResult && syncResult.results && syncResult.results.length > 0) {
          const firstErr = syncResult.results[0];
          if (firstErr.status === 'rejected') {
            alert(`⚠️ ውድቅ ተደርጓል፦ ${firstErr.message}`);
            // Revert local state
            currentCopy.status = 'available';
            await window.offlineEngine.put('books', currentBook);
            renderBookCard(currentBook, currentCopy);
            return;
          }
        }
      } catch (e) {}
    }
  }

  alert(`✓ «${currentBook.title}» ለአባል [${memberName}] በመሳሪያው ላይ ተመዝግቧል፤ ኔትዎርክ ሲኖር በአገልጋይ ይረጋገጣል።`);
  renderBookCard(currentBook, currentCopy);
}

// 6. Librarian Return Action
async function executeReturnAction() {
  if (!currentCopy || !currentCopy.id) {
    alert('የመጽሐፍ ቅጂ መረጃ አልተገኘም፤ እባክዎ እንደገና ስካን ያድርጉ።');
    return;
  }
  if (!confirm(`«${currentBook.title}» (ቅጂ ${currentCopy.copy_code}) መመለሱን ማረጋገጥ ይፈልጋሉ?`)) {
    return;
  }

  if (window.offlineEngine) {
    await window.offlineEngine.queueEvent('RETURN', {
      book_id: currentBook.id,
      copy_id: parseInt(currentCopy.id) || 0,
      qr_identifier: currentCopy.qr_identifier || '',
      copy_code: currentCopy.copy_code,
      timestamp: new Date().toISOString()
    });

    // Update local copy state
    currentCopy.status = 'available';
    await window.offlineEngine.put('books', currentBook);

    if (navigator.onLine) {
      try {
        const syncResult = await window.offlineEngine.syncWithServer();
        if (syncResult && syncResult.synced_count > 0) {
          alert(`✓ «${currentBook.title}» (ቅጂ ${currentCopy.copy_code}) መመለሱ በአገልጋይ ተረጋግጧል!`);
          renderBookCard(currentBook, currentCopy);
          return;
        }
      } catch (e) {}
    }
  }

  alert(`✓ «${currentBook.title}» (ቅጂ ${currentCopy.copy_code}) መመለሱ ተመዝግቧል፤ ኔትዎርክ ሲኖር በአገልጋይ ይረጋገጣል።`);
  renderBookCard(currentBook, currentCopy);
}

// 7. Member Borrow Request Action
async function requestMemberBorrow() {
  if (!currentCopy || !currentCopy.id) {
    alert('የመጽሐፍ ቅጂ መረጃ አልተገኘም፤ እባክዎ እንደገና ስካን ያድርጉ።');
    return;
  }
  if (window.offlineEngine) {
    await window.offlineEngine.queueEvent('REQUEST', {
      book_id: currentBook.id,
      copy_id: parseInt(currentCopy.id) || 0,
      qr_identifier: currentCopy.qr_identifier || '',
      copy_code: currentCopy.copy_code,
      timestamp: new Date().toISOString()
    });

    if (navigator.onLine) {
      try {
        const syncResult = await window.offlineEngine.syncWithServer();
        if (syncResult && syncResult.synced_count > 0) {
          alert(`✓ ለ«${currentBook.title}» የመዋስ ጥያቄዎ በአገልጋይ ተመዝግቧል!`);
          return;
        }
      } catch (e) {}
    }
  }
  alert(`✓ ለ«${currentBook.title}» የመዋስ ጥያቄዎ ተመዝግቧል! ኔትዎርክ ሲኖር በአገልጋይ ይረጋገጣል።`);
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, m => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  })[m]);
}
</script>

<style>
@keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
