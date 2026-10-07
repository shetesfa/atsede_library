<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM members WHERE user_id=" . (int)$user['id']));
$memberId = (int)$member['id'];

// Current monthly payment check
$paymentStatus = get_member_payment_status($conn, $memberId);
$minMonthly = get_minimum_monthly_payment($conn);

$activeBorrows = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed'"))['c'];
$pendingRequests = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE member_id=$memberId AND status='pending'"))['c'];
$overdueCount = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed' AND due_date < CURDATE()"))['c'];
$favoritesCount = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM favorites WHERE user_id=" . (int)$user['id']))['c'];
$unreadNotifs = unread_count($conn, (int)$user['id']);

$current = mysqli_query($conn, "
  SELECT br.*, b.title, b.author, bc.copy_code FROM borrow_records br
  JOIN books b ON b.id=br.book_id JOIN book_copies bc ON bc.id=br.book_copy_id
  WHERE br.member_id=$memberId AND br.status='borrowed' ORDER BY br.due_date ASC LIMIT 5");

$pageTitle = __('home');
$activeKey = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<!-- User Profile Hero Banner -->
<div class="hero-banner mb-3">
  <div class="d-flex justify-content-between align-items-center">
    <div>
      <h1 class="font-display" style="font-size:1.3rem;margin:0 0 4px;">
        እንኳን ደህና መጡ፣ <?= e(explode(' ', $user['full_name'])[0]) ?>
      </h1>
      <p style="margin:0;font-size:.84rem;opacity:0.85;">
        <i class="bi bi-person-badge"></i> <?= e($user['full_name']) ?> · ክፍል <?= e($member['class'] ?: '—') ?>
      </p>
    </div>
    <a href="<?= $base ?>member/profile.php" class="btn btn-outline btn-sm" style="border-color:rgba(255,255,255,0.4);color:#fff;">
      <i class="bi bi-person"></i> መገለጫ
    </a>
  </div>
</div>

<!-- 1. MONTHLY PAYMENT STATUS CARD (Important Requirement) -->
<a href="<?= $base ?>member/payments.php" class="card card-pad mb-3" style="display:block;text-decoration:none;border-left: 5px solid <?= $paymentStatus['is_paid'] ? 'var(--success)' : 'var(--danger)' ?>;">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <strong style="color:var(--navy);font-size:.95rem;">
      <i class="bi bi-cash-coin text-gold"></i> የዚህ ወር ክፍያ (<?= e($paymentStatus['month_label']) ?>)
    </strong>
    <span class="badge <?= $paymentStatus['badge_class'] ?>" style="font-size:.8rem;">
      <?= $paymentStatus['is_paid'] ? '✓ ' . $paymentStatus['status_text'] : '⚠ ' . $paymentStatus['status_text'] ?>
    </span>
  </div>
  <div class="d-flex justify-content-between align-items-end mt-2">
    <div>
      <span style="font-size:1.3rem;font-weight:700;color:var(--navy);"><?= number_format($paymentStatus['amount_paid'], 2) ?> ብር</span>
      <span class="text-muted" style="font-size:.8rem;"> / ከዝቅተኛው <?= number_format($minMonthly, 0) ?> ብር</span>
    </div>
    <span class="text-muted" style="font-size:.78rem;">
      ዝርዝር ታሪክ ይዩ <i class="bi bi-chevron-right"></i>
    </span>
  </div>
</a>

<!-- 2. QUICK STATS CARDS -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <a href="<?= $base ?>member/my_books.php?tab=current" class="stat-card gold" style="display:block;text-decoration:none;">
      <div class="num"><?= $activeBorrows ?></div>
      <div class="lbl">የተዋስኳቸው መጽሐፍት</div>
      <i class="bi bi-journal-bookmark"></i>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $base ?>member/my_books.php?tab=pending" class="stat-card" style="display:block;text-decoration:none;">
      <div class="num"><?= $pendingRequests ?></div>
      <div class="lbl">የመዋስ ጥያቄዎች</div>
      <i class="bi bi-hourglass-split"></i>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $base ?>member/notifications.php" class="stat-card outline" style="display:block;text-decoration:none;<?= $unreadNotifs > 0 ? 'border-color:var(--gold);' : '' ?>">
      <div class="num" style="<?= $unreadNotifs > 0 ? 'color:var(--gold-600);' : '' ?>"><?= $unreadNotifs ?></div>
      <div class="lbl">ማሳወቂያዎች</div>
      <i class="bi bi-bell"></i>
    </a>
  </div>

  <div class="col-6 col-md-3">
    <a href="<?= $base ?>member/favorites.php" class="stat-card outline" style="display:block;text-decoration:none;">
      <div class="num" style="color:var(--danger);"><?= $favoritesCount ?></div>
      <div class="lbl">የተወደዱ መጽሐፍት</div>
      <i class="bi bi-heart"></i>
    </a>
  </div>
</div>

<!-- 3. CURRENTLY BORROWED & DUE DATES -->
<div class="section-title">
  በእጄ ያሉ መጻሕፍትና የመመለሻ ቀን
  <a href="<?= $base ?>member/my_books.php" class="see-all"><?= __('see_all') ?> <i class="bi bi-arrow-right"></i></a>
</div>

<?php if (mysqli_num_rows($current) === 0): ?>
  <div class="empty-state mb-3">
    <i class="bi bi-journal-check text-muted" style="font-size:2.2rem;"></i>
    <h4>አሁን በእጅዎ ያለ መጽሐፍ የለም</h4>
    <p>የሚፈልጉትን መጽሐፍ በካታሎግ ውስጥ ይፈልጉ።</p>
    <a href="<?= $base ?>search.php" class="btn btn-gold btn-sm mt-1">መጽሐፍት ይፈልጉ</a>
  </div>
<?php else: ?>
  <div class="table-wrap mb-3">
    <table class="app-table app-stack">
      <thead><tr><th>መጽሐፍ</th><th>ቅጂ ኮድ</th><th>የመመለሻ ቀን</th></tr></thead>
      <tbody>
        <?php while ($r = mysqli_fetch_assoc($current)): 
          $isOverdue = strtotime($r['due_date']) < strtotime('today'); 
        ?>
        <tr>
          <td data-label="መጽሐፍ">
            <strong><?= e($r['title']) ?></strong><br>
            <span class="text-muted" style="font-size:.76rem;"><?= e($r['author']) ?></span>
          </td>
          <td data-label="ኮድ"><span class="shelf-tag"><?= e($r['copy_code']) ?></span></td>
          <td data-label="የመመለሻ ቀን">
            <span class="badge <?= $isOverdue ? 'badge-danger' : 'badge-success' ?>">
              <i class="bi bi-calendar-event"></i> <?= formatDate($r['due_date']) ?>
              <?= $isOverdue ? ' (ጊዜው አልፏል!)' : '' ?>
            </span>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- 4. SHORTCUTS -->
<div class="section-title">ፈጣን አገልግሎቶች</div>
<div class="row g-2">
  <div class="col-6">
    <a href="<?= $base ?>search.php" class="card card-pad card-hover" style="display:block;text-align:center;text-decoration:none;">
      <i class="bi bi-search" style="font-size:1.4rem;color:var(--gold-600);"></i>
      <div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">መጻሕፍት ይፈልጉ</div>
    </a>
  </div>
  <div class="col-6">
    <a href="<?= $base ?>member/payments.php" class="card card-pad card-hover" style="display:block;text-align:center;text-decoration:none;">
      <i class="bi bi-cash-stack" style="font-size:1.4rem;color:var(--gold-600);"></i>
      <div style="font-weight:700;font-size:.85rem;margin-top:6px;color:var(--navy);">የክፍያ ታሪክ ይዩ</div>
    </a>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
