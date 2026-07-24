<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM members WHERE user_id=" . (int)$user['id']));
$memberId = $member['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request_id'])) {
    csrf_verify();
    $rid = (int)$_POST['cancel_request_id'];
    mysqli_query($conn, "UPDATE borrow_requests SET status='cancelled' WHERE id=$rid AND member_id=$memberId AND status='pending'");
    flash('msg', 'ጥያቄው ተሰርዟል።', 'success');
    redirect('my_books.php');
}

$tab = $_GET['tab'] ?? 'current';

$pending = mysqli_query($conn, "
  SELECT rq.*, b.title, b.author FROM borrow_requests rq JOIN books b ON b.id=rq.book_id
  WHERE rq.member_id=$memberId AND rq.status='pending' ORDER BY rq.requested_at DESC");

$current = mysqli_query($conn, "
  SELECT br.*, b.title, b.author, bc.copy_code FROM borrow_records br
  JOIN books b ON b.id=br.book_id JOIN book_copies bc ON bc.id=br.book_copy_id
  WHERE br.member_id=$memberId AND br.status='borrowed' ORDER BY br.due_date ASC");

$history = mysqli_query($conn, "
  SELECT br.*, b.title, b.author, bc.copy_code FROM borrow_records br
  JOIN books b ON b.id=br.book_id JOIN book_copies bc ON bc.id=br.book_copy_id
  WHERE br.member_id=$memberId AND br.status != 'borrowed' ORDER BY br.borrowed_at DESC LIMIT 50");

$pageTitle = __('my_books');
$typeLabels = ['borrow'=>'መዋስ','reserve'=>'ማስያዝ'];
$recordStatusLabels = ['returned'=>'ተመልሷል','lost'=>'ጠፍቷል','overdue'=>'ጊዜው ያለፈ'];
$activeKey = 'mybooks';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;"><?= __('my_books') ?></div>

<div style="display:flex;gap:8px;margin-bottom:16px;overflow-x:auto;">
  <a href="?tab=current" class="btn <?= $tab==='current'?'btn-navy':'btn-outline' ?> btn-sm">በመያዝ ላይ</a>
  <a href="?tab=pending" class="btn <?= $tab==='pending'?'btn-navy':'btn-outline' ?> btn-sm"><?= __('pending') ?> (<?= mysqli_num_rows($pending) ?>)</a>
  <a href="?tab=history" class="btn <?= $tab==='history'?'btn-navy':'btn-outline' ?> btn-sm">ታሪክ</a>
</div>

<?php if ($tab === 'pending'): ?>
  <?php if (mysqli_num_rows($pending) === 0): ?>
    <div class="empty-state"><i class="bi bi-hourglass"></i><h4>በመጠባበቅ ላይ ያለ ጥያቄ የለም</h4></div>
  <?php else: while ($r = mysqli_fetch_assoc($pending)): ?>
    <div class="card card-pad mb-2" style="display:flex;align-items:center;gap:12px;">
      <div style="flex:1;">
        <strong><?= e($r['title']) ?></strong><br>
        <span class="text-muted" style="font-size:.78rem;"><?= e($r['author']) ?> · የተጠየቀው <?= formatDate($r['requested_at']) ?></span><br>
        <span class="badge badge-warning mt-1"><?= e($typeLabels[$r['type']] ?? $r['type']) ?> · <?= __('pending') ?></span>
      </div>
      <form method="post" onsubmit="return confirmAction('ይህን ጥያቄ መሰረዝ ይፈልጋሉ?', this)">
        <?= csrf_field() ?>
        <input type="hidden" name="cancel_request_id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-outline btn-sm"><i class="bi bi-x"></i> ይቅር</button>
      </form>
    </div>
  <?php endwhile; endif; ?>

<?php elseif ($tab === 'history'): ?>
  <?php if (mysqli_num_rows($history) === 0): ?>
    <div class="empty-state"><i class="bi bi-clock-history"></i><h4>እስካሁን ታሪክ የለም</h4></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="app-table app-stack">
        <thead><tr><th>መጽሐፍ</th><th>ኮድ</th><th>የተወሰደበት ቀን</th><th>ሁኔታ</th></tr></thead>
        <tbody>
          <?php while ($r = mysqli_fetch_assoc($history)): ?>
          <tr>
            <td data-label="መጽሐፍ"><strong><?= e($r['title']) ?></strong></td>
            <td data-label="ኮድ"><span class="shelf-tag"><?= e($r['copy_code']) ?></span></td>
            <td data-label="የተወሰደበት ቀን"><?= formatDate($r['borrowed_at']) ?></td>
            <td data-label="Status"><span class="badge <?= $r['status']==='returned'?'badge-success':'badge-danger' ?>"><?= ucfirst($r['status']) ?></span></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php if (mysqli_num_rows($current) === 0): ?>
    <div class="empty-state"><i class="bi bi-journal-x"></i><h4>አሁን የተወሰደ መጽሐፍ የለም</h4><a href="<?= $base ?>search.php" class="btn btn-gold mt-2">ካታሎግ ይዩ</a></div>
  <?php else: while ($r = mysqli_fetch_assoc($current)): $isOverdue = strtotime($r['due_date']) < strtotime('today'); ?>
    <div class="card card-pad mb-2">
      <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;">
        <div>
          <strong><?= e($r['title']) ?></strong><br>
          <span class="text-muted" style="font-size:.78rem;"><?= e($r['author']) ?></span>
        </div>
        <span class="shelf-tag"><?= e($r['copy_code']) ?></span>
      </div>
      <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;">
        <span class="badge <?= $isOverdue ? 'badge-danger' : 'badge-success' ?>"><i class="bi bi-calendar-event"></i> የመመለሻ <?= formatDate($r['due_date']) ?></span>
        <?php if ($isOverdue): ?><span class="text-muted" style="font-size:.74rem;color:var(--danger);">እባክዎ በቅርቡ ይመልሱ</span><?php endif; ?>
      </div>
    </div>
  <?php endwhile; endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
