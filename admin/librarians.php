<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_librarian'])) {
    csrf_verify();
    $fullName = clean($_POST['full_name'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '' || $phone === '' || strlen($username) < 4 || strlen($password) < 6) {
        $error = 'እባክዎ ሁሉንም መስኮች በትክክል ይሙሉ (የሚስጥር ቁልፍ ቢያንስ 6 ፊደላት)።';
    } else {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE username='" . mysqli_real_escape_string($conn, $username) . "'");
        if (mysqli_num_rows($check) > 0) {
            $error = 'የተጠቃሚ ስም ተይዟል።';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, phone, username, password, role, status) VALUES (?,?,?,?,'librarian','active')");
            mysqli_stmt_bind_param($stmt, 'ssss', $fullName, $phone, $username, $hash);
            mysqli_stmt_execute($stmt);
            $newId = mysqli_insert_id($conn);
            $stmt2 = mysqli_prepare($conn, "INSERT INTO librarians (user_id, assigned_by) VALUES (?,?)");
            $assignedBy = (int)$user['id'];
            mysqli_stmt_bind_param($stmt2, 'ii', $newId, $assignedBy);
            mysqli_stmt_execute($stmt2);
            audit($conn, $user['id'], 'librarian_added', "user_id:$newId");
            flash('msg', 'የቤተ-መጻሕፍት ኃላፊ መለያ ተፈጥሯል።', 'success');
            redirect('librarians.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    csrf_verify();
    $uid = (int)$_POST['user_id'];
    $newStatus = $_POST['new_status'] === 'active' ? 'active' : 'suspended';
    mysqli_query($conn, "UPDATE users SET status='$newStatus' WHERE id=$uid AND role='librarian'");
    audit($conn, $user['id'], 'librarian_status_' . $newStatus, "user_id:$uid");
    flash('msg', 'የቤተ-መጻሕፍት ኃላፊ ሁኔታ ዘምኗል።', 'success');
    redirect('librarians.php');
}

$librarians = mysqli_query($conn, "SELECT u.* FROM users u WHERE u.role='librarian' ORDER BY u.created_at DESC");

$pageTitle = __('librarians');
$activeKey = 'librarians';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
  <button class="btn btn-gold" onclick="openSheet('lib-sheet')"><i class="bi bi-plus-lg"></i> ቤተ-መጻሕፍት ኃላፊ ጨምር</button>
</div>

<div class="table-wrap">
  <table class="app-table app-stack">
    <thead><tr><th>ስም</th><th>ስልክ</th><th>የተጠቃሚ ስም</th><th>ሁኔታ</th><th>ይምረጡ</th></tr></thead>
    <tbody>
      <?php if (mysqli_num_rows($librarians) === 0): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-person-badge"></i><h4>እስካሁን ቤተ-መጻሕፍት ኃላፊ የለም</h4></div></td></tr><?php endif; ?>
      <?php while ($l = mysqli_fetch_assoc($librarians)): ?>
      <tr>
        <td data-label="ስም">
          <strong style="color:var(--navy);cursor:pointer;" onclick="openLibDetail(<?= htmlspecialchars(json_encode([
            'full_name' => $l['full_name'],
            'phone' => $l['phone'],
            'username' => $l['username'],
            'status' => $l['status'] === 'active' ? 'ንቁ' : 'ታግዷል',
            'created_at' => formatDate($l['created_at']),
            'last_login' => $l['last_login'] ? formatDate($l['last_login']) : '—',
          ]), ENT_QUOTES, 'UTF-8') ?>)"><?= e($l['full_name']) ?></strong>
          <i class="bi bi-chevron-right text-muted" style="font-size:.7rem;"></i>
        </td>
        <td data-label="ስልክ"><?= e($l['phone']) ?></td>
        <td data-label="የተጠቃሚ ስም" class="mono"><?= e($l['username']) ?></td>
        <td data-label="ሁኔታ"><span class="badge <?= $l['status']==='active'?'badge-success':'badge-danger' ?>"><?= $l['status']==='active' ? __("active") : __("suspended") ?></span></td>
        <td data-label="ይምረጡ">
          <form method="post" style="display:inline;">
            <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$l['id'] ?>">
            <input type="hidden" name="new_status" value="<?= $l['status']==='active'?'suspended':'active' ?>">
            <button class="btn btn-outline btn-sm" name="toggle_status" value="1"><?= $l['status']==='active'? 'አግድ' : 'መልስ' ?></button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<div class="sheet-overlay" id="lib-detail-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" id="ld-name">የቤተ-መጻሕፍት ኃላፊ</div>
    <div id="ld-body" style="font-size:.86rem;line-height:1.7;"></div>
    <button class="btn btn-outline btn-block mt-2" type="button" onclick="closeSheet('lib-detail-sheet')"><?= __('close') ?></button>
  </div>
</div>

<div class="sheet-overlay <?= $error ? 'show' : '' ?>" id="lib-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">ቤተ-መጻሕፍት ኃላፊ ጨምር</div>
    <?php if ($error): ?><div class="card card-pad mb-2" style="border-left:4px solid var(--danger);color:var(--danger);font-size:.85rem;"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label><?= __("full_name") ?></label><input class="input" name="full_name" required></div>
      <div class="field"><label><?= __("phone") ?></label><input class="input" name="phone" required></div>
      <div class="field"><label><?= __("username") ?></label><input class="input" name="username" required></div>
      <div class="field"><label><?= __("password") ?></label><input class="input" type="password" name="password" required></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('lib-sheet')"><?= __("cancel") ?></button>
        <button class="btn btn-gold btn-block" name="add_librarian" value="1">መለያ ይፍጠሩ</button>
      </div>
    </form>
  </div>
</div>

<script>
function openLibDetail(d) {
  document.getElementById('ld-name').textContent = d.full_name;
  document.getElementById('ld-body').innerHTML =
    '<div style="display:grid;gap:6px;">' +
    '<div><span class="text-muted">ስልክ፦</span> ' + d.phone + '</div>' +
    '<div><span class="text-muted">የተጠቃሚ ስም፦</span> ' + d.username + '</div>' +
    '<div><span class="text-muted">ሁኔታ፦</span> ' + d.status + '</div>' +
    '<div><span class="text-muted">የተመዘገበበት፦</span> ' + d.created_at + '</div>' +
    '<div><span class="text-muted">የመጨረሻ መግቢያ፦</span> ' + d.last_login + '</div>' +
    '</div>';
  openSheet('lib-detail-sheet');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
