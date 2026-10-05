<?php
/**
 * ajax/member_info.php
 * Called when librarian scans a member's Digital ID Card QR.
 * Shows full member info: name, class, payment status, active borrows, fines.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';

global $conn;

require_login();

$user    = current_user();
$userId  = (int)$user['id'];
$role    = $user['role'] ?? 'guest';
$isStaff = in_array($role, ['librarian', 'admin'], true);

$token = clean($_GET['token'] ?? $_GET['card_token'] ?? '');
$uid   = (int)($_GET['uid'] ?? 0);

$member = null;
if (!empty($token)) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.*, m.id AS member_id, m.class, m.student_id, m.card_token, m.blocked_until
         FROM members m JOIN users u ON u.id = m.user_id 
         WHERE m.card_token = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $token);
    mysqli_stmt_execute($stmt);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
} elseif ($uid > 0 && ($isStaff || $userId === $uid)) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.*, m.id AS member_id, m.class, m.student_id, m.card_token, m.blocked_until
         FROM users u JOIN members m ON m.user_id = u.id 
         WHERE u.id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $uid);
    mysqli_stmt_execute($stmt);
    $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

if (!$member) {
    http_response_code(404);
    $pageTitle  = 'አባሉ አልተገኘም';
    $hideChrome = ($role === 'guest');
    include __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-person-x text-danger" style="font-size:2.5rem;"></i>
          <h4>አባሉ አልተገኘም</h4>
          <p>ይህ የካርድ መለያ ዕውቅና ያለው አባል አይወክልም።</p></div>';
    include __DIR__ . '/../includes/footer.php';
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

// Authorization check: Only staff or the member themselves can view details
$targetUserId = (int)$member['id'];
if (!$isStaff && $userId !== $targetUserId) {
    http_response_code(403);
    $pageTitle  = 'ፍቃድ የለዎትም';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-shield-x text-danger" style="font-size:2.5rem;"></i>
          <h4>403 — ፍቃድ የለዎትም</h4>
          <p>የሌላ አባል መረጃ ለመመልከት የሚያስችል ስልጣን የለዎትም።</p></div>';
    include __DIR__ . '/../includes/footer.php';
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

http_response_code(200);

$memberId   = (int)$member['member_id'];
$ps         = get_member_payment_status($conn, $memberId);
$totalFine  = get_member_outstanding_fine($conn, $memberId);
$isBlocked  = is_member_blocked($conn, $targetUserId);

// Active borrows (accessible to staff or self)
$borrows = mysqli_query($conn,
    "SELECT b.title, b.author, bc.copy_code, br.due_date, br.overdue_fine, br.fine_paid, br.fine_waived
     FROM borrow_records br
     JOIN books b ON b.id=br.book_id
     JOIN book_copies bc ON bc.id=br.book_copy_id
     WHERE br.member_id=$memberId AND br.status='borrowed'
     ORDER BY br.due_date ASC");

$pageTitle  = 'የአባልነት ማረጋገጫ — ' . $member['full_name'];
$activeKey  = '';
include __DIR__ . '/../includes/header.php';

// Prepare Avatar Photo URL
$photoUrl = '';
if (!empty($member['profile_photo']) && file_exists(__DIR__ . '/../' . $member['profile_photo'])) {
    $photoUrl = rtrim(BASE_URL, '/') . '/' . ltrim($member['profile_photo'], '/');
}
$libOfficialName = library_name($conn);
?>

<!-- Back -->
<div class="mb-3">
  <a href="javascript:history.back()" class="text-muted" style="font-size:.85rem;text-decoration:none;">
    <i class="bi bi-arrow-left"></i> ተመለስ
  </a>
</div>

<!-- ================= OFFICIAL VERIFICATION CARD ================= -->
<div class="card mb-3" style="border: 2px solid <?= $isBlocked ? 'var(--danger)' : ($member['status'] === 'active' ? '#10b981' : 'var(--gold)') ?>; border-radius:16px; overflow:hidden; box-shadow:0 8px 24px rgba(0,0,0,0.06); background:#fff;">

  <!-- Header Banner -->
  <div style="background:linear-gradient(135deg, #0d1b2a 0%, #1e3a5f 100%); color:#fff; padding:18px 16px; text-align:center; position:relative;">
    <div style="display:inline-block; background:rgba(212,160,23,0.18); border:1px solid rgba(212,160,23,0.4); border-radius:30px; padding:3px 12px; font-size:.72rem; color:var(--gold); font-weight:700; letter-spacing:0.5px; margin-bottom:6px;">
      <i class="bi bi-shield-check"></i> ይፋዊ የዲጂታል አባልነት ማረጋገጫ
    </div>
    <div style="font-size:1.02rem; font-weight:800; color:#fff; line-height:1.35; max-width:420px; margin:0 auto;">
      <?= e($libOfficialName) ?>
    </div>
  </div>

  <div class="card-pad text-center" style="padding:22px 18px;">
    
    <!-- Member Avatar -->
    <div style="margin:-10px auto 14px; position:relative; display:inline-block;">
      <?php if ($photoUrl): ?>
        <img src="<?= e($photoUrl) ?>" alt="<?= e($member['full_name']) ?>"
             style="width:96px; height:96px; border-radius:50%; object-fit:cover; border:3.5px solid #fff; box-shadow:0 4px 14px rgba(0,0,0,0.18);">
      <?php else: ?>
        <div style="width:96px; height:96px; border-radius:50%; background:linear-gradient(135deg, #1e3a5f, #0d1b2a); display:flex; align-items:center; justify-content:center; margin:0 auto; border:3.5px solid #fff; box-shadow:0 4px 14px rgba(0,0,0,0.18);">
          <i class="bi bi-person-fill" style="font-size:2.8rem; color:var(--gold);"></i>
        </div>
      <?php endif; ?>

      <?php if ($member['status'] === 'active' && !$isBlocked): ?>
        <div style="position:absolute; bottom:2px; right:4px; width:26px; height:26px; background:#10b981; border:2.5px solid #fff; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:.8rem;" title="የተረጋገጠ">
          <i class="bi bi-check-lg"></i>
        </div>
      <?php endif; ?>
    </div>

    <!-- Member Name -->
    <h3 style="font-size:1.25rem; font-weight:800; color:var(--navy); margin:0 0 4px; line-height:1.3;">
      <?= e($member['full_name']) ?>
    </h3>

    <div class="text-muted" style="font-size:.86rem; margin-bottom:12px;">
      <span>ክፍል፦ <strong><?= e($member['class'] ?: 'ያልተገለጸ') ?></strong></span>
      <?php if (!empty($member['student_id'])): ?>
        <span class="mx-1">·</span>
        <span>የተማሪ መለያ፦ <strong>#<?= e($member['student_id']) ?></strong></span>
      <?php endif; ?>
    </div>

    <!-- Status Badges -->
    <div class="d-flex justify-content-center flex-wrap gap-2 mb-3">
      <?php if ($isBlocked): ?>
        <span class="badge badge-danger" style="font-size:.82rem; padding:6px 12px;">
          <i class="bi bi-ban"></i> የታገደ አባል
        </span>
      <?php elseif ($member['status'] === 'active'): ?>
        <span class="badge" style="background:#10b981; color:#fff; font-size:.82rem; padding:6px 12px; font-weight:700;">
          <i class="bi bi-check-circle-fill"></i> ይፋዊ ንቁ አባል (Verified)
        </span>
      <?php elseif ($member['status'] === 'pending'): ?>
        <span class="badge badge-warning" style="font-size:.82rem; padding:6px 12px;">
          <i class="bi bi-hourglass-split"></i> ማረጋገጫ በመጠባበቅ ላይ
        </span>
      <?php else: ?>
        <span class="badge badge-danger" style="font-size:.82rem; padding:6px 12px;">
          <i class="bi bi-x-circle"></i> ንቁ ያልሆነ አባል
        </span>
      <?php endif; ?>

      <span class="badge badge-outline" style="font-size:.82rem; padding:6px 12px; border-color:var(--line);">
        መለያ #<?= str_pad((string)$member['id'], 5, '0', STR_PAD_LEFT) ?>
      </span>
    </div>

    <!-- Official Stamp Notice -->
    <div style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:12px 14px; font-size:.82rem; color:#475569; line-height:1.6; text-align:center; margin-bottom:6px;">
      <i class="bi bi-patch-check-fill" style="color:var(--gold); font-size:1rem;"></i>
      ይህ መታወቂያ ካርድ በ<strong><?= e($libOfficialName) ?></strong> የታወቀና ዕውቅና የተሰጠው ህጋዊ የዲጂታል አባልነት ማስረጃ ነው።
    </div>

    <!-- Staff Only Section: Payment & Fines -->
    <?php if ($isStaff): ?>
      <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--line); text-align:left;">
        <div style="font-weight:700; font-size:.84rem; color:var(--navy); margin-bottom:8px;">
          <i class="bi bi-gear-fill text-gold me-1"></i> የላይብረሪያን / አስተዳዳሪ ቁጥጥር
        </div>

        <div class="row g-2 text-center mb-2">
          <div class="col-4">
            <div style="background:var(--slate-50);border-radius:8px;padding:8px 4px;">
              <div style="font-weight:800;color:<?= $ps['is_paid'] ? '#10b981' : 'var(--danger)' ?>;font-size:1rem;">
                <?= $ps['is_paid'] ? 'ተከፍሏል' : 'አልተከፈለም' ?>
              </div>
              <div style="font-size:.7rem;color:var(--muted);">የወር ክፍያ</div>
            </div>
          </div>
          <div class="col-4">
            <div style="background:var(--slate-50);border-radius:8px;padding:8px 4px;">
              <div style="font-weight:800;color:var(--navy);font-size:1.1rem;">
                <?= $borrows ? mysqli_num_rows($borrows) : 0 ?>
              </div>
              <div style="font-size:.7rem;color:var(--muted);">ያዋሱ ቅጂዎች</div>
            </div>
          </div>
          <div class="col-4">
            <div style="background:<?= $totalFine > 0 ? '#fff8f0' : 'var(--slate-50)' ?>;border-radius:8px;padding:8px 4px;">
              <div style="font-weight:800;color:<?= $totalFine > 0 ? 'var(--danger)' : 'var(--navy)' ?>;font-size:1.1rem;">
                <?= number_format($totalFine, 0) ?>
              </div>
              <div style="font-size:.7rem;color:var(--muted);">ብር ቅጣት</div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <a href="<?= $base ?>librarian/payments.php?member_id=<?= $memberId ?>" class="btn btn-gold btn-sm" style="flex:1;">
            <i class="bi bi-cash-coin"></i> ክፍያ መዝግብ
          </a>
          <a href="<?= $base ?>librarian/issue.php?member_id=<?= $memberId ?>" class="btn btn-navy btn-sm" style="flex:1;">
            <i class="bi bi-book"></i> መጽሐፍ አውስ
          </a>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- Active Borrows (Staff & Member Only) -->
<?php if (($isStaff || ($user && (int)$user['id'] === $uid)) && $borrows && mysqli_num_rows($borrows) > 0): ?>
  <div class="section-title">📚 አሁን በእጅ ያሉ የተዋሱ ቅጂዎች</div>
  <div class="card mb-3">
    <?php
    $today = new DateTime();
    while ($br = mysqli_fetch_assoc($borrows)):
      $due     = new DateTime($br['due_date']);
      $isLate  = $today > $due;
      $netFine = max(0, (float)$br['overdue_fine'] - (float)$br['fine_paid'] - (float)$br['fine_waived']);
    ?>
      <div style="padding:10px 14px;border-bottom:1px solid var(--line);">
        <div style="font-weight:700;font-size:.9rem;color:var(--navy);">
          <?= e($br['title']) ?>
        </div>
        <div style="font-size:.78rem;color:var(--muted);">
          ቅጂ <?= e($br['copy_code']) ?>
          <?= $br['author'] ? ' · ' . e($br['author']) : '' ?>
        </div>
        <div style="font-size:.78rem;margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;">
          <span class="<?= $isLate ? 'text-danger' : 'text-muted' ?>">
            <i class="bi bi-calendar<?= $isLate ? '-x' : '' ?>"></i>
            <?= $isLate ? '⚠️ ዘግይቷል — ' : 'ይመለስ: ' ?>
            <?= formatDate($br['due_date']) ?>
          </span>
          <?php if ($isLate): ?>
            <span style="color:var(--danger);">
              <?= $today->diff($due)->days ?> ቀን ዘግይቷል
            </span>
          <?php endif; ?>
          <?php if ($netFine > 0): ?>
            <span style="color:var(--danger);">
              💸 <?= number_format($netFine, 2) ?> ብር ቅጣት
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

