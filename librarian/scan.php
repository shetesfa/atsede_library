<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian', 'admin']);

$pageTitle = __('scan_qr');
$activeKey = 'scan';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;"><?= __('scan_qr') ?></div>

<div class="card card-pad mb-3 text-center">
  <p class="text-muted" style="font-size:.86rem;margin-bottom:12px;">
    የመጽሐፉን አካላዊ ቅጂ QR ኮድ በካሜራዎ ያንብቡ ወይም የመለያ ኮዱን በእጅ ያስገቡ።
  </p>

  <!-- Video Scanner Viewport -->
  <div id="scanner-container" style="position:relative;width:100%;max-width:320px;margin:0 auto 12px;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:1/1;display:none;">
    <video id="qr-video" playsinline style="width:100%;height:100%;object-fit:cover;"></video>
    <div style="position:absolute;inset:20px;border:2px dashed var(--gold);border-radius:10px;pointer-events:none;"></div>
  </div>

  <button type="button" id="start-cam-btn" class="btn btn-gold btn-block mb-3" onclick="startCamera()">
    <i class="bi bi-camera"></i> ካሜራ ክፈት እና ስካን አድርግ
  </button>
  <button type="button" id="stop-cam-btn" class="btn btn-outline btn-block mb-3" style="display:none;" onclick="stopCamera()">
    <i class="bi bi-camera-video-off"></i> ካሜራ ዝጋ
  </button>

  <div id="scan-status" class="text-muted mb-2" style="font-size:.82rem;"></div>

  <div style="position:relative;margin:18px 0;text-align:center;">
    <hr style="border:0;border-top:1px solid var(--line);">
    <span style="position:absolute;top:-10px;left:50%;transform:translateX(-50%);background:#fff;padding:0 10px;font-size:.78rem;color:var(--muted);">ወይም በእጅ ኮድ ያስገቡ</span>
  </div>

  <!-- Manual Code Input Fallback -->
  <form action="<?= $base ?>qr.php" method="get" class="d-flex gap-2">
    <div class="input-group" style="flex:1;">
      <i class="bi bi-tag"></i>
      <input type="text" class="input" name="code" placeholder="ምሳሌ፦ 01A ወይም ATS-COPY-..." required>
    </div>
    <button type="submit" class="btn btn-navy">ፈልግ</button>
  </form>
</div>

<!-- Recent Copies Quick Links -->
<div class="section-title">በቅርብ የተጨመሩ ቅጂዎች</div>
<div class="card card-pad">
  <div class="d-flex flex-wrap gap-2">
    <?php
      $recentCopies = mysqli_query($conn, "
        SELECT bc.id, bc.copy_code, bc.qr_identifier, bc.status, b.title 
        FROM book_copies bc 
        JOIN books b ON b.id = bc.book_id 
        ORDER BY bc.id DESC LIMIT 10
      ");
      while ($rc = mysqli_fetch_assoc($recentCopies)):
    ?>
      <a href="<?= e(get_qr_url($rc['qr_identifier'])) ?>" class="btn btn-outline btn-sm" style="text-align:left;">
        <strong><?= e($rc['copy_code']) ?></strong> — <?= e(mb_substr($rc['title'], 0, 16)) ?>…
      </a>
    <?php endwhile; ?>
  </div>
</div>

<script src="<?= $base ?>assets/js/jsQR.min.js"></script>
<script>
let videoStream = null;
let scanInterval = null;
let scanCanvas = null;
let scanContext = null;

async function startCamera() {
  const container = document.getElementById('scanner-container');
  const video = document.getElementById('qr-video');
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

    container.style.display = 'block';
    startBtn.style.display = 'none';
    stopBtn.style.display = 'block';
    status.textContent = 'የመጽሐፉን QR ኮድ በካሜራው መሃል አድርገው ያሳዩ...';

    // 1. Try Native BarcodeDetector first
    if ('BarcodeDetector' in window) {
      try {
        const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
        scanInterval = setInterval(async () => {
          try {
            const barcodes = await barcodeDetector.detect(video);
            if (barcodes.length > 0) {
              handleScanResult(barcodes[0].rawValue);
            }
          } catch (e) {}
        }, 300);
        return;
      } catch (e) {}
    }

    // 2. Universal jsQR fallback for iOS, Firefox, and all other browsers
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
            handleScanResult(qr.data);
          }
        }
      }, 250);
    } else {
      status.innerHTML = '<span class="text-warning">ካሜራ ስካነር አልተገኘም፤ እባክዎ ከታች የቅጂውን ኮድ በእጅ ያስገቡ።</span>';
    }
  } catch (err) {
    status.innerHTML = '<span class="text-danger">ካሜራውን መክፈት አልተቻለም። እባክዎ የካሜራ ፍቃድ ይስጡ ወይም ኮዱን በእጅ ያስገቡ።</span>';
    stopCamera();
  }
}

function stopCamera() {
  if (scanInterval) {
    clearInterval(scanInterval);
    scanInterval = null;
  }
  if (videoStream) {
    videoStream.getTracks().forEach(track => track.stop());
    videoStream = null;
  }
  document.getElementById('scanner-container').style.display = 'none';
  document.getElementById('start-cam-btn').style.display = 'block';
  document.getElementById('stop-cam-btn').style.display = 'none';
  document.getElementById('scan-status').textContent = '';
}

function handleScanResult(scannedText) {
  stopCamera();
  let code = scannedText.trim();
  try {
    if (code.includes('code=')) {
      const url = new URL(code.startsWith('http') ? code : 'http://x/' + code);
      if (url.searchParams.has('code')) {
        code = url.searchParams.get('code');
      }
    }
  } catch (e) {}

  window.location.href = window.APP_BASE + 'qr.php?code=' + encodeURIComponent(code);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
