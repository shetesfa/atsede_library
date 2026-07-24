<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

$user = current_user();
$role = $user['role'] ?? 'guest';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $bookName = clean($_POST['book_name'] ?? '');
    $author = clean($_POST['author'] ?? '');
    $reason = clean($_POST['reason'] ?? '');
    $guestName = clean($_POST['guest_name'] ?? '');
    $guestPhone = clean($_POST['guest_phone'] ?? '');

    if ($bookName === '') {
        $error = 'እባክዎ የመጽሐፍ ስም ይግለጹ።';
    } elseif ($role === 'guest' && $guestName === '') {
        $error = 'እባክዎ ስምዎን ይግለጹ።';
    } else {
        $existing = mysqli_query($conn, "SELECT id, total_requests FROM book_suggestions WHERE LOWER(book_name)='" . mysqli_real_escape_string($conn, strtolower($bookName)) . "' AND status='pending' LIMIT 1");
        $row = mysqli_fetch_assoc($existing);

        if ($row) {
            mysqli_query($conn, "UPDATE book_suggestions SET total_requests = total_requests + 1 WHERE id=" . (int)$row['id']);
        } else {
            $memberId = null;
            if ($role === 'member') {
                $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id=" . (int)$user['id']));
                $memberId = $m['id'];
            }
            $stmt = mysqli_prepare($conn, "INSERT INTO book_suggestions (member_id, guest_name, guest_phone, book_name, author, reason) VALUES (?,?,?,?,?,?)");
            mysqli_stmt_bind_param($stmt, 'isssss', $memberId, $guestName, $guestPhone, $bookName, $author, $reason);
            mysqli_stmt_execute($stmt);
        }
        $success = true;
    }
}

$pageTitle = 'መጽሐፍ ይጠይቁ';
$activeKey = 'suggest';
include __DIR__ . '/includes/header.php';
?>

<?php if ($success): ?>
  <div class="empty-state">
    <i class="bi bi-check-circle" style="color:var(--success);"></i>
    <h4>ጥያቄ ተልኳል</h4>
    <p>ቤተ መጻሕፍቱ ቡድን ጥያቄዎን በቅርቡ ይመለከታል። ስብስባችንን ለማበልጸግ ስለረዱን እናመስግናለን።</p>
    <a href="<?= $base ?>search.php" class="btn btn-navy"><?= __("back") ?></a>
  </div>
<?php else: ?>

<div class="section-title" style="margin-top:0;">መጽሐፍ ይጠይቁ</div>
<?php if (!empty($error)): ?><div class="card card-pad mb-3" style="border-left:4px solid var(--danger);color:var(--danger);"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="card card-pad">
  <?= csrf_field() ?>
  <?php if ($role === 'guest'): ?>
    <div class="field"><label><?= __("full_name") ?></label><input class="input" name="guest_name" required></div>
    <div class="field"><label><?= __("phone") ?> <span class="text-muted">(አማራጭ)</span></label><input class="input" name="guest_phone"></div>
  <?php endif; ?>
  <div class="field"><label><?= __("book_name") ?></label><input class="input" name="book_name" value="<?= e($_GET['name'] ?? '') ?>" required></div>
  <div class="field"><label><?= __("author") ?> <span class="text-muted">(አማራጭ)</span></label><input class="input" name="author"></div>
  <div class="field"><label>ለምን ይህን መጽሐፍ ይፈልጋሉ?</label><textarea class="input" name="reason" rows="3"></textarea></div>
  <button class="btn btn-gold btn-block"><i class="bi bi-send"></i> ጥያቄ ይላኩ</button>
</form>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
