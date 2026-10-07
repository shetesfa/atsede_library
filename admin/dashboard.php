<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$stats = [
    'ጠቅላላ መጻሕፍት' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books"))['c'],
    'ጠቅላላ ቅጂዎች' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies"))['c'],
    'ይገኛል ያሉ መጻሕፍት' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status='available'"))['c'],
    'በውሰት ላይ ያሉ' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='borrowed'"))['c'],
    'ለውሰት ያልተፈቀዱ' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status='restricted'"))['c'],
    'ለንባብ ብቻ' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status='reference'"))['c'],
    'በማህደር ያሉ' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status='archived'"))['c'],
    'ጠቅላላ አባላት' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='active'"))['c'],
    'የመዋስ ጥያቄዎች' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE status='pending'"))['c'],
    'አዳዲስ ምዝገባዎች' => mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='pending'"))['c'],
];

$recentAudit = mysqli_query($conn, "SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 8");

$pageTitle = __('dashboard');
$activeKey = 'dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">አጠቃላይ ስታትስቲክስ</div>
<div class="row g-2">
  <?php $i=0; $colors=['gold','','outline']; foreach ($stats as $label => $val): $cls = $colors[$i % 3]; $i++; ?>
    <div class="col-6 col-md-3">
      <div class="stat-card <?= $cls ?>" style="<?= ($label==='የመዋስ ጥያቄዎች' && $val>0) || ($label==='አዳዲስ ምዝገባዎች' && $val>0) ? 'outline:2px solid var(--warning);' : '' ?>">
        <div class="num"><?= (int)$val ?></div>
        <div class="lbl"><?= e($label) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($stats['አዳዲስ ምዝገባዎች'] > 0): ?>
<div class="card card-pad mt-3" style="border-left:4px solid var(--warning);display:flex;align-items:center;gap:12px;">
  <i class="bi bi-person-plus" style="font-size:1.4rem;color:var(--warning);"></i>
  <div style="flex:1;"><?= $stats['አዳዲስ ምዝገባዎች'] ?> የአባልነት ምዝገባ(ዎች) ማረጋገጫዎን በመጠባበቅ ላይ ናቸው።</div>
  <a href="members.php?status=pending" class="btn btn-navy btn-sm">ይመልክቱ</a>
</div>
<?php endif; ?>

<div class="section-title d-flex justify-content-between align-items-center">
  <span>የቅርብ ጊዜ ድርጊቶች</span>
  <a href="activities.php" class="see-all">ሁሉንም ድርጊቶች ይመልከቱ <i class="bi bi-arrow-right"></i></a>
</div>
<div class="table-wrap">
  <table class="app-table app-stack">
    <thead><tr><th>ማን</th><th>ድርጊት</th><th>መቼ</th></tr></thead>
    <tbody>
      <?php if (mysqli_num_rows($recentAudit) === 0): ?><tr><td colspan="3"><div class="empty-state"><i class="bi bi-clock-history"></i><h4>እስካሁን ድርጊት የለም</h4></div></td></tr><?php endif; ?>
      <?php while ($a = mysqli_fetch_assoc($recentAudit)): ?>
      <tr>
        <td data-label="ማን"><?= e($a['full_name'] ?: 'ስርዓት') ?></td>
        <td data-label="ድርጊት"><?= e(str_replace('_',' ', $a['action'])) ?></td>
        <td data-label="መቼ"><?= formatDate($a['created_at']) ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="row g-2 mt-3">
  <div class="col-6 col-md-4 col-lg-2"><a href="activities.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-clock-history" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">የድርጊቶች ታሪክ</div></a></div>
  <div class="col-6 col-md-4 col-lg-2"><a href="librarians.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-person-badge" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">ቤተ-መጻሕፍት ኃላፊዎች</div></a></div>
  <div class="col-6 col-md-4 col-lg-2"><a href="categories.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-tags" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">ምድቦች</div></a></div>
  <div class="col-6 col-md-4 col-lg-2"><a href="notifications.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-megaphone" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">ማሳወቂያ ይላኩ</div></a></div>
  <div class="col-6 col-md-4 col-lg-2"><a href="reports.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-bar-chart" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">ሪፖርቶች</div></a></div>
  <div class="col-6 col-md-4 col-lg-2"><a href="settings.php" class="card card-pad card-hover" style="display:block;text-align:center;"><i class="bi bi-gear" style="font-size:1.3rem;color:var(--gold-600);"></i><div style="font-weight:700;font-size:.82rem;margin-top:6px;color:var(--navy);">ቅንብሮች</div></a></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
