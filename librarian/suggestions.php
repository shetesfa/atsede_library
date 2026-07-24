<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)$_POST['suggestion_id'];
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['approved','rejected','purchased'])) {
        $sugg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.*, u.id AS member_user_id FROM book_suggestions s LEFT JOIN members m ON m.id=s.member_id LEFT JOIN users u ON u.id=m.user_id WHERE s.id=$id"));
        mysqli_query($conn, "UPDATE book_suggestions SET status='$status' WHERE id=$id");
        if ($sugg && $sugg['member_user_id'] && $status === 'approved') {
            notify($conn, $sugg['member_user_id'], 'የመጽሐፍ ጥቆማ ጸድቷል', '"' . $sugg['book_name'] . '" ጸድቶ ወደ ቤተ መጻሕፍቱ ይታከላል።', 'suggestion_approved', 'member/suggestions.php');
        } elseif ($sugg && $sugg['member_user_id'] && $status === 'purchased') {
            notify($conn, $sugg['member_user_id'], 'መጽሐፉ ቤተ መጻሕፍት ገብቷል', '"' . $sugg['book_name'] . '" ተገዝቶ ታክሏል። ይመልከቱት!', 'suggestion_approved', 'search.php');
        }
        audit($conn, $user['id'], 'suggestion_' . $status, "suggestion_id:$id");
        flash('msg', 'ዘምኗል።', 'success');
    }
    redirect('suggestions.php');
}

$filter = $_GET['status'] ?? 'pending';
$where = $filter === 'all' ? '1=1' : "s.status='" . mysqli_real_escape_string($conn, $filter) . "'";
$suggestions = mysqli_query($conn, "
  SELECT s.*, COALESCE(u.full_name, s.guest_name) AS requester FROM book_suggestions s
  LEFT JOIN members m ON m.id=s.member_id LEFT JOIN users u ON u.id=m.user_id
  WHERE $where ORDER BY s.total_requests DESC, s.created_at DESC");

$statusClass = ['pending'=>'badge-warning','approved'=>'badge-success','rejected'=>'badge-danger','purchased'=>'badge-gold'];

$pageTitle = __('suggestions');
$activeKey = 'suggestions';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;gap:8px;margin-bottom:14px;overflow-x:auto;">
  <?php foreach (['pending'=>__('pending'),'approved'=>__('approved'),'purchased'=>__('purchased'),'rejected'=>__('rejected'),'all'=>'ሁሉም'] as $k=>$lbl): ?>
    <a href="?status=<?= $k ?>" class="btn <?= $filter===$k?'btn-navy':'btn-outline' ?> btn-sm"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (mysqli_num_rows($suggestions) === 0): ?>
  <div class="empty-state"><i class="bi bi-lightbulb"></i><h4>ምንም የለም</h4></div>
<?php else: while ($s = mysqli_fetch_assoc($suggestions)): ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;gap:10px;">
      <div>
        <strong><?= e($s['book_name']) ?></strong><br>
        <span class="text-muted" style="font-size:.8rem;"><?= e($s['author'] ?: 'ደራሲ ያልታወቀ') ?> · የጠየቀው <?= e($s['requester'] ?: 'እንግዳ') ?></span>
      </div>
      <span class="badge <?= $statusClass[$s['status']] ?? 'badge-muted' ?>"><?= (int)$s['total_requests'] ?>x</span>
    </div>
    <?php if ($s['reason']): ?><p class="text-muted" style="font-size:.82rem;margin:8px 0;">"<?= e($s['reason']) ?>"</p><?php endif; ?>
    <?php if ($s['status'] === 'pending'): ?>
    <div style="display:flex;gap:8px;margin-top:8px;">
      <form method="post" style="flex:1;"><?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="approved"><button class="btn btn-success btn-block btn-sm"><?= __("approve") ?></button></form>
      <form method="post" style="flex:1;"><?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="purchased"><button class="btn btn-gold btn-block btn-sm"><?= __("purchased") ?></button></form>
      <form method="post" style="flex:1;"><?= csrf_field() ?><input type="hidden" name="suggestion_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="status" value="rejected"><button class="btn btn-outline btn-block btn-sm" style="color:var(--danger);border-color:var(--danger);"><?= __("reject") ?></button></form>
    </div>
    <?php endif; ?>
  </div>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
