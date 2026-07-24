<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT u.*, m.class, m.student_id FROM users u JOIN members m ON m.user_id=u.id WHERE u.id=" . (int)$user['id']));

$error = ''; $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    if (!password_verify($current, $row['password'])) {
        $error = 'የአሁኑ የሚስጥር ቁልፍ ትክክል አይደለም።';
    } elseif (strlen($new) < 6) {
        $error = 'አዲሱ የሚስጥር ቁልፍ ቢያንስ 6 ፊደላት ሊኖረው ይገባል።';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password='" . mysqli_real_escape_string($conn, $hash) . "' WHERE id=" . (int)$user['id']);
        audit($conn, $user['id'], 'password_changed', '');
        $ok = 'የሚስጥር ቁልፍ በትክክል ተቀይሯል።';
    }
}

$pageTitle = __('profile');
$activeKey = 'profile';
include __DIR__ . '/../includes/header.php';
?>

<div class="card card-pad" style="text-align:center;margin-bottom:18px;">
  <i class="bi bi-person-circle" style="font-size:3rem;color:var(--gold-600);"></i>
  <h3 class="font-display" style="color:var(--navy);margin:8px 0 2px;"><?= e($row['full_name']) ?></h3>
  <p class="text-muted" style="font-size:.84rem;">ክፍል <?= e($row['class'] ?: '—') ?> <?= $row['student_id'] ? '· መታወቂያ ' . e($row['student_id']) : '' ?></p>
  <span class="badge badge-success">ንቁ አባል</span>
</div>

<div class="section-title" style="margin-top:0;">ምርጫዎች</div>
<div class="card card-pad mb-3" style="display:flex;flex-direction:column;gap:12px;">
  <button class="btn btn-outline" onclick="toggleTheme()"><i class="bi bi-moon-stars"></i> ጨለማ ገጽታ ይቀያይሩ</button>
  <button class="btn btn-outline" id="profile-install-btn" onclick="installApp()"><i class="bi bi-phone"></i> መተግበሪያውን ይጫኑ</button>
</div>

<div class="section-title">የሚስጥር ቁልፍ ይቀይሩ</div>
<?php if ($error): ?><div class="card card-pad mb-2" style="border-left:4px solid var(--danger);color:var(--danger);font-size:.85rem;"><?= e($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="card card-pad mb-2" style="border-left:4px solid var(--success);color:var(--success);font-size:.85rem;"><?= e($ok) ?></div><?php endif; ?>
<form method="post" class="card card-pad mb-3">
  <?= csrf_field() ?>
  <div class="field"><label><?= __("current_password") ?></label><input class="input" type="password" name="current_password" required></div>
  <div class="field"><label><?= __("new_password") ?></label><input class="input" type="password" name="new_password" required></div>
  <button class="btn btn-navy btn-block">የሚስጥር ቁልፍ ያዘምኑ</button>
</form>

<a href="<?= $base ?>logout.php" class="btn btn-outline btn-block" style="color:var(--danger);border-color:var(--danger);"><i class="bi bi-box-arrow-right"></i> <?= __("sign_out") ?></a>

<?php include __DIR__ . '/../includes/footer.php'; ?>
