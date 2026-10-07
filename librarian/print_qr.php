<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_once __DIR__ . '/../includes/qrcode.php';
require_role(['librarian', 'admin']);

// 100% Dynamic Host & URL Resolution — Zero hardcoded IPs, ready for any domain/server
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$rawBase = defined('BASE_URL') ? BASE_URL : '/';
$appRoot = '/' . trim($rawBase, '/') . '/';
if ($appRoot === '//') $appRoot = '/';

$publicBaseUrl = $scheme . $host . $appRoot;

// Filters & limit
$bookId = (int)($_GET['book_id'] ?? 0);
$categoryId = (int)($_GET['category_id'] ?? 0);
$limit = trim($_GET['limit'] ?? 'all'); // 'all', '50', '100', '200'

$where = ["1=1"];
if ($bookId > 0) $where[] = "b.id = $bookId";
if ($categoryId > 0) $where[] = "b.category_id = $categoryId";
$whereSql = implode(' AND ', $where);

$limitSql = "";
if ($limit === '50') $limitSql = "LIMIT 50";
elseif ($limit === '100') $limitSql = "LIMIT 100";
elseif ($limit === '200') $limitSql = "LIMIT 200";

$copiesQuery = "
    SELECT bc.id AS copy_id, bc.copy_code, bc.qr_identifier, bc.status AS copy_status,
           b.id AS book_id, b.title, b.author, b.position,
           c.name AS category_name, r.name AS room_name, s.name AS shelf_name
    FROM book_copies bc
    JOIN books b ON b.id = bc.book_id
    LEFT JOIN categories c ON c.id = b.category_id
    LEFT JOIN rooms r ON r.id = b.room_id
    LEFT JOIN shelves s ON s.id = b.shelf_id
    WHERE $whereSql
    ORDER BY b.title ASC, bc.copy_code ASC
    $limitSql
";
$copies = mysqli_query($conn, $copiesQuery);
$totalCopiesCount = mysqli_num_rows($copies);

// Count total available in database
$countRes = mysqli_query($conn, "SELECT COUNT(*) as total FROM book_copies bc JOIN books b ON b.id = bc.book_id WHERE $whereSql");
$overallTotal = mysqli_fetch_assoc($countRes)['total'] ?? 0;

$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY name ASC");
$booksList = mysqli_query($conn, "SELECT id, title FROM books ORDER BY title ASC");

$pageTitle = 'የመጻሕፍት QR ኮድ ስቲከሮች ማተሚያ';
$activeKey = 'books';
include __DIR__ . '/../includes/header.php';
?>

<!-- Client-side QR Code Generator & Advanced Watermark Sticker Engine -->
<script src="<?= $appRoot ?>assets/js/qrcode.min.js"></script>
<script src="<?= $appRoot ?>assets/js/advanced_qr_card.js"></script>

<div class="no-print mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <h2 style="font-size:1.35rem;font-weight:800;color:var(--navy);margin:0;">
        <i class="bi bi-qr-code text-gold"></i> የመጻሕፍት QR ኮድ ስቲከሮች ማተሚያ
      </h2>
      <p class="text-muted mb-0" style="font-size:.84rem;">
        ለመጻሕፍት አካላዊ ሽፋን የሚለጠፉ ንጹህ የQR ስቲከሮች። በስልክ ሲቃኙ የመጽሐፉን ሙሉ ዝርዝር፣ መደርደሪያና ክፍል ያሳያሉ።
      </p>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-gold" onclick="window.print()" style="font-weight:700;">
        <i class="bi bi-printer-fill"></i> ስቲከሮችን አትም / እንደ PDF አስቀምጥ
      </button>
    </div>
  </div>

  <!-- Filter & Search Toolbar -->
  <form method="get" class="card card-pad mb-3" id="filter-form">
    <div class="row g-2 align-items-end">
      
      <!-- Limit Selector -->
      <div class="col-12 col-md-3">
        <label style="font-size:.78rem;font-weight:700;color:var(--navy);">የቅጂዎች ብዛት</label>
        <select class="input" name="limit" onchange="this.form.submit()">
          <option value="all" <?= $limit === 'all' ? 'selected' : '' ?>>ሁሉንም (<?= $overallTotal ?> ቅጂዎች)</option>
          <option value="50" <?= $limit === '50' ? 'selected' : '' ?>>50 ቅጂዎች</option>
          <option value="100" <?= $limit === '100' ? 'selected' : '' ?>>100 ቅጂዎች</option>
          <option value="200" <?= $limit === '200' ? 'selected' : '' ?>>200 ቅጂዎች</option>
        </select>
      </div>

      <!-- Category Filter -->
      <div class="col-12 col-md-4">
        <label style="font-size:.78rem;font-weight:700;color:var(--navy);">በምድብ ምረጥ</label>
        <select class="input" name="category_id" onchange="this.form.submit()">
          <option value="0">-- ሁሉም ምድቦች --</option>
          <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <!-- Book Filter -->
      <div class="col-12 col-md-5">
        <label style="font-size:.78rem;font-weight:700;color:var(--navy);">በመጽሐፍ ምረጥ</label>
        <select class="input" name="book_id" onchange="this.form.submit()">
          <option value="0">-- ሁሉም መጻሕፍት --</option>
          <?php mysqli_data_seek($booksList, 0); while ($b = mysqli_fetch_assoc($booksList)): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $bookId === (int)$b['id'] ? 'selected' : '' ?>>
              <?= e($b['title']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>

    <!-- Live Amharic Search Input -->
    <div class="row g-2 mt-2 pt-2" style="border-top:1px solid #f1f5f9;">
      <div class="col-12 col-md-8">
        <div class="input-group">
          <i class="bi bi-search"></i>
          <input type="text" id="live-sticker-search" class="input" placeholder="በመጽሐፍ ስም ወይም በቅጂ ኮድ እዚህ ይፈልጉ..." oninput="filterStickersLive(this.value)">
        </div>
      </div>
      <div class="col-12 col-md-4 text-md-end d-flex align-items-center justify-content-between justify-content-md-end gap-2">
        <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:.82rem;padding:6px 12px;">
          አሁን የሚታዩ፦ <strong id="visible-sticker-count"><?= $totalCopiesCount ?></strong> / <?= $overallTotal ?>
        </span>
        <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
          <i class="bi bi-printer"></i> አትም
        </button>
      </div>
    </div>
  </form>
</div>

<style>
/* Clean Sticker Card Layout: Focused on QR Code + Book Title Only */
.qr-sticker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.qr-sticker-card {
  background: transparent;
  border: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  position: relative;
  page-break-inside: avoid;
  break-inside: avoid;
  box-sizing: border-box;
}

.watermark-sticker-canvas {
  width: 100%;
  max-width: 270px;
  height: auto;
  display: block;
  border-radius: 14px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.06);
  background: #ffffff;
}

/* Print Specific Rules for A4 Paper & PDF */
@media print {
  @page {
    size: A4 portrait;
    margin: 5mm;
  }
  
  html, body {
    background: #ffffff !important;
    color: #000000 !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  
  .no-print, 
  .topbar, 
  .bottomnav, 
  .sidebar, 
  .app-shell > header,
  .app-footer,
  .offline-banner,
  .btn,
  header {
    display: none !important;
  }
  
  .app-shell, .page, .main-content {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
  }
  
  .qr-sticker-grid {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 4mm !important;
    margin: 0 !important;
  }
  
  .qr-sticker-card {
    border: 0 !important;
    box-shadow: none !important;
    page-break-inside: avoid !important;
    break-inside: avoid !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  
  .watermark-sticker-canvas {
    width: 63mm !important;
    height: auto !important;
    box-shadow: none !important;
    border-radius: 3.5mm !important;
    border: 0.5pt solid #cbd5e1 !important;
    display: block !important;
  }
}
</style>

<!-- Stickers Grid -->
<div class="qr-sticker-grid" id="stickers-container">
  <?php if ($totalCopiesCount === 0): ?>
    <div class="card card-pad text-center" style="grid-column: 1 / -1; padding: 40px 20px;">
      <i class="bi bi-search text-muted" style="font-size:2.5rem;"></i>
      <h4 style="margin:12px 0 6px;">ምንም ቅጂ አልተገኘም</h4>
      <p class="text-muted">ለተመረጠው መመዘኛ ምንም የመጽሐፍ ቅጂ አልተገኘም።</p>
      <a href="print_qr.php" class="btn btn-navy mt-2">ሁሉንም ቅጂዎች አሳይ</a>
    </div>
  <?php endif; ?>

  <?php while ($cp = mysqli_fetch_assoc($copies)): 
    // Construct the absolute scannable network URL for this copy
    $copyQrUrl = $publicBaseUrl . 'qr.php?code=' . urlencode($cp['qr_identifier']);
    $searchPayload = mb_strtolower($cp['title'] . ' ' . $cp['copy_code'] . ' ' . $cp['qr_identifier']);
  ?>
    <div class="qr-sticker-card" data-search="<?= e($searchPayload) ?>">
      <canvas class="watermark-sticker-canvas" 
              data-url="<?= e($copyQrUrl) ?>" 
              data-title="<?= e($cp['title']) ?>" 
              data-code="<?= e($cp['copy_code']) ?>"></canvas>
    </div>
  <?php endwhile; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const sundaySchoolLogo = new Image();
  sundaySchoolLogo.src = "<?= $base ?>uploads/logo.png";

  function renderAllStickers() {
    const canvases = document.querySelectorAll('.watermark-sticker-canvas');
    canvases.forEach(function(cvs) {
      if (cvs.getAttribute('data-rendered') === 'true') return;
      const url = cvs.getAttribute('data-url');
      const title = cvs.getAttribute('data-title');
      const code = cvs.getAttribute('data-code');

      if (url && typeof renderWatermarkQrCard === 'function') {
        renderWatermarkQrCard(cvs, {
          text: url,
          title: title,
          copyCode: code,
          logoImg: sundaySchoolLogo,
          width: 340,
          height: 460,
          qrSize: 212,
          watermarkOpacity: 0.42
        });
        cvs.setAttribute('data-rendered', 'true');
      }
    });
  }

  sundaySchoolLogo.onload = renderAllStickers;
  if (sundaySchoolLogo.complete) renderAllStickers();
  setTimeout(renderAllStickers, 120);
});

// Client-side Live Filter for Instant Search
function filterStickersLive(query) {
  const q = query.trim().toLowerCase();
  const cards = document.querySelectorAll('.qr-sticker-card');
  let visibleCount = 0;

  cards.forEach(function(card) {
    const searchData = card.getAttribute('data-search') || '';
    if (q === '' || searchData.includes(q)) {
      card.style.display = '';
      visibleCount++;
    } else {
      card.style.display = 'none';
    }
  });

  const countBadge = document.getElementById('visible-sticker-count');
  if (countBadge) {
    countBadge.textContent = visibleCount;
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
