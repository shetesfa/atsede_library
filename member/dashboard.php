<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM members WHERE user_id=" . (int)$user['id']));
$memberId = $member['id'];

$activeBorrows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed'"))['c'] ?? 0;
$pendingRequests = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE member_id=$memberId AND status='pending'"))['c'] ?? 0;
$overdue = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed' AND due_date < CURDATE()"))['c'] ?? 0;
$totalHistory = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId"))['c'] ?? 0;

$current = mysqli_query($conn, "
  SELECT br.*, b.title, b.author, bc.copy_code FROM borrow_records br
  JOIN books b ON b.id=br.book_id JOIN book_copies bc ON bc.id=br.book_copy_id
  WHERE br.member_id=$memberId AND br.status='borrowed' ORDER BY br.due_date ASC LIMIT 4");

$pageTitle = __('home');
$activeKey = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="hero-banner">
  <h1 class="font-display">እንኳን ደህና መጡ፣ <?= e(explode(' ', $user['full_name'])[0]) ?></h1>
  <p>ክፍል <?= e($member['class'] ?: '—') ?> · ከ<?= formatDate($member['created_at']) ?> ጀምሮ አባል</p>
</div>

<div class="row g-2">
  <div class="col-6 col-md-3"><div class="stat-card gold"><div class="num"><?= $activeBorrows ?></div><div class="lbl">አሁን የተወሰዱ</div><i class="bi bi-journal-bookmark"></i></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="num"><?= $pendingRequests ?></div><div class="lbl">በመጠባበቅ ላይ ያሉ ጥያቄዎች</div><i class="bi bi-hourglass-split"></i></div></div>
  <div class="col-6 col-md-3"><div class="stat-card outline" style="<?= $overdue ? 'border-color:var(--danger);' : '' ?>"><div class="num" style="<?= $overdue ? 'color:var(--danger);' : '' ?>"><?= $overdue ?></div><div class="lbl">ጊዜው ያለፈ</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card outline"><div class="num"><?= $totalHistory ?></div><div class="lbl">ጠቅላላ የተወሰዱ</div></div></div>
</div>

<div class="section-title">
  በመያዝ ላይ ያሉ
  <a href="<?= $base ?>member/my_books.php" class="see-all"><?= __('see_all') ?> <i class="bi bi-arrow-right"></i></a>
</div>

<?php if (mysqli_num_rows($current) === 0): ?>
  <div class="empty-state">
    <i class="bi bi-journal-x"></i>
    <h4>እስካሁን የተወሰደ መጽሐፍ የለም</h4>
    <p>ለመጀመር መጽሐፍ ይፈልጉ።</p>
    <a href="<?= $base ?>search.php" class="btn btn-gold">ካታሎግ ይዩ</a>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="app-table app-stack">
      <thead><tr><th>መጽሐፍ</th><th>ኮድ</th><th>የመመለሻ ቀን</th></tr></thead>
      <tbody>
        <?php while ($r = mysqli_fetch_assoc($current)): $isOverdue = strtotime($r['due_date']) < strtotime('today'); ?>
        <tr>
          <td data-label="መጽሐፍ"><strong><?= e($r['title']) ?></strong><br><span class="text-muted" style="font-size:.76rem;"><?= e($r['author']) ?></span></td>
          <td data-label="ኮድ"><span class="shelf-tag"><?= e($r['copy_code']) ?></span></td>
          <td data-label="የመመለሻ ቀን"><span class="badge <?= $isOverdue ? 'badge-danger' : 'badge-success' ?>"><?= formatDate($r['due_date']) ?></span></td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="section-title">አጭር መንገዶች</div>
<div class="row g-2">
  <div class="col-6"><a href="<?= $base ?>search.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-search" style="font-size:1.4rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">መጻሕፍት ይዩ</div></a></div>
  <div class="col-6"><a href="<?= $base ?>suggest_book.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-lightbulb" style="font-size:1.4rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">መጽሐፍ ይጠቁሙ</div></a></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
