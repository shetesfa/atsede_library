<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
$suggestions = mysqli_query($conn, "SELECT * FROM book_suggestions WHERE member_id=" . (int)$member['id'] . " ORDER BY created_at DESC");

$statusClass = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger','purchased'=>'badge-gold'];

$pageTitle = 'የእኔ ጥቆማዎች';
$activeKey = 'suggest';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">
  የእኔ ጥቆማዎች
  <a href="<?= $base ?>suggest_book.php" class="see-all">አዲስ ጥያቄ <i class="bi bi-plus-lg"></i></a>
</div>

<?php if (mysqli_num_rows($suggestions) === 0): ?>
  <div class="empty-state"><i class="bi bi-lightbulb"></i><h4>እስካሁን ጥቆማ የለም</h4><a href="<?= $base ?>suggest_book.php" class="btn btn-gold mt-2">መጽሐፍ ይጠቁሙ</a></div>
<?php else: while ($s = mysqli_fetch_assoc($suggestions)): ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;gap:10px;">
      <strong><?= e($s['book_name']) ?></strong>
      <span class="badge <?= $statusClass[$s['status']] ?? 'badge-muted' ?>"><?= e([
        'pending'=>__('pending'),'approved'=>__('approved'),'rejected'=>__('rejected'),'purchased'=>__('purchased')
      ][$s['status']] ?? $s['status']) ?></span>
    </div>
    <div class="text-muted" style="font-size:.8rem;"><?= e($s['author'] ?: 'ደራሲ ያልታወቀ') ?> · <?= (int)$s['total_requests'] ?> ጥያቄ<?= $s['total_requests']>1?'ዎች':'' ?> · <?= formatDate($s['created_at']) ?></div>
  </div>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
