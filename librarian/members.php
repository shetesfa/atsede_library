<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$q = clean($_GET['q'] ?? '');
$where = "u.role='member' AND u.status='active'";
if ($q !== '') {
    $like = mysqli_real_escape_string($conn, $q);
    $where .= " AND (u.full_name LIKE '%$like%' OR u.phone LIKE '%$like%' OR m.student_id LIKE '%$like%')";
}

$members = mysqli_query($conn, "
  SELECT u.*, m.id AS member_id, m.class, m.student_id, m.blocked_until,
    (SELECT COUNT(*) FROM borrow_records WHERE member_id=m.id AND status='borrowed') AS active_loans,
    (SELECT COUNT(*) FROM borrow_records WHERE member_id=m.id) AS total_borrows
  FROM users u JOIN members m ON m.user_id=u.id WHERE $where ORDER BY u.full_name ASC");

$pageTitle = __('members');
$activeKey = 'members';
include __DIR__ . '/../includes/header.php';
?>

<form method="get" class="card card-pad mb-3"><div class="input-group"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($q) ?>" placeholder="አባላትን ይፈልጉ…"></div></form>

<div class="table-wrap">
  <table class="app-table app-stack">
    <thead><tr><th>ስም</th><th>ክፍል</th><th>ስልክ</th><th>በስራ ላይ ያሉ ውሶች</th></tr></thead>
    <tbody>
      <?php if (mysqli_num_rows($members) === 0): ?>
        <tr><td colspan="4"><div class="empty-state"><i class="bi bi-people"></i><h4>አባል አልተገኘም</h4></div></td></tr>
      <?php endif; ?>
      <?php while ($m = mysqli_fetch_assoc($members)): ?>
      <tr style="cursor:pointer;" onclick="openMemberDetail(<?= htmlspecialchars(json_encode([
        'full_name' => $m['full_name'],
        'phone' => $m['phone'],
        'username' => $m['username'],
        'class' => $m['class'] ?: '—',
        'student_id' => $m['student_id'] ?: '—',
        'created_at' => formatDate($m['created_at']),
        'last_login' => $m['last_login'] ? formatDate($m['last_login']) : '—',
        'active_loans' => (int)$m['active_loans'],
        'total_borrows' => (int)$m['total_borrows'],
      ]), ENT_QUOTES, 'UTF-8') ?>)">
        <td data-label="ስም"><strong style="color:var(--navy);"><?= e($m['full_name']) ?></strong><?= $m['student_id'] ? '<br><span class="text-muted" style="font-size:.74rem;">መታወቂያ '.e($m['student_id']).'</span>' : '' ?></td>
        <td data-label="ክፍል"><?= e($m['class'] ?: '—') ?></td>
        <td data-label="ስልክ"><?= e($m['phone']) ?></td>
        <td data-label="በስራ ላይ ያሉ ውሶች"><span class="badge <?= $m['active_loans']>0?'badge-warning':'badge-success' ?>"><?= (int)$m['active_loans'] ?></span></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="sheet-overlay" id="member-detail-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" id="md-name">የአባል ዝርዝር</div>
    <div id="md-body" style="font-size:.86rem;line-height:1.7;"></div>
    <button class="btn btn-outline btn-block mt-2" type="button" onclick="closeSheet('member-detail-sheet')"><?= __('close') ?></button>
  </div>
</div>

<script>
function openMemberDetail(d) {
  document.getElementById('md-name').textContent = d.full_name;
  document.getElementById('md-body').innerHTML =
    '<div style="display:grid;gap:6px;">' +
    '<div><span class="text-muted">ስልክ፦</span> ' + d.phone + '</div>' +
    '<div><span class="text-muted">የተጠቃሚ ስም፦</span> ' + d.username + '</div>' +
    '<div><span class="text-muted">ክፍል፦</span> ' + d.class + '</div>' +
    '<div><span class="text-muted">መታወቂያ፦</span> ' + d.student_id + '</div>' +
    '<div><span class="text-muted">የተመዘገበበት፦</span> ' + d.created_at + '</div>' +
    '<div><span class="text-muted">የመጨረሻ መግቢያ፦</span> ' + d.last_login + '</div>' +
    '<div><span class="text-muted">በስራ ላይ ያሉ ውሶች፦</span> ' + d.active_loans + '</div>' +
    '<div><span class="text-muted">ጠቅላላ ውሶች፦</span> ' + d.total_borrows + '</div>' +
    '</div>';
  openSheet('member-detail-sheet');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
