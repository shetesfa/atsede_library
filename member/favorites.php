<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$userId = (int)$user['id'];

// Handle remove from favorite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_favorite'])) {
    csrf_verify();
    $bookId = (int)$_POST['book_id'];
    mysqli_query($conn, "DELETE FROM favorites WHERE user_id=$userId AND book_id=$bookId");
    flash('msg', 'መጽሐፉ ከወደፊት የማነባቸው ዝርዝር ወጥቷል።', 'info');
    redirect('favorites.php');
}

$favorites = mysqli_query($conn, "
    SELECT b.*, c.name AS category_name,
      (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.id AND bc.status = 'available') AS available_count
    FROM favorites f
    JOIN books b ON b.id = f.book_id
    LEFT JOIN categories c ON c.id = b.category_id
    WHERE f.user_id = $userId
    ORDER BY f.created_at DESC
");

$pageTitle = __('favorites');
$activeKey = 'favorites';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">
  <i class="bi bi-bookmark-check-fill text-gold me-1"></i> ወደፊት የማነባቸው መጻሕፍት ዝርዝር
</div>

<?php if (mysqli_num_rows($favorites) === 0): ?>
  <div class="empty-state">
    <i class="bi bi-bookmark text-muted" style="font-size:2.5rem;"></i>
    <h4>እስካሁን ወደፊት የሚነበብ መጽሐፍ አልተመዘገበም</h4>
    <p>መጻሕፍትን ሲያዩ «ወደፊት የማነባቸው» የሚለውን ምልክት በመጫን እዚህ ማከማቸት ይችላሉ።</p>
    <a href="<?= $base ?>search.php" class="btn btn-gold btn-sm mt-2">መጻሕፍት ይፈልጉ</a>
  </div>
<?php else: ?>
  <div class="row g-2">
    <?php while ($b = mysqli_fetch_assoc($favorites)): 
      $isBorrowable = is_book_borrowable($b) && $b['borrow_status'] !== 'restricted' && $b['borrow_status'] !== 'archived';
      $avail = (int)$b['available_count'];
    ?>
      <div class="col-12 col-md-6">
        <div class="card card-pad" style="display:flex;gap:12px;align-items:center;">
          <div style="width:60px;height:80px;flex-shrink:0;">
            <?= book_cover_html($b['cover_original'] ?: $b['cover_image']) ?>
          </div>
          <div style="flex:1;min-width:0;">
            <a href="<?= $base ?>book.php?id=<?= (int)$b['id'] ?>" style="font-weight:700;color:var(--navy);text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
              <?= e($b['title']) ?>
            </a>
            <div class="text-muted" style="font-size:.78rem;margin-bottom:4px;"><?= e($b['author']) ?></div>
            <div class="d-flex gap-1 flex-wrap align-items-center">
              <span class="badge badge-navy" style="font-size:.7rem;"><?= e($b['category_name']) ?></span>
              <?php if ($avail > 0): ?>
                <span class="badge badge-success" style="font-size:.7rem;"><?= $avail ?> ይገኛል</span>
              <?php else: ?>
                <span class="badge badge-muted" style="font-size:.7rem;">አሁን የለም</span>
              <?php endif; ?>
            </div>
          </div>
          <div>
            <form method="post" style="margin:0;">
              <?= csrf_field() ?>
              <input type="hidden" name="book_id" value="<?= (int)$b['id'] ?>">
              <input type="hidden" name="remove_favorite" value="1">
              <button type="submit" class="btn btn-outline btn-sm" title="አስወግድ" style="border-radius:50%;width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-trash text-danger"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
