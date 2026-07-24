<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$userId = (int)$user['id'];

mysqli_query($conn, "UPDATE notifications SET is_read=1 WHERE (user_id=$userId OR user_id IS NULL)");

$notifs = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=$userId OR user_id IS NULL ORDER BY created_at DESC LIMIT 50");

$icons = ['borrow_approved'=>'bi-check-circle','borrow_rejected'=>'bi-x-circle','new_book'=>'bi-book','registration_approved'=>'bi-person-check','suggestion_approved'=>'bi-lightbulb','general'=>'bi-bell'];
$colors = ['borrow_approved'=>'var(--success)','borrow_rejected'=>'var(--danger)','new_book'=>'var(--gold-600)','registration_approved'=>'var(--success)','suggestion_approved'=>'var(--gold-600)','general'=>'var(--navy)'];

$pageTitle = __('notifications');
$activeKey = 'notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;"><?= __('notifications') ?></div>

<?php if (mysqli_num_rows($notifs) === 0): ?>
  <div class="empty-state"><i class="bi bi-bell-slash"></i><h4>እስካሁን ማሳወቂያ የለም</h4><p>ነገር ሲኖር እናሳውቅዎታለን።</p></div>
<?php else: while ($n = mysqli_fetch_assoc($notifs)): ?>
  <a href="<?= $n['link'] ? $base . e($n['link']) : '#' ?>" class="card card-pad mb-2" style="display:flex;gap:12px;text-decoration:none;">
    <i class="bi <?= e($icons[$n['type']] ?? 'bi-bell') ?>" style="font-size:1.2rem;color:<?= $colors[$n['type']] ?? 'var(--navy)' ?>;margin-top:2px;"></i>
    <div style="flex:1;">
      <div style="font-weight:700;color:var(--navy);font-size:.88rem;"><?= e($n['title']) ?></div>
      <div class="text-muted" style="font-size:.8rem;"><?= e($n['message']) ?></div>
      <div class="text-muted" style="font-size:.7rem;margin-top:4px;"><?= formatDate($n['created_at']) ?></div>
    </div>
  </a>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
