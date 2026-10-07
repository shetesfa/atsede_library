<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

$user = current_user();
$role = $user['role'] ?? 'guest';

// ---- Handle borrow / reserve request (members only) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    require_role('member');
    if (is_member_blocked($conn, (int)$user['id'])) {
        flash('msg', member_block_message($conn, (int)$user['id']), 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }
    $bookId = (int)$_POST['book_id'];
    $type = $_POST['action'] === 'reserve' ? 'reserve' : 'borrow';

    $bCheck = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_borrowable, borrow_status, non_borrowable_reason FROM books WHERE id=$bookId"));
    if (!$bCheck || !is_book_borrowable($bCheck) || $bCheck['borrow_status'] === 'restricted') {
        flash('msg', 'ይህ መጽሐፍ ለመዋስ አይፈቀድም። ' . ($bCheck['non_borrowable_reason'] ?? ''), 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }

    $memberRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
    $memberId = (int)$memberRow['id'];

    // Strict 1-book borrowing rule
    $activeBorrows = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed'"))['c'];
    $pendingReqs = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE member_id=$memberId AND status='pending' AND book_id != $bookId"))['c'];
    if ($activeBorrows >= 1 || $pendingReqs >= 1) {
        flash('msg', 'የውሰት ደንብ፦ በአንድ ጊዜ ከአንድ መጽሐፍ በላይ መዋስ አይፈቀድም። እባክዎ አስቀድመው የወሰዱትን መጽሐፍ ይመልሱ ወይም ቀደም ሲል የላኩትን ጥያቄ ይጠብቁ።', 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }

    $dupe = mysqli_query($conn, "SELECT id FROM borrow_requests WHERE member_id=$memberId AND book_id=$bookId AND status='pending'");
    if (mysqli_num_rows($dupe) > 0) {
        flash('msg', 'ለዚህ መጽሐፍ ቀደም ሲል ያስገቡት ጥያቄ በመጠባበቅ ላይ ነው።', 'warning');
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO borrow_requests (member_id, book_id, type) VALUES (?,?,?)");
        mysqli_stmt_bind_param($stmt, 'iis', $memberId, $bookId, $type);
        mysqli_stmt_execute($stmt);
        audit($conn, $user['id'], 'borrow_request_created', "book_id:$bookId type:$type");
        flash('msg', $type === 'reserve' ? 'የማስያዝ ጥያቄዎ ተልኳል። ዝግጁ ሲሆን እናሳውቅዎታለን።' : 'የመዋስ ጥያቄዎ ለቤተ-መጻሕፍት ኃላፊ ማረጋገጫ ተልኳል።', 'success');
    }
    redirect($_SERVER['REQUEST_URI']);
}

// ---- Filters ----
$q = clean($_GET['q'] ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$availability = clean($_GET['availability'] ?? '');
$borrowFilter = clean($_GET['borrowable'] ?? '');
$sort = clean($_GET['sort'] ?? '');

$where = ["b.borrow_status != 'archived'"];
$params = []; 
$types = '';

if ($q !== '') {
    $where[] = "(b.title LIKE ? OR b.author LIKE ? OR bc.copy_code LIKE ? OR bc.qr_identifier LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'ssss';
}
if ($categoryId > 0) { 
    $where[] = "b.category_id = ?"; 
    $params[] = $categoryId; 
    $types .= 'i'; 
}
if ($availability === 'available') { 
    $where[] = "(SELECT COUNT(*) FROM book_copies bc_chk WHERE bc_chk.book_id=b.id AND bc_chk.status='available') > 0"; 
} elseif ($availability === 'unavailable') {
    $where[] = "(SELECT COUNT(*) FROM book_copies bc_chk WHERE bc_chk.book_id=b.id AND bc_chk.status='available') = 0"; 
}

if ($borrowFilter === 'yes') {
    $where[] = "(b.is_borrowable = 1 AND b.borrow_status = 'available')";
} elseif ($borrowFilter === 'no') {
    $where[] = "(b.is_borrowable = 0 OR b.borrow_status = 'restricted')";
}

$whereSql = implode(' AND ', $where);
$orderSql = $sort === 'new' ? 'b.created_at DESC, b.id DESC' : 'b.title ASC';

// Total matching count
$countSql = "SELECT COUNT(DISTINCT b.id) AS total_count 
             FROM books b
             LEFT JOIN book_copies bc ON bc.book_id=b.id
             WHERE $whereSql";
$countStmt = mysqli_prepare($conn, $countSql);
if ($params) mysqli_stmt_bind_param($countStmt, $types, ...$params);
mysqli_stmt_execute($countStmt);
$totalRecords = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['total_count'];

// Pagination
$perPage = 48;
$page = max(1, (int)($_GET['p'] ?? 1));
$totalPages = max(1, ceil($totalRecords / $perPage));
$offset = ($page - 1) * $perPage;

$sql = "SELECT DISTINCT b.*, c.name AS category_name, c.icon AS category_icon, r.name AS room_name, s.name AS shelf_name,
        (SELECT COUNT(*) FROM book_copies bc2 WHERE bc2.book_id=b.id AND bc2.status='available') AS available_count,
        (SELECT GROUP_CONCAT(copy_code ORDER BY copy_code SEPARATOR ', ') FROM book_copies bc3 WHERE bc3.book_id=b.id) AS codes
        FROM books b
        LEFT JOIN categories c ON c.id=b.category_id
        LEFT JOIN rooms r ON r.id=b.room_id
        LEFT JOIN shelves s ON s.id=b.shelf_id
        LEFT JOIN book_copies bc ON bc.book_id=b.id
        WHERE $whereSql ORDER BY $orderSql LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $sql);
$mainTypes = $types . 'ii';
$mainParams = array_merge($params, [$perPage, $offset]);
mysqli_stmt_bind_param($stmt, $mainTypes, ...$mainParams);
mysqli_stmt_execute($stmt);
$results = mysqli_stmt_get_result($stmt);
$resultCount = mysqli_num_rows($results);

$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY name ASC");

$pageTitle = __('search');
$activeKey = 'browse';
include __DIR__ . '/includes/header.php';
?>

<form method="get" class="card card-pad mb-3">
  <div class="field" style="margin-bottom:10px;">
    <div class="input-group">
      <i class="bi bi-search"></i>
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="በመጽሐፍ ስም፣ ደራሲ፣ ኮድ ወይም QR መለያ ይፈልጉ…">
    </div>
  </div>
  <div class="row g-2">
    <div class="col-12 col-md-4">
      <select class="input" name="category">
        <option value="0">-- ሁሉም ምድቦች --</option>
        <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <select class="input" name="availability">
        <option value="">መገኘት፦ ሁሉም</option>
        <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>አሁን የሚገኙ ብቻ</option>
        <option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>አሁን የሌሉ</option>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <select class="input" name="borrowable">
        <option value="">ውሰት፦ ሁሉም</option>
        <option value="yes" <?= $borrowFilter === 'yes' ? 'selected' : '' ?>>ለመዋስ የሚፈቀዱ ብቻ</option>
        <option value="no" <?= $borrowFilter === 'no' ? 'selected' : '' ?>>ለመዋስ የማይፈቀዱ</option>
      </select>
    </div>
  </div>
  <button class="btn btn-navy btn-block mt-2"><i class="bi bi-search"></i> <?= __('search_btn') ?></button>
</form>

<div class="section-title d-flex justify-content-between align-items-center" style="margin-top:0;">
  <span>ጠቅላላ <?= number_format($totalRecords) ?> ውጤቶች ተገኝተዋል<?= $totalPages > 1 ? " (ገጽ $page ከ $totalPages)" : '' ?></span>
</div>

<?php if ($totalRecords === 0): ?>
  <div class="empty-state">
    <i class="bi bi-emoji-frown"></i>
    <h4><?= __('no_books_found') ?></h4>
    <p>ምንም ተመጣጣኝ ውጤት አልተገኘም። እባክዎ የፊደል አጻጻፉን አስተካክለው እንደገና ይሞክሩ።</p>
  </div>
<?php else: ?>
  <div class="row g-2 g-md-3">
    <?php while ($b = mysqli_fetch_assoc($results)): 
      $isBorrowable = is_book_borrowable($b) && $b['borrow_status'] !== 'restricted';
      $avail = (int)$b['available_count'];
      $sAuth = (!empty(trim($b['author'] ?? '')) && strtolower($b['author']) !== 'unwritten' && $b['author'] !== 'ጸሃፊው አልተገለጸም') ? $b['author'] : 'ጸሃፊው አልተገለጸም';
    ?>
      <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="<?= $base ?>book.php?id=<?= (int)$b['id'] ?>" class="book-card card-hover" style="text-decoration:none;display:flex;flex-direction:column;height:100%;">
          <div class="book-cover">
            <?= book_cover_html($b['cover_original'] ?: $b['cover_image']) ?>
          </div>
          <div class="book-body" style="flex:1;display:flex;flex-direction:column;justify-content:space-between;">
            <div>
              <div class="book-title"><?= e($b['title']) ?></div>
              <div class="book-author" style="<?= $sAuth === 'ጸሃፊው አልተገለጸም' ? 'font-style:italic;opacity:0.8;' : '' ?>"><?= e($sAuth) ?></div>
              <div class="text-muted" style="font-size:.7rem;margin-bottom:4px;"><?= e($b['category_name']) ?></div>
            </div>
            
            <div class="book-meta mt-1">
              <?php if ($isBorrowable): ?>
                <span class="badge badge-success" style="font-size:.65rem;"><i class="bi bi-check-circle"></i> ይዋሳል</span>
              <?php else: ?>
                <span class="badge badge-danger" style="font-size:.65rem;"><i class="bi bi-x-circle"></i> አይዋስም</span>
              <?php endif; ?>

              <?php if ($avail > 0): ?>
                <span class="badge badge-gold" style="font-size:.65rem;"><?= $avail ?> ቅጂ</span>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:.65rem;">ወጥቷል</span>
              <?php endif; ?>
            </div>
          </div>
        </a>
      </div>
    <?php endwhile; ?>
  </div>

  <!-- Pagination links -->
  <?php if ($totalPages > 1): 
    $pParams = $_GET;
  ?>
    <div class="d-flex justify-content-between align-items-center mt-3 mb-3">
      <div class="text-muted" style="font-size:.82rem;">
        ገጽ <?= $page ?> ከ <?= $totalPages ?> (ጠቅላላ <?= $totalRecords ?> መጻሕፍት)
      </div>
      <div class="d-flex gap-1">
        <?php if ($page > 1): 
          $pParams['p'] = $page - 1;
        ?>
          <a href="?<?= http_build_query($pParams) ?>" class="btn btn-outline btn-sm">
            <i class="bi bi-chevron-left"></i> ቀዳሚ
          </a>
        <?php endif; ?>
        <?php if ($page < $totalPages): 
          $pParams['p'] = $page + 1;
        ?>
          <a href="?<?= http_build_query($pParams) ?>" class="btn btn-navy btn-sm">
            ቀጣይ <i class="bi bi-chevron-right"></i>
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
