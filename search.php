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

    $memberRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
    $memberId = $memberRow['id'];

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
$sort = clean($_GET['sort'] ?? '');

$where = ["b.borrow_status != 'archived'"];
$params = []; $types = '';

if ($q !== '') {
    $where[] = "(b.title LIKE ? OR b.author LIKE ? OR bc.copy_code LIKE ?)";
    $like = "%$q%";
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
if ($categoryId > 0) { $where[] = "b.category_id = ?"; $params[] = $categoryId; $types .= 'i'; }
if ($availability === 'available') { $where[] = "b.borrow_status = 'available'"; }

$whereSql = implode(' AND ', $where);
$orderSql = $sort === 'new' ? 'b.created_at DESC' : 'b.title ASC';

$sql = "SELECT DISTINCT b.*, c.name AS category_name, c.icon AS category_icon, r.name AS room_name, s.name AS shelf_name,
        (SELECT COUNT(*) FROM book_copies bc2 WHERE bc2.book_id=b.id AND bc2.status='available') AS available_count,
        (SELECT GROUP_CONCAT(copy_code ORDER BY copy_code SEPARATOR ', ') FROM book_copies bc3 WHERE bc3.book_id=b.id) AS codes
        FROM books b
        LEFT JOIN categories c ON c.id=b.category_id
        LEFT JOIN rooms r ON r.id=b.room_id
        LEFT JOIN shelves s ON s.id=b.shelf_id
        LEFT JOIN book_copies bc ON bc.book_id=b.id
        WHERE $whereSql ORDER BY $orderSql LIMIT 60";

$stmt = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
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
      <input class="input" name="q" value="<?= e($q) ?>" placeholder="በመጽሐፍ ስም፣ ደራሲ ወይም የመደርደሪያ ኮድ ይፈልጉ…">
    </div>
  </div>
  <div class="row g-2">
    <div class="col-6">
      <select class="input" name="category">
        <option value="0">ሁሉንም ምድቦች</option>
        <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $categoryId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="col-6">
      <select class="input" name="availability">
        <option value="">ምንም ይሁን</option>
        <option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>አሁን ይገኛል</option>
      </select>
    </div>
  </div>
  <button class="btn btn-navy btn-block mt-2"><i class="bi bi-search"></i> <?= __('search_btn') ?></button>
</form>

<div class="section-title" style="margin-top:0;">
  <?= $resultCount ?> ውጤት<?= $resultCount === 1 ? '' : 'ዎች' ?> ተገኝቷል
</div>

<?php if ($resultCount === 0): ?>
  <div class="empty-state">
    <i class="bi bi-emoji-frown"></i>
    <h4><?= __('no_books_found') ?></h4>
    <p>ምንም ተመጣጣኝ ውጤት አልተገኘም። የሚፈልጉትን ይንገሩን፤ ቤተ መጻሕፍቱ ሊያካተተው ይችላል።</p>
    <a href="<?= $base ?>suggest_book.php?name=<?= urlencode($q) ?>" class="btn btn-gold"><?= __('request_this_book') ?></a>
  </div>
<?php else: ?>
  <div class="row g-2 g-md-3">
    <?php while ($b = mysqli_fetch_assoc($results)): ?>
      <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="<?= $base ?>book.php?id=<?= (int)$b['id'] ?>" class="book-card card-hover" style="text-decoration:none;">
          <div class="book-cover">
            <?= book_cover_html($b['cover_image']) ?>
          </div>
          <div class="book-body">
            <div class="book-title"><?= e($b['title']) ?></div>
            <div class="book-author"><?= e($b['author']) ?></div>
            <div class="text-muted" style="font-size:.7rem;"><?= e($b['category_name']) ?> · <?= e($b['room_name'] ?: '—') ?><?= $b['shelf_name'] ? ' / '.e($b['shelf_name']) : '' ?></div>
            <div class="book-meta">
              <span class="badge <?= borrow_status_class($b['borrow_status']) ?>"><?= borrow_status_label($b['borrow_status']) ?></span>
              <span class="shelf-tag"><i class="bi bi-tag"></i><?= e(strtok($b['codes'] ?: '—', ',')) ?></span>
            </div>
          </div>
        </a>
      </div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
