<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$bookId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    require_role('member');
    if (is_member_blocked($conn, (int)$user['id'])) {
        flash('msg', member_block_message($conn, (int)$user['id']), 'danger');
        redirect('book.php?id=' . $bookId);
    }
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
        flash('msg', $type === 'reserve' ? 'የማስያዝ ጥያቄዎ ተልኳል።' : 'የመዋስ ጥያቄዎ ለማረጋገጫ ተልኳል።', 'success');
    }
    redirect('book.php?id=' . $bookId);
}

$stmt = mysqli_prepare($conn, "SELECT b.*, c.name AS category_name, r.name AS room_name, s.name AS shelf_name
    FROM books b LEFT JOIN categories c ON c.id=b.category_id
    LEFT JOIN rooms r ON r.id=b.room_id LEFT JOIN shelves s ON s.id=b.shelf_id
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

$copies = mysqli_query($conn, "SELECT * FROM book_copies WHERE book_id=$bookId ORDER BY copy_code ASC");
$availableCount = 0; $codesList = [];
mysqli_data_seek($copies, 0);
while ($c = mysqli_fetch_assoc($copies)) { if ($c['status'] === 'available') $availableCount++; $codesList[] = $c; }

$pageTitle = $book['title'];
$activeKey = 'browse';
include __DIR__ . '/includes/header.php';
?>

<a href="javascript:history.back()" class="text-muted" style="font-size:.85rem;display:inline-flex;align-items:center;gap:4px;margin-bottom:10px;"><i class="bi bi-arrow-left"></i> <?= __('back') ?></a>

<div class="card" style="overflow:hidden;">
  <div class="row g-0">
    <div class="col-4 col-md-3">
      <div class="book-cover" style="aspect-ratio:3/4;width:100%;max-width:200px;margin:0 auto;">
        <?= book_cover_html($book['cover_image']) ?>
      </div>
    </div>
    <div class="col-8 col-md-9">
      <div class="card-pad">
        <span class="badge <?= borrow_status_class($book['borrow_status']) ?> mb-2"><?= borrow_status_label($book['borrow_status']) ?></span>
        <h2 class="font-display" style="font-size:1.2rem;color:var(--navy);margin:0 0 4px;"><?= e($book['title']) ?></h2>
        <div class="text-muted" style="font-size:.88rem;margin-bottom:6px;">በ <?= e($book['author']) ?></div>
        <div class="d-flex flex-wrap gap-2" style="margin-bottom:8px;">
          <span class="badge badge-navy"><?= e($book['category_name']) ?></span>
          <?php foreach ($codesList as $c): ?>
            <span class="shelf-tag"><i class="bi bi-tag"></i><?= e($c['copy_code']) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="text-muted" style="font-size:.8rem;">
          <i class="bi bi-geo-alt"></i> <?= e($book['room_name'] ?: 'አልተመደበም') ?><?= $book['shelf_name'] ? ' · ' . e($book['shelf_name']) : '' ?><?= $book['position'] ? ' · ' . e($book['position']) : '' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $msg = borrow_status_message($book['borrow_status']); ?>
<?php if ($msg): ?>
  <div class="card card-pad mt-3" style="border-left:4px solid var(--warning);">
    <i class="bi bi-info-circle" style="color:var(--warning);"></i> <?= e($msg) ?>
  </div>
<?php endif; ?>

<div class="section-title">የውሰት ዝግጁነት</div>
<div class="row g-2">
  <div class="col-6"><div class="stat-card outline"><div class="num"><?= (int)$book['quantity'] ?></div><div class="lbl">ጠቅላላ ቅጂዎች</div></div></div>
  <div class="col-6"><div class="stat-card gold"><div class="num"><?= $availableCount ?></div><div class="lbl">አሁን ይገኛል</div></div></div>
</div>

<?php if ($book['description']): ?>
<div class="section-title">መግለጫ</div>
<div class="card card-pad"><p style="margin:0;font-size:.9rem;line-height:1.6;"><?= nl2br(e($book['description'])) ?></p></div>
<?php endif; ?>

<div class="section-title">ዝርዝር መረጃ</div>
<div class="card">
  <table class="app-table app-stack" style="width:100%;">
    <tbody>
      <tr><td data-label="አሳታሚ"><?= e($book['publisher'] ?: '—') ?></td></tr>
      <tr><td data-label="ዓመት"><?= e($book['publication_year'] ?: '—') ?></td></tr>
      <tr><td data-label="ዋጋ"><?= $book['price'] ? number_format($book['price'],2) . ' ብር' : '—' ?></td></tr>
    </tbody>
  </table>
</div>

<?php if ($role === 'member' && $book['borrow_status'] !== 'archived'): ?>
  <form method="post" class="mt-3" style="display:flex;gap:10px;">
    <?= csrf_field() ?>
    <input type="hidden" name="book_id" value="<?= (int)$book['id'] ?>">
    <?php if ($book['borrow_status'] === 'available' && $availableCount > 0): ?>
      <button name="action" value="borrow" class="btn btn-gold btn-block"><i class="bi bi-journal-plus"></i> ለመዋስ ይጠይቁ</button>
    <?php elseif ($book['borrow_status'] === 'available' && $availableCount === 0): ?>
      <button name="action" value="reserve" class="btn btn-navy btn-block"><i class="bi bi-bookmark-plus"></i> ያስያዙ (ሁሉም ቅጂዎች ወጥቷል)</button>
    <?php endif; ?>
  </form>
<?php elseif ($role === 'guest'): ?>
  <a href="<?= $base ?>login.php" class="btn btn-navy btn-block mt-3"><i class="bi bi-box-arrow-in-right"></i> ይህን መጽሐፍ ለመዋስ ይግቡ</a>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
