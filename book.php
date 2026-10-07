<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';
require_once __DIR__ . '/includes/LibraryService.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$bookId = (int)($_GET['id'] ?? 0);

// Load book details
$stmt = mysqli_prepare($conn, "SELECT b.*, c.name AS category_name, r.name AS room_name, s.name AS shelf_name
    FROM books b 
    LEFT JOIN categories c ON c.id=b.category_id
    LEFT JOIN rooms r ON r.id=b.room_id 
    LEFT JOIN shelves s ON s.id=b.shelf_id
    WHERE b.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $bookId);
mysqli_stmt_execute($stmt);
$book = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$book) {
    http_response_code(404);
    $pageTitle = 'አልተገኘም';
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-question-circle"></i><h4>መጽሐፉ አልተገኘም</h4><a href="' . $base . 'search.php" class="btn btn-navy">ወደ ፍለጋ ተመለስ</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$isBorrowable = is_book_borrowable($book) && $book['borrow_status'] !== 'restricted' && $book['borrow_status'] !== 'archived';
$restrictionReason = $book['non_borrowable_reason'] ?: borrow_status_message($book['borrow_status']);

// Handle Post Actions (Borrow Request, Favorite, Availability Alert, Librarian Direct Issue)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    // 1. Member Borrow Request
    if (isset($_POST['action']) && in_array($_POST['action'], ['borrow', 'reserve'])) {
        require_role('member');
        if (is_member_blocked($conn, (int)$user['id'])) {
            flash('msg', member_block_message($conn, (int)$user['id']), 'danger');
            redirect('book.php?id=' . $bookId);
        }

        // Strict backend enforcement: Non-borrowable book CANNOT be requested!
        if (!$isBorrowable) {
            flash('msg', 'ይህ መጽሐፍ ለመዋስ አይፈቀድም። ' . ($restrictionReason ? "ምክንያት፦ $restrictionReason" : ""), 'danger');
            redirect('book.php?id=' . $bookId);
        }

        $type = $_POST['action'] === 'reserve' ? 'reserve' : 'borrow';
        $memberRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
        $memberId = (int)$memberRow['id'];

        $dupe = mysqli_query($conn, "SELECT id FROM borrow_requests WHERE member_id=$memberId AND book_id=$bookId AND status='pending'");
        if (mysqli_num_rows($dupe) > 0) {
            flash('msg', 'ለዚህ መጽሐፍ ቀደም ሲል ያስገቡት ጥያቄ በመጠባበቅ ላይ ነው።', 'warning');
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO borrow_requests (member_id, book_id, type) VALUES (?,?,?)");
            mysqli_stmt_bind_param($stmt, 'iis', $memberId, $bookId, $type);
            mysqli_stmt_execute($stmt);
            audit($conn, $user['id'], 'borrow_request_created', "book_id:$bookId type:$type");
            flash('msg', $type === 'reserve' ? 'የማስያዝ ጥያቄዎ ተልኳል።' : 'የመዋስ ጥያቄዎ ለማረጋገጫ ተልኳል።', 'success');
        }
        redirect('book.php?id=' . $bookId);
    }

    // 2. Toggle Favorite
    if (isset($_POST['toggle_favorite'])) {
        require_login();
        $uid = (int)$user['id'];
        $chk = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id=$uid AND book_id=$bookId");
        if (mysqli_num_rows($chk) > 0) {
            mysqli_query($conn, "DELETE FROM favorites WHERE user_id=$uid AND book_id=$bookId");
            flash('msg', 'መጽሐፉ ከወደፊት የማነባቸው ዝርዝር ወጥቷል።', 'info');
        } else {
            mysqli_query($conn, "INSERT INTO favorites (user_id, book_id) VALUES ($uid, $bookId)");
            flash('msg', 'መጽሐፉ ወደ ወደፊት የማነባቸው ዝርዝር ታክሏል 🔖', 'success');
        }
        redirect('book.php?id=' . $bookId);
    }

    // 3. Request Availability Alert ("ሲገኝ አሳውቀኝ")
    if (isset($_POST['request_alert'])) {
        require_login();
        $uid = (int)$user['id'];
        $chk = mysqli_query($conn, "SELECT id FROM book_availability_alerts WHERE user_id=$uid AND book_id=$bookId");
        if (mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, "INSERT INTO book_availability_alerts (user_id, book_id) VALUES ($uid, $bookId)");
            flash('msg', 'ይህ መጽሐፍ ሲገኝ ማሳወቂያ ይደርስዎታል 🔔', 'success');
        } else {
            flash('msg', 'ቀደም ሲል ማሳወቂያ እንዲደርስዎት ጠይቀዋል።', 'info');
        }
        redirect('book.php?id=' . $bookId);
    }

    // 4. Librarian / Admin Handover / Issue Book
    if (isset($_POST['librarian_issue_copy'])) {
        require_role(['librarian', 'admin']);
        $targetMemberId = (int)$_POST['target_member_id'];
        $targetCopyId = (int)$_POST['target_copy_id'];

        $result = issue_copy($conn, $targetMemberId, $targetCopyId, null, (int)$user['id']);
        if ($result['success']) {
            flash('msg', $result['message'], 'success');
        } else {
            flash('msg', $result['message'], 'danger');
        }
        redirect('book.php?id=' . $bookId);
    }
}

// Check copies & availability
$copies = mysqli_query($conn, "SELECT * FROM book_copies WHERE book_id=$bookId ORDER BY copy_code ASC");
$availableCount = 0; 
$copiesList = [];
while ($c = mysqli_fetch_assoc($copies)) { 
    if ($c['status'] === 'available') $availableCount++; 
    $copiesList[] = $c; 
}

// Check if current user favorited this book
$isFavorited = false;
$hasAlert = false;
if (is_logged_in()) {
    $uid = (int)$user['id'];
    $isFavorited = (mysqli_num_rows(mysqli_query($conn, "SELECT id FROM favorites WHERE user_id=$uid AND book_id=$bookId")) > 0);
    $hasAlert = (mysqli_num_rows(mysqli_query($conn, "SELECT id FROM book_availability_alerts WHERE user_id=$uid AND book_id=$bookId AND is_notified=0")) > 0);
}

$pageTitle = $book['title'];
$activeKey = 'browse';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <a href="javascript:history.back()" class="text-muted" style="font-size:.85rem;display:inline-flex;align-items:center;gap:4px;">
    <i class="bi bi-arrow-left"></i> <?= __('back') ?>
  </a>
  <?php if (is_logged_in() && $role === 'member'): ?>
    <form method="post" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="toggle_favorite" value="1">
      <button type="submit" class="btn btn-outline btn-sm" style="border-radius:20px;">
        <i class="bi <?= $isFavorited ? 'bi-bookmark-check-fill text-gold' : 'bi-bookmark-plus' ?>"></i>
        <?= $isFavorited ? 'በወደፊት የማነባቸው ውስጥ አለ' : 'ወደፊት የማነባቸው' ?>
      </button>
    </form>
  <?php endif; ?>
</div>

<!-- Book Main Info Card -->
<div class="card mb-3" style="overflow:hidden;">
  <div class="row g-0">
    <div class="col-4 col-md-3">
      <div class="book-cover" style="aspect-ratio:3/4;width:100%;max-width:200px;margin:0 auto;position:relative;">
        <?= book_cover_html($book['cover_original'] ?: $book['cover_image']) ?>
      </div>
    </div>
    <div class="col-8 col-md-9">
      <div class="card-pad">
        
        <!-- Borrowable & Status Badges -->
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php if ($isBorrowable): ?>
            <span class="badge badge-success"><i class="bi bi-check-circle"></i> <?= __('borrowable') ?></span>
          <?php else: ?>
            <span class="badge badge-danger"><i class="bi bi-x-circle"></i> <?= __('not_borrowable') ?></span>
          <?php endif; ?>

          <?php if ($availableCount > 0): ?>
            <span class="badge badge-gold"><i class="bi bi-box-seam"></i> <?= $availableCount ?> ቅጂ ይገኛል</span>
          <?php else: ?>
            <span class="badge badge-muted"><i class="bi bi-clock"></i> አሁን አይገኝም</span>
          <?php endif; ?>
        </div>

        <h2 class="font-display" style="font-size:1.25rem;color:var(--navy);margin:0 0 4px;line-height:1.3;">
          <?= e($book['title']) ?>
        </h2>
        <div class="text-muted" style="font-size:.88rem;margin-bottom:8px;">በ <?= e($book['author']) ?></div>
        
        <div class="d-flex flex-wrap gap-1 mb-2">
          <span class="badge badge-navy"><?= e($book['category_name']) ?></span>
          <span class="shelf-tag"><i class="bi bi-bookshelf"></i> <?= !empty($book['shelf_name']) ? 'መደርደሪያ ' . e($book['shelf_name']) : 'መደበኛ መደርደሪያ' ?></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Restriction alert if book is not borrowable -->
<?php if (!$isBorrowable): ?>
  <div class="card card-pad mb-3" style="border-left:5px solid var(--danger);background:rgba(239, 68, 68, 0.05);">
    <div style="font-weight:700;color:var(--danger);font-size:.9rem;margin-bottom:4px;">
      <i class="bi bi-shield-x"></i> ይህ መጽሐፍ ለመዋስ አይፈቀድም
    </div>
    <div class="text-muted" style="font-size:.85rem;line-height:1.5;">
      <?= e($restrictionReason ?: 'ይህ መጽሐፍ በቤተ መጻሕፍት ውስጥ ብቻ እንዲነበብ የተወሰነ ነው። ከላይብረሪ ውጭ ማውጣት የተከለከለ ነው።') ?>
    </div>
  </div>
<?php endif; ?>

<!-- Physical copies tracking -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="section-title" style="margin:0;"><i class="bi bi-journal-bookmark text-gold"></i> የመጽሐፉ ቅጂዎች (<?= count($copiesList) ?> ቅጂዎች)</div>
  <?php if ($role === 'librarian' || $role === 'admin'): ?>
    <a href="<?= $base ?>librarian/print_qr.php?q=<?= urlencode($book['title']) ?>" class="btn btn-outline btn-sm" style="font-size:.78rem;">
      <i class="bi bi-printer"></i> የQR ስቲከሮች አትም
    </a>
  <?php endif; ?>
</div>

<div class="card card-pad mb-3">
  <div class="row g-2">
    <?php foreach ($copiesList as $idx => $cp): 
      $copyQrUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '/') . 'qr.php?code=' . urlencode($cp['qr_identifier']);
    ?>
      <div class="col-12 col-sm-6">
        <div class="card p-2 h-100 d-flex flex-row align-items-center justify-content-between" style="border:1.5px solid var(--line);background:#fff;border-radius:10px;">
          <div style="flex:1;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
              <span class="shelf-tag" style="margin:0;font-weight:800;font-size:.85rem;color:var(--navy);"><?= e($cp['copy_code']) ?></span>
              <?php if ($cp['status'] === 'available'): ?>
                <span class="badge badge-success" style="font-size:.72rem;"><i class="bi bi-check-circle-fill"></i> ይገኛል</span>
              <?php elseif ($cp['status'] === 'borrowed'): ?>
                <span class="badge badge-warning" style="font-size:.72rem;"><i class="bi bi-clock-fill"></i> ተወስዷል</span>
              <?php else: ?>
                <span class="badge badge-danger" style="font-size:.72rem;"><?= e($cp['status']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.76rem;">
              <i class="bi bi-bookshelf"></i> <?= !empty($book['shelf_name']) ? 'መደርደሪያ ' . e($book['shelf_name']) : 'መደበኛ መደርደሪያ' ?><?= !empty($cp['position']) ? ' · ረድፍ ' . e($cp['position']) : '' ?>
            </div>
          </div>

          <?php if ($role === 'librarian' || $role === 'admin'): ?>
          <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
            <button type="button" class="btn btn-navy btn-sm" onclick="showCopyQrModal('<?= e(addslashes($cp['copy_code'])) ?>', '<?= e(addslashes($cp['qr_identifier'])) ?>', '<?= e(addslashes($copyQrUrl)) ?>', '<?= e(addslashes($book['title'])) ?>')" style="padding:6px 10px;font-size:.78rem;">
              <i class="bi bi-qr-code"></i> ስቲከር አሳይ
            </button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Interactive Modal for Showing Copy QR Code Instantly -->
<?php if ($role === 'librarian' || $role === 'admin'): ?>
<!-- Interactive Modal for Showing Copy QR Code Instantly (Librarian/Admin Only) -->
<div id="copyQrModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.75);z-index:9999;align-items:center;justify-content:center;padding:14px;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);">
  <div style="background:#fff;border-radius:20px;max-width:370px;width:100%;overflow:hidden;box-shadow:0 12px 35px rgba(0,0,0,0.35);position:relative;animation:popIn 0.2s ease;">
    <div style="background:var(--navy);padding:10px 14px;color:#fff;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-weight:700;font-size:.88rem;"><i class="bi bi-qr-code text-gold"></i> የመጽሐፍ ቅጂ ስቲከር (የአስተዳዳሪ)</span>
      <button type="button" onclick="closeCopyQrModal()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;line-height:1;">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div style="padding:14px;text-align:center;background:#f8fafc;">
      <!-- Watermark Canvas Container -->
      <canvas id="modalWatermarkCanvas" style="width:100%;max-width:320px;height:auto;border-radius:14px;box-shadow:0 6px 18px rgba(0,0,0,0.1);display:block;margin:0 auto;background:#fff;"></canvas>

      <div class="d-flex gap-2 justify-content-center mt-3">
        <button type="button" onclick="downloadModalQr()" class="btn btn-navy btn-sm" style="flex:1;">
          <i class="bi bi-download"></i> ስቲከር አውርድ (PNG)
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= $base ?>assets/js/qrcode.min.js"></script>
<script src="<?= $base ?>assets/js/advanced_qr_card.js?v=<?= filemtime(__DIR__ . '/assets/js/advanced_qr_card.js') ?>"></script>
<script>
let modalLogoImg = new Image();
modalLogoImg.src = '<?= $base ?>uploads/logo.png';
let currentModalCopyCode = '';

function showCopyQrModal(copyCode, qrId, qrUrl, bookTitle) {
  currentModalCopyCode = copyCode;

  const canvas = document.getElementById('modalWatermarkCanvas');
  if (canvas && typeof renderWatermarkQrCard === 'function') {
    function draw() {
      renderWatermarkQrCard(canvas, {
        text: qrUrl,
        title: bookTitle,
        copyCode: copyCode,
        logoImg: modalLogoImg,
        width: 360,
        height: 490,
        qrSize: 226,
        watermarkOpacity: 0.42
      });
    }

    if (modalLogoImg.complete) {
      draw();
    } else {
      modalLogoImg.onload = draw;
    }
    setTimeout(draw, 100);
  }

  const modal = document.getElementById('copyQrModal');
  modal.style.display = 'flex';
}

function closeCopyQrModal() {
  document.getElementById('copyQrModal').style.display = 'none';
}

function downloadModalQr() {
  const canvas = document.getElementById('modalWatermarkCanvas');
  if (!canvas) return;
  const link = document.createElement('a');
  link.download = 'Sticker_ID_' + (currentModalCopyCode.replace(/[^a-zA-Z0-9_-]/g, '_')) + '.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
}

// Close modal when clicked outside
document.getElementById('copyQrModal').addEventListener('click', function(e) {
  if (e.target === this) closeCopyQrModal();
});
</script>
<?php endif; ?>

<?php if ($book['description']): ?>
  <div class="section-title">መግለጫ</div>
  <div class="card card-pad mb-3">
    <p style="margin:0;font-size:.9rem;line-height:1.7;color:var(--ink);"><?= nl2br(e($book['description'])) ?></p>
  </div>
<?php endif; ?>

<!-- Book Meta Info -->
<div class="section-title">ተጨማሪ ዝርዝር መረጃ</div>
<div class="card mb-3">
  <table class="app-table app-stack" style="width:100%;">
    <tbody>
      <tr><td data-label="አሳታሚ"><?= e($book['publisher'] ?: '—') ?></td></tr>
      <tr><td data-label="የታተመበት ዓመት"><?= e($book['publication_year'] ?: '—') ?></td></tr>
      <tr><td data-label="ዋጋ"><?= $book['price'] ? number_format($book['price'],2) . ' ብር' : '—' ?></td></tr>
      <tr><td data-label="መገኛ ቦታ"><?= e($book['shelf_name'] ?: '—') ?><?= $book['position'] ? ' ('.e($book['position']).')' : '' ?></td></tr>
    </tbody>
  </table>
</div>

<!-- ================= ACTION PANELS ================= -->

<?php if ($role === 'member'): ?>
  <!-- Member Action Panel -->
  <div class="card card-pad mb-3" style="background:var(--slate-50);border:1px solid var(--line);">
    <div style="font-weight:700;font-size:.92rem;color:var(--navy);margin-bottom:8px;">
      <i class="bi bi-journal-bookmark text-gold"></i> የመዋስ አማራጮች
    </div>

    <?php if (!$isBorrowable): ?>
      <p class="text-muted" style="font-size:.85rem;margin:0;">
        ይህ መጽሐፍ ለውሰት የማይፈቀድ ስለሆነ የመዋስ ጥያቄ ማቅረብ አይቻልም። በቤተ-መጻሕፍቱ ውስጥ እንዲያነቡት ተጋብዘዋል።
      </p>
    <?php elseif ($availableCount > 0): ?>
      <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <button type="submit" name="action" value="borrow" class="btn btn-gold btn-block">
          <i class="bi bi-journal-plus"></i> ለመዋስ ጠይቅ
        </button>
      </form>
    <?php else: ?>
      <!-- Copy not available: reserve or request alert -->
      <div class="d-flex flex-column gap-2">
        <form method="post" style="margin:0;">
          <?= csrf_field() ?>
          <button type="submit" name="action" value="reserve" class="btn btn-navy btn-block">
            <i class="bi bi-bookmark-plus"></i> ቅጂ ሲመለስ እንዲያዝልኝ ጠይቅ (Reserve)
          </button>
        </form>

        <form method="post" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="request_alert" value="1">
          <button type="submit" class="btn btn-outline btn-block" <?= $hasAlert ? 'disabled' : '' ?>>
            <i class="bi bi-bell"></i> <?= $hasAlert ? '✓ ሲገኝ ማሳወቂያ እንዲደርስዎት ተመዝግቧል' : 'ይህ መጽሐፍ ሲገኝ አሳውቀኝ' ?>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>

<?php elseif ($role === 'librarian' || $role === 'admin'): ?>
  <!-- Librarian / Admin Operational Action Panel -->
  <div class="card card-pad mb-3" style="border:2px solid var(--gold);background:rgba(212, 175, 55, 0.08);">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong style="color:var(--navy);font-size:.95rem;">
        <i class="bi bi-gear-wide-connected text-gold"></i> የላይብረሪያን ፈጣን ውሰት መስጫ
      </strong>
      <span class="badge badge-navy"><?= ucfirst($role) ?></span>
    </div>

    <?php if (!$isBorrowable): ?>
      <div style="color:var(--danger);font-size:.85rem;margin-bottom:8px;">
        <i class="bi bi-shield-x"></i> ይህ መጽሐፍ በአድሚኑ የታገደ ስለሆነ ማበደር አይቻልም።
      </div>
    <?php elseif ($availableCount === 0): ?>
      <div class="text-muted" style="font-size:.85rem;margin-bottom:8px;">
        <i class="bi bi-info-circle"></i> አሁን ለማበደር የሚገኝ አካላዊ ቅጂ የለም።
      </div>
    <?php else: ?>
      <button type="button" class="btn btn-gold btn-block" onclick="openSheet('librarian-issue-sheet')">
        <i class="bi bi-person-check"></i> መጽሐፍ አበድር / አስረክብ
      </button>
    <?php endif; ?>
  </div>

  <!-- Sheet: Librarian Issue Directly to Member -->
  <div class="sheet-overlay" id="librarian-issue-sheet">
    <div class="sheet" style="max-height:90vh;overflow-y:auto;">
      <div class="sheet-handle"></div>
      <div class="sheet-title"><i class="bi bi-journal-arrow-up text-gold"></i> መጽሐፍ አበድር / አስረክብ</div>
      
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="librarian_issue_copy" value="1">

        <div class="field">
          <label>መጽሐፉን የሚወስደው አባል <span class="text-danger">*</span></label>
          <select class="input" name="target_member_id" required>
            <option value="">-- አባል ይምረጡ --</option>
            <?php 
              $mems = mysqli_query($conn, "SELECT m.id, u.full_name, u.phone FROM members m JOIN users u ON u.id=m.user_id WHERE u.status='active' ORDER BY u.full_name ASC");
              while ($m = mysqli_fetch_assoc($mems)):
            ?>
              <option value="<?= (int)$m['id'] ?>"><?= e($m['full_name']) ?> (<?= e($m['phone']) ?>)</option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="field">
          <label>የሚሰጠው አካላዊ ቅጂ <span class="text-danger">*</span></label>
          <select class="input" name="target_copy_id" required>
            <?php foreach ($copiesList as $c): if ($c['status'] === 'available'): ?>
              <option value="<?= (int)$c['id'] ?>">ቅጂ ኮድ፦ <?= e($c['copy_code']) ?></option>
            <?php endif; endforeach; ?>
          </select>
        </div>

        <div class="card card-pad mb-3" style="font-size:.8rem;line-height:1.5;background:var(--slate-50);">
          <i class="bi bi-shield-check text-success"></i> <strong>ማስታወሻ፦</strong> አባሉ ንቁ መሆኑ፣ የዚህ ወር ክፍያ (50+ ብር) መክፈሉ እና የውሰት ገደቡ በሰርቨሩ በራስ ሰር ይረጋገጣል።
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-gold btn-block">
            <i class="bi bi-check2"></i> አረጋግጥ እና አስረክብ
          </button>
          <button type="button" class="btn btn-outline" onclick="closeSheet('librarian-issue-sheet')">
            <?= __('cancel') ?>
          </button>
        </div>
      </form>
    </div>
  </div>

<?php elseif ($role === 'guest'): ?>
  <!-- Guest Action Panel -->
  <div class="card card-pad text-center mb-3">
    <p class="text-muted" style="font-size:.88rem;margin-bottom:10px;">ይህን መጽሐፍ ለመዋስ ወይም ለማስያዝ የአባልነት መለያ ያስፈልግዎታል።</p>
    <div class="d-flex gap-2 justify-content-center">
      <a href="<?= $base ?>login.php" class="btn btn-navy btn-sm"><i class="bi bi-box-arrow-in-right"></i> ይግቡ</a>
      <a href="<?= $base ?>register.php" class="btn btn-gold btn-sm"><i class="bi bi-person-plus"></i> አባል ይሁኑ</a>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
