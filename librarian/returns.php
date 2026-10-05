<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_once __DIR__ . '/../includes/LibraryService.php';
require_role(['librarian','admin']);

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $recordId = (int)($_POST['record_id'] ?? 0);
    $action = $_POST['action'] ?? 'returned';

    $rec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT book_copy_id FROM borrow_records WHERE id = $recordId"));
    if ($rec) {
        $result = return_copy($conn, (int)$rec['book_copy_id'], $action, '', (int)$user['id']);
        if ($result['success']) {
            flash('msg', $result['message'], 'success');
        } else {
            flash('msg', $result['message'], 'danger');
        }
    } else {
        flash('msg', 'የውሰት መዝገቡ አልተገኘም።', 'warning');
    }
    redirect('returns.php');
}

$q = clean($_GET['q'] ?? '');
$where = "br.status='borrowed'";
if ($q !== '') {
    $like = mysqli_real_escape_string($conn, $q);
    $where .= " AND (b.title LIKE '%$like%' OR u.full_name LIKE '%$like%' OR bc.copy_code LIKE '%$like%')";
}

$records = mysqli_query($conn, "
  SELECT br.*, b.title, b.author, bc.copy_code, u.full_name, u.phone FROM borrow_records br
  JOIN books b ON b.id=br.book_id JOIN book_copies bc ON bc.id=br.book_copy_id
  JOIN members m ON m.id=br.member_id JOIN users u ON u.id=m.user_id
  WHERE $where ORDER BY br.due_date ASC");

$pageTitle = __('returns');
$activeKey = 'returns';
include __DIR__ . '/../includes/header.php';
?>

<form method="get" class="card card-pad mb-3">
  <div class="input-group"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($q) ?>" placeholder="በመጽሐፍ፣ በአባል ወይም በኮድ ይፈልጉ…"></div>
</form>

<div class="section-title" style="margin-top:0;">በስራ ላይ ያሉ ውሶች</div>

<?php if (mysqli_num_rows($records) === 0): ?>
  <div class="empty-state"><i class="bi bi-inbox"></i><h4>ምንም የተወሰደ መጽሐፍ የለም</h4></div>
<?php else: while ($r = mysqli_fetch_assoc($records)): $isOverdue = strtotime($r['due_date']) < strtotime('today'); ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;gap:10px;">
      <div>
        <strong><?= e($r['title']) ?></strong> <span class="shelf-tag"><?= e($r['copy_code']) ?></span><br>
        <span class="text-muted" style="font-size:.8rem;"><i class="bi bi-person"></i> <?= e($r['full_name']) ?> · <?= e($r['phone']) ?></span>
      </div>
      <span class="badge <?= $isOverdue ? 'badge-danger' : 'badge-success' ?>">የመመለሻ <?= formatDate($r['due_date']) ?></span>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <form method="post" style="flex:1;">
        <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="action" value="returned">
        <button class="btn btn-success btn-block btn-sm"><i class="bi bi-check-lg"></i> ተመልሷል</button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="action" value="damaged">
        <button class="btn btn-outline btn-sm" style="color:var(--warning);border-color:var(--warning);" onclick="return confirmAction('ይህን ቅጂ እንደተጎዳ ምልክት ማድረግ ይፈልጋሉ?', this.form)" type="button"><i class="bi bi-tools"></i></button>
      </form>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="action" value="lost">
        <button class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);" onclick="return confirmAction('ይህን ቅጂ እንደጠፋ ምልክት ማድረግ ይፈልጋሉ?', this.form)" type="button"><i class="bi bi-exclamation-triangle"></i></button>
      </form>
    </div>
  </div>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
