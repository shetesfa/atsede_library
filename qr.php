<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';
require_once __DIR__ . '/includes/qrcode.php';
require_once __DIR__ . '/includes/LibraryService.php';

$code = clean($_GET['code'] ?? '');
$user = current_user();
$role = $user['role'] ?? 'guest';

$copyData = resolve_copy_by_qr($conn, $code);

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$copyData) {
        echo json_encode(['success' => false, 'error' => 'ቅጂው አልተገኘም']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'book' => [
            'id' => (int)$copyData['book_id'],
            'title' => $copyData['title'],
            'author' => $copyData['author'],
            'category_name' => $copyData['category_name'] ?? 'አጠቃላይ',
            'room_name' => $copyData['room_name'] ?? 'ዋና አዳራሽ',
            'shelf_name' => $copyData['shelf_name'] ?? '—',
            'position' => $copyData['position'] ?? '—',
        ],
        'copy' => [
            'id' => (int)$copyData['id'],
            'copy_code' => $copyData['copy_code'],
            'qr_identifier' => $copyData['qr_identifier'],
            'status' => $copyData['status'],
            'is_available' => ($copyData['status'] === 'available'),
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$copyData) {
    http_response_code(404);
    $pageTitle = 'ቅጂው አልተገኘም';
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty-state">
            <i class="bi bi-qr-code text-danger" style="font-size:3rem;"></i>
            <h4>የተሳሳተ የQR ኮድ</h4>
            <p>ይህ QR ኮድ በቤተ መጻሕፍቱ የዳታቤዝ መዝገብ ውስጥ አልተገኘም።</p>
            <a href="' . $base . 'search.php" class="btn btn-navy mt-2">ወደ ፍለጋ ተመለስ</a>
          </div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$bookId = (int)$copyData['book_id'];
$copyId = (int)$copyData['id'];
$isBorrowable = is_book_borrowable($copyData) && $copyData['borrow_status'] !== 'restricted' && $copyData['borrow_status'] !== 'archived';
$isAvailable = ($copyData['status'] === 'available');

// Handle Librarian / Member actions
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    // 1. Librarian Issue Book from QR
    if (isset($_POST['librarian_issue'])) {
        require_role(['librarian', 'admin']);
        $targetMemberId = (int)$_POST['target_member_id'];
        
        $result = issue_copy($conn, $targetMemberId, $copyId, null, (int)$user['id']);
        if ($result['success']) {
            flash('msg', $result['message'], 'success');
        } else {
            flash('msg', $result['message'], 'danger');
        }
        redirect('qr.php?code=' . urlencode($code));
    }

    // 2. Librarian Return Book from QR
    if (isset($_POST['librarian_return'])) {
        require_role(['librarian', 'admin']);
        $activeRecord = mysqli_fetch_assoc(mysqli_query($conn, "
            SELECT id FROM borrow_records 
            WHERE book_copy_id = $copyId AND status = 'borrowed' 
            ORDER BY id DESC LIMIT 1
        "));

        if ($activeRecord) {
            $result = return_copy($conn, $copyId, 'returned', '', (int)$user['id']);
            if ($result['success']) {
                flash('msg', $result['message'], 'success');
            } else {
                flash('msg', $result['message'], 'danger');
            }
        } else {
            // Check copy status first: do NOT resurrect lost/damaged copies!
            $copyStatusRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM book_copies WHERE id = $copyId"));
            $currStatus = $copyStatusRow['status'] ?? '';
            if (in_array($currStatus, ['lost', 'damaged', 'archived'], true)) {
                flash('msg', "ይህ ቅጂ ሁኔታው '{$currStatus}' በመሆኑ ወደ 'available' መቀየር አይቻልም።", 'warning');
            } else {
                mysqli_query($conn, "UPDATE book_copies SET status='available' WHERE id=$copyId");
                flash('msg', 'ቅጂው አሁን የሚገኝ ተብሎ ተዘምኗል።', 'info');
            }
        }
        redirect('qr.php?code=' . urlencode($code));
    }

    // 3. Member Borrow Request from QR
    if (isset($_POST['member_request'])) {
        require_role('member');
        if (!$isBorrowable) {
            flash('msg', 'ይህ መጽሐፍ ለመዋስ አይፈቀድም።', 'danger');
        } else {
            $mRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
            $memberId = (int)$mRow['id'];
            $dupe = mysqli_query($conn, "SELECT id FROM borrow_requests WHERE member_id=$memberId AND book_id=$bookId AND status='pending'");
            if (mysqli_num_rows($dupe) > 0) {
                flash('msg', 'ለዚህ መጽሐፍ ቀደም ሲል ያስገቡት ጥያቄ በመጠባበቅ ላይ ነው።', 'warning');
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO borrow_requests (member_id, book_id, book_copy_id, type) VALUES (?,?,?, 'borrow')");
                mysqli_stmt_bind_param($stmt, 'iii', $memberId, $bookId, $copyId);
                mysqli_stmt_execute($stmt);
                audit($conn, $user['id'], 'qr_borrow_request', "book_id:$bookId copy_id:$copyId");
                flash('msg', 'የመዋስ ጥያቄዎ ለላይብረሪያኑ ተልኳል!', 'success');
            }
        }
        redirect('qr.php?code=' . urlencode($code));
    }
}

// Active borrower details for librarian view
$currentBorrower = null;
if (($role === 'librarian' || $role === 'admin') && !$isAvailable) {
    $currentBorrower = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT br.*, u.full_name, u.phone, m.class 
        FROM borrow_records br
        JOIN members m ON m.id = br.member_id
        JOIN users u ON u.id = m.user_id
        WHERE br.book_copy_id = $copyId AND br.status = 'borrowed'
        ORDER BY br.borrowed_at DESC LIMIT 1
    "));
}

// Deep stock statistics for this book across the library
$stockRes = mysqli_query($conn, "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status='borrowed' THEN 1 ELSE 0 END) as borrowed
    FROM book_copies WHERE book_id = $bookId");
$stock = mysqli_fetch_assoc($stockRes);
$totalCopies = (int)($stock['total'] ?? 0);
$availableCopies = (int)($stock['available'] ?? 0);
$borrowedCopies = (int)($stock['borrowed'] ?? 0);

$pageTitle = $copyData['title'] . ' (ቅጂ ' . $copyData['copy_code'] . ')';
$activeKey = 'browse';
include __DIR__ . '/includes/header.php';
?>

<!-- ADVANCED WATERMARK QR CODE STICKER (Matching User Exact Sample) -->
<div class="card mb-3 text-center" style="overflow:hidden;border:1px solid var(--line);box-shadow:0 6px 24px rgba(0,0,0,0.08);background:#f8fafc;padding:16px 12px;">
  <div style="max-width:340px;margin:0 auto;">
    <canvas id="singleCopyWatermarkCanvas" style="width:100%;height:auto;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,0.12);display:block;background:#fff;"></canvas>
  </div>

  <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
    <button type="button" class="btn btn-navy btn-sm" onclick="downloadSingleQr()">
      <i class="bi bi-download"></i> ስቲከር አውርድ (PNG)
    </button>
    <?php if ($role === 'librarian' || $role === 'admin'): ?>
      <a href="<?= $base ?>librarian/print_qr.php?q=<?= urlencode($copyData['copy_code']) ?>" class="btn btn-outline btn-sm">
        <i class="bi bi-printer"></i> ስቲከር አትም
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- Book Preview Card -->
<div class="card mb-3" style="overflow:hidden;">
  <div class="row g-0">
    <div class="col-4 col-md-3">
      <div class="book-cover" style="aspect-ratio:3/4;width:100%;max-width:180px;margin:0 auto;">
        <?= book_cover_html($copyData['cover_original'] ?: $copyData['cover_image']) ?>
      </div>
    </div>
    <div class="col-8 col-md-9">
      <div class="card-pad">
        
        <!-- Status Badges -->
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php if ($isBorrowable): ?>
            <span class="badge badge-success"><i class="bi bi-check-circle"></i> ለመዋስ የተፈቀደ</span>
          <?php else: ?>
            <span class="badge badge-danger"><i class="bi bi-x-circle"></i> በቤተ መጻሕፍት ውስጥ ብቻ</span>
          <?php endif; ?>

          <?php if ($isAvailable): ?>
            <span class="badge badge-gold"><i class="bi bi-check-circle-fill"></i> ይህ ቅጂ ይገኛል</span>
          <?php else: ?>
            <span class="badge badge-warning"><i class="bi bi-clock-fill"></i> ይህ ቅጂ ተወስዷል</span>
          <?php endif; ?>
        </div>

        <h2 class="font-display" style="font-size:1.25rem;color:var(--navy);margin:0 0 4px;line-height:1.35;">
          <?= e($copyData['title']) ?>
        </h2>
        <div class="text-muted" style="font-size:.88rem;margin-bottom:6px;">በ <?= e($copyData['author']) ?></div>
        
        <div style="font-size:.82rem;color:var(--navy);background:var(--slate-50);padding:5px 8px;border-radius:6px;display:inline-block;">
          <i class="bi bi-bookmark-fill text-gold"></i> <strong>ምድብ፦</strong> <?= e($copyData['category_name'] ?: 'ያልተመደበ') ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- DEEP SHELF & LOCATION DETAILS CARD -->
<div class="section-title"><i class="bi bi-geo-alt-fill text-gold"></i> ጥልቅ የመደርደሪያና ክፍል መገኛ</div>
<div class="card card-pad mb-3" style="background:#fff;border-left:4px solid var(--navy);">
  <div class="row g-2 text-center text-sm-start">
    <div class="col-12 col-sm-4">
      <div style="font-size:.76rem;font-weight:700;color:var(--muted);text-transform:uppercase;">የክፍሉ ስም</div>
      <div style="font-size:.95rem;font-weight:700;color:var(--navy);margin-top:2px;">
        <i class="bi bi-door-open text-primary"></i> <?= e($copyData['room_name'] ?: 'ዋና አዳራሽ') ?>
      </div>
    </div>
    <div class="col-12 col-sm-4">
      <div style="font-size:.76rem;font-weight:700;color:var(--muted);text-transform:uppercase;">የመደርደሪያ ስም / ቁጥር</div>
      <div style="font-size:.95rem;font-weight:700;color:var(--navy);margin-top:2px;">
        <i class="bi bi-bookshelf text-gold"></i> <?= e($copyData['shelf_name'] ?: 'መደርደሪያ አልተመደበም') ?>
      </div>
    </div>
    <div class="col-12 col-sm-4">
      <div style="font-size:.76rem;font-weight:700;color:var(--muted);text-transform:uppercase;">ትክክለኛ የመደርደሪያ ቦታ / ረድፍ</div>
      <div style="font-size:.95rem;font-weight:700;color:var(--navy);margin-top:2px;">
        <i class="bi bi-pin-map text-danger"></i> <?= e($copyData['position'] ?: 'መደበኛ ረድፍ') ?>
      </div>
    </div>
  </div>
</div>

<!-- LIBRARY STOCK & SPECIFICATIONS CARD -->
<div class="section-title"><i class="bi bi-journal-check text-gold"></i> የመጽሐፉ ቅጂዎችና የላይብረሪ አጠቃላይ ሁኔታ</div>
<div class="card card-pad mb-3">
  <div class="row g-2 text-center mb-3">
    <div class="col-4">
      <div style="background:var(--slate-50);padding:10px 6px;border-radius:8px;border:1px solid var(--line);">
        <div style="font-size:1.2rem;font-weight:800;color:var(--navy);"><?= $totalCopies ?></div>
        <div style="font-size:.74rem;color:var(--muted);font-weight:600;">አጠቃላይ ቅጂዎች</div>
      </div>
    </div>
    <div class="col-4">
      <div style="background:#f0fdf4;padding:10px 6px;border-radius:8px;border:1px solid #bbf7d0;">
        <div style="font-size:1.2rem;font-weight:800;color:#166534;"><?= $availableCopies ?></div>
        <div style="font-size:.74rem;color:#166534;font-weight:600;">አሁን የሚገኙ ነፃ</div>
      </div>
    </div>
    <div class="col-4">
      <div style="background:#fffbeb;padding:10px 6px;border-radius:8px;border:1px solid #fde68a;">
        <div style="font-size:1.2rem;font-weight:800;color:#b45309;"><?= $borrowedCopies ?></div>
        <div style="font-size:.74rem;color:#b45309;font-weight:600;">በውሰት ላይ ያሉ</div>
      </div>
    </div>
  </div>

  <div style="font-size:.84rem;line-height:1.7;color:var(--ink);border-top:1px solid var(--line);padding-top:10px;">
    <div><strong>የተቃኘ ቅጂ ሁኔታ፦</strong> <?= $isAvailable ? '<span class="text-success font-weight-bold">✓ ነፃ — ለመዋስ ዝግጁ</span>' : '<span class="text-warning font-weight-bold">⏳ በውሰት ላይ ያለ</span>' ?></div>
    <div><strong>የህትመት ዓመት፦</strong> <?= e($copyData['publication_year'] ?: 'ያልተገለጸ') ?> · <strong>አሳታሚ፦</strong> <?= e($copyData['publisher'] ?: 'ያልተገለጸ') ?></div>
    <?php if ($copyData['price']): ?>
      <div><strong>ዋጋ፦</strong> <?= number_format((float)$copyData['price'], 2) ?> ብር</div>
    <?php endif; ?>
  </div>
</div>

<!-- Non-borrowable banner -->
<?php if (!$isBorrowable): ?>
  <div class="card card-pad mb-3" style="border-left:5px solid var(--danger);background:rgba(239, 68, 68, 0.05);">
    <div style="font-weight:700;color:var(--danger);font-size:.88rem;">
      <i class="bi bi-shield-x"></i> ይህ መጽሐፍ ከላይብረሪ ውጭ እንዲወጣ አይፈቀድም
    </div>
    <div class="text-muted" style="font-size:.82rem;margin-top:2px;">
      <?= e($copyData['non_borrowable_reason'] ?: 'ብርቅዬ ወይም የንባብ ክፍል መጽሐፍ ስለሆነ በቤተ መጻሕፍቱ ውስጥ ብቻ ይነበባል።') ?>
    </div>
  </div>
<?php endif; ?>

<!-- Description if present -->
<?php if ($copyData['description']): ?>
  <div class="section-title"><i class="bi bi-card-text text-gold"></i> መግለጫና ይዘት</div>
  <div class="card card-pad mb-3">
    <p style="margin:0;font-size:.88rem;line-height:1.65;color:var(--ink);"><?= nl2br(e($copyData['description'])) ?></p>
  </div>
<?php endif; ?>

<!-- ================= ROLE SPECIFIC ACTION SECTION ================= -->

<?php if ($role === 'librarian' || $role === 'admin'): ?>
  <!-- LIBRARIAN / ADMIN SPECIFIC PANEL -->
  <div class="card card-pad mb-3" style="border:2px solid var(--gold);background:rgba(212, 175, 55, 0.08);">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong style="color:var(--navy);font-size:.95rem;">
        <i class="bi bi-person-badge text-gold"></i> የላይብረሪያን ፈጣን የውሰት / ተመላሽ መቆጣጠሪያ
      </strong>
      <span class="badge badge-navy"><?= ucfirst($role) ?></span>
    </div>

    <?php if ($currentBorrower): ?>
      <div class="card card-pad mb-2" style="background:#fff;border:1px solid var(--line);">
        <div style="font-size:.82rem;font-weight:700;color:var(--muted);text-transform:uppercase;">አሁን በውሰት የያዘው አባል</div>
        <div style="font-weight:700;font-size:.95rem;color:var(--navy);margin-top:2px;"><?= e($currentBorrower['full_name']) ?></div>
        <div class="text-muted" style="font-size:.8rem;"><i class="bi bi-telephone"></i> <?= e($currentBorrower['phone']) ?> · የመመለሻ ቀን፦ <?= formatDate($currentBorrower['due_date']) ?></div>
      </div>

      <form method="post" onsubmit="return confirmAction('ይህ ቅጂ በትክክል መመለሱን ያረጋግጣሉ?', this);">
        <?= csrf_field() ?>
        <input type="hidden" name="librarian_return" value="1">
        <button type="submit" class="btn btn-success btn-block">
          <i class="bi bi-arrow-return-left"></i> ተመላሽ ተቀበል (ቅጂውን ነጻ አድርግ)
        </button>
      </form>

    <?php elseif (!$isBorrowable): ?>
      <div style="color:var(--danger);font-size:.86rem;">
        <i class="bi bi-shield-x"></i> ይህ መጽሐፍ ለውሰት የማይፈቀድ ስለሆነ ማበደር አይቻልም።
      </div>

    <?php elseif ($isAvailable): ?>
      <!-- Add Borrow Button for Librarian as requested! -->
      <button type="button" class="btn btn-gold btn-block" onclick="openSheet('librarian-qr-borrow-sheet')">
        <i class="bi bi-journal-arrow-up"></i> መጽሐፍ አበድር / አስረክብ
      </button>

      <!-- Bottom sheet to pick member and issue immediately -->
      <div class="sheet-overlay" id="librarian-qr-borrow-sheet">
        <div class="sheet" style="max-height:90vh;overflow-y:auto;">
          <div class="sheet-handle"></div>
          <div class="sheet-title"><i class="bi bi-journal-arrow-up text-gold"></i> ቅጂውን ለአባል አበድር</div>
          
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="librarian_issue" value="1">

            <div class="field">
              <label>የሚወስደው አባል <span class="text-danger">*</span></label>
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

            <div class="card card-pad mb-3" style="font-size:.8rem;line-height:1.5;background:var(--slate-50);">
              <i class="bi bi-shield-check text-success"></i> <strong>ቼክሊስት፦</strong> አባሉ ንቁ መሆኑ፣ የወሩ ክፍያ፣ ያልተከፈለ ቅጣት እና የውሰት ገደብ ወዲያው ይረጋገጣል። ቅጣት ካለ <a href="librarian/fines.php" target="_blank" style="text-decoration:underline;">በቅጣቶች ገጽ</a> ይክፈሉ።
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-gold btn-block">
                <i class="bi bi-check2-circle"></i> አረጋግጥ እና አስረክብ
              </button>
              <button type="button" class="btn btn-outline" onclick="closeSheet('librarian-qr-borrow-sheet')">
                <?= __('cancel') ?>
              </button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>
  </div>

<?php elseif ($role === 'member'): ?>
  <!-- MEMBER ACTION PANEL -->
  <div class="card card-pad mb-3" style="background:var(--slate-50);border:1px solid var(--line);">
    <?php if (!$isBorrowable): ?>
      <p class="text-muted" style="font-size:.85rem;margin:0;">
        ይህ መጽሐፍ ከላይብረሪ ውጭ የማይወጣ ስለሆነ የመዋስ ጥያቄ ማቅረብ አይቻልም። በንባብ አዳራሹ ውስጥ እንዲያነቡት ተጋብዘዋል።
      </p>
    <?php elseif ($isAvailable): ?>
      <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="member_request" value="1">
        <button type="submit" class="btn btn-gold btn-block">
          <i class="bi bi-journal-plus"></i> ይህንን ቅጂ ለመዋስ ጠይቅ
        </button>
      </form>
    <?php else: ?>
      <p class="text-muted" style="font-size:.85rem;margin-bottom:8px;">ይህ ቅጂ አሁን በሌላ ሰው ተወስዷል። ሙሉ የመጽሐፍ ገጽ ላይ ሄደው መጠየቅ ይችላሉ።</p>
      <a href="<?= $base ?>book.php?id=<?= $bookId ?>" class="btn btn-navy btn-block btn-sm">የመጽሐፉን ሙሉ መረጃ ይዩ</a>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- GUEST ACTION PANEL -->
  <div class="card card-pad text-center mb-3">
    <p class="text-muted" style="font-size:.88rem;margin-bottom:10px;">
      መጽሐፉን ለመዋስ ወይም የቤተ መጻሕፍቱ አባል ለመሆን ይግቡ።
    </p>
    <div class="d-flex gap-2 justify-content-center">
      <a href="<?= $base ?>login.php" class="btn btn-navy btn-sm"><i class="bi bi-box-arrow-in-right"></i> ይግቡ</a>
      <a href="<?= $base ?>register.php" class="btn btn-gold btn-sm"><i class="bi bi-person-plus"></i> አባል ይሁኑ</a>
    </div>
  </div>
<?php endif; ?>

<div class="text-center mt-3">
  <a href="<?= $base ?>book.php?id=<?= $bookId ?>" class="text-muted" style="font-size:.84rem;">
    <i class="bi bi-journal-text"></i> የመጽሐፉን ሙሉ ዝርዝር መረጃ ይክፈቱ
  </a>
</div>

<script src="<?= $base ?>assets/js/qrcode.min.js"></script>
<script src="<?= $base ?>assets/js/advanced_qr_card.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const canvas = document.getElementById('singleCopyWatermarkCanvas');
  if (!canvas) return;

  const logoImg = new Image();
  logoImg.src = '<?= $base ?>uploads/logo.png';

  function render() {
    renderWatermarkQrCard(canvas, {
      text: window.location.href,
      title: <?= json_encode($copyData['title'], JSON_UNESCAPED_UNICODE) ?>,
      copyCode: <?= json_encode($copyData['copy_code']) ?>,
      logoImg: logoImg,
      width: 360,
      height: 490,
      qrSize: 226,
      watermarkOpacity: 0.42
    });
  }

  logoImg.onload = render;
  if (logoImg.complete) render();
  setTimeout(render, 120);
});

function downloadSingleQr() {
  const canvas = document.getElementById('singleCopyWatermarkCanvas');
  if (!canvas) {
    alert('ስቲከሩ ገና አልተዘጋጀም');
    return;
  }
  const link = document.createElement('a');
  link.download = 'Sticker_ID_<?= e($copyData['copy_code']) ?>.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
