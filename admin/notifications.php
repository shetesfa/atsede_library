<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title = clean($_POST['title'] ?? '');
    $message = clean($_POST['message'] ?? '');
    $target = $_POST['target'] ?? 'all';

    if ($title === '' || $message === '') {
        flash('msg', 'አርእስት እና መልዕክት ያስፈልጋሉ።', 'danger');
    } else {
        if ($target === 'all') {
            notify_broadcast($conn, $title, $message, 'general');
            audit($conn, $user['id'], 'notification_broadcast', $title);
            flash('msg', 'ማሳወቂያ ለሁሉም አባላት ተልኳል።', 'success');
        } else {
            $memberUserId = (int)$target;
            notify($conn, $memberUserId, $title, $message, 'general');
            audit($conn, $user['id'], 'notification_direct', "to:$memberUserId title:$title");
            flash('msg', 'ማሳወቂያ ተልኳል።', 'success');
        }
    }
    redirect('notifications.php');
}

$members = mysqli_query($conn, "SELECT id, full_name FROM users WHERE role='member' AND status='active' ORDER BY full_name ASC");
$sent = mysqli_query($conn, "SELECT * FROM notifications ORDER BY created_at DESC LIMIT 20");

$pageTitle = __('send_notice');
$activeKey = 'notifications_send';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">ማሳወቂያ ይጻፉ</div>
<form method="post" class="card card-pad mb-3">
  <?= csrf_field() ?>
  <div class="field">
    <label>ለማን ይላክ</label>
    <select class="input" name="target">
      <option value="all">ሁሉም አባላት</option>
      <?php mysqli_data_seek($members, 0); while ($m = mysqli_fetch_assoc($members)): ?>
        <option value="<?= (int)$m['id'] ?>"><?= e($m['full_name']) ?></option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="field"><label>አርእስት</label><input class="input" name="title" required></div>
  <div class="field"><label>መልዕክት</label><textarea class="input" name="message" rows="3" required></textarea></div>
  <button class="btn btn-gold btn-block"><i class="bi bi-send"></i> አሁን ይላኩ</button>
</form>

<div class="section-title">ቅርብ ጊዜ የተላኩ</div>
<?php if (mysqli_num_rows($sent) === 0): ?>
  <div class="empty-state"><i class="bi bi-megaphone"></i><h4>እስካሁን ምንም አልተላከም</h4></div>
<?php else: while ($n = mysqli_fetch_assoc($sent)): ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;">
      <strong><?= e($n['title']) ?></strong>
      <span class="badge <?= $n['user_id'] ? 'badge-muted' : 'badge-gold' ?>"><?= $n['user_id'] ? 'ለግል' : 'ለሁሉም' ?></span>
    </div>
    <div class="text-muted" style="font-size:.82rem;"><?= e($n['message']) ?></div>
    <div class="text-muted" style="font-size:.7rem;margin-top:4px;"><?= formatDate($n['created_at']) ?></div>
  </div>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
