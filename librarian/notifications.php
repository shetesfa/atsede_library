<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$user = current_user();
$userId = (int)$user['id'];
mysqli_query($conn, "UPDATE notifications SET is_read=1 WHERE user_id=$userId");
$notifs = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id=$userId ORDER BY created_at DESC LIMIT 50");

$pageTitle = __('notifications');
$activeKey = 'notifications';
include __DIR__ . '/../includes/header.php';
?>
<div class="section-title" style="margin-top:0;"><?= __('notifications') ?></div>
<?php if (mysqli_num_rows($notifs) === 0): ?>
  <div class="empty-state"><i class="bi bi-bell-slash"></i><h4>እስካሁን ማሳወቂያ የለም</h4></div>
<?php else: while ($n = mysqli_fetch_assoc($notifs)): ?>
  <div class="card card-pad mb-2" style="display:flex;gap:12px;">
    <i class="bi bi-bell" style="font-size:1.1rem;color:var(--gold-600);margin-top:2px;"></i>
    <div>
      <div style="font-weight:700;color:var(--navy);font-size:.88rem;"><?= e($n['title']) ?></div>
      <div class="text-muted" style="font-size:.8rem;"><?= e($n['message']) ?></div>
      <div class="text-muted" style="font-size:.7rem;margin-top:4px;"><?= formatDate($n['created_at']) ?></div>
    </div>
  </div>
<?php endwhile; endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
