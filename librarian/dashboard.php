<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$totalBooks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books"))['c'];
$totalCopies = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies"))['c'];
$availableCopies = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='available'"))['c'];
$borrowedCopies = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='borrowed'"))['c'];
$pendingRequests = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE status='pending'"))['c'];
$pendingSuggestions = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_suggestions WHERE status='pending'"))['c'];
$totalMembers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM members m JOIN users u ON u.id=m.user_id WHERE u.status='active'"))['c'];
$overdue = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE status='borrowed' AND due_date < CURDATE()"))['c'];

$recentRequests = mysqli_query($conn, "
  SELECT rq.*, b.title, u.full_name FROM borrow_requests rq
  JOIN books b ON b.id=rq.book_id JOIN members m ON m.id=rq.member_id JOIN users u ON u.id=m.user_id
  WHERE rq.status='pending' ORDER BY rq.requested_at ASC LIMIT 5");

$pageTitle = __('dashboard');
$typeLabels = ['borrow'=>'መዋስ','reserve'=>'ማስያዝ'];
$activeKey = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">አጠቃላይ ዕይታ</div>
<div class="row g-2">
  <div class="col-6 col-md-3"><div class="stat-card gold"><div class="num"><?= $totalBooks ?></div><div class="lbl">ጠቅላላ መጻሕፍት</div><i class="bi bi-book"></i></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="num"><?= $totalCopies ?></div><div class="lbl">ጠቅላላ ቅጂዎች</div><i class="bi bi-stack"></i></div></div>
  <div class="col-6 col-md-3"><div class="stat-card outline"><div class="num" style="color:var(--success);"><?= $availableCopies ?></div><div class="lbl">ይገኛል</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card outline"><div class="num" style="color:var(--warning);"><?= $borrowedCopies ?></div><div class="lbl">ተወስዷል</div></div></div>
</div>
<div class="row g-2 mt-1">
  <div class="col-6 col-md-3"><a href="requests.php" class="stat-card outline" style="display:block;text-decoration:none;<?= $pendingRequests ? 'border-color:var(--warning);' : '' ?>"><div class="num"><?= $pendingRequests ?></div><div class="lbl">በመጠባበቅ ላይ ያሉ ጥያቄዎች</div></a></div>
  <div class="col-6 col-md-3"><a href="suggestions.php" class="stat-card outline" style="display:block;text-decoration:none;"><div class="num"><?= $pendingSuggestions ?></div><div class="lbl">ጥቆማዎች</div></a></div>
  <div class="col-6 col-md-3"><a href="members.php" class="stat-card outline" style="display:block;text-decoration:none;"><div class="num"><?= $totalMembers ?></div><div class="lbl">አባላት</div></a></div>
  <div class="col-6 col-md-3"><div class="stat-card outline" style="<?= $overdue ? 'border-color:var(--danger);' : '' ?>"><div class="num" style="<?= $overdue ? 'color:var(--danger);' : '' ?>"><?= $overdue ?></div><div class="lbl">ጊዜው ያለፈ</div></div></div>
</div>

<div class="section-title">
  ማረጋገጫ የሚጠብቁ ጥያቄዎች
  <a href="requests.php" class="see-all"><?= __('see_all') ?> <i class="bi bi-arrow-right"></i></a>
</div>
<?php if (mysqli_num_rows($recentRequests) === 0): ?>
  <div class="empty-state"><i class="bi bi-check2-circle"></i><h4>ምንም የቀረ ነገር የለም</h4><p>አሁን በመጠባበቅ ላይ ያለ ጥያቄ የለም።</p></div>
<?php else: while ($r = mysqli_fetch_assoc($recentRequests)): ?>
  <div class="card card-pad mb-2" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <div><strong><?= e($r['title']) ?></strong><br><span class="text-muted" style="font-size:.78rem;">በ <?= e($r['full_name']) ?> · <?= e($typeLabels[$r['type']] ?? $r['type']) ?></span></div>
    <a href="requests.php" class="btn btn-navy btn-sm">ይመልክቱ</a>
  </div>
<?php endwhile; endif; ?>

<div class="row g-2 mt-3">
  <div class="col-6"><a href="books.php?new=1" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-plus-circle" style="font-size:1.4rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">መጽሐፍ ጨምር</div></a></div>
  <div class="col-6"><a href="returns.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-arrow-return-left" style="font-size:1.4rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">ተመላሽ ይያዙ</div></a></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
