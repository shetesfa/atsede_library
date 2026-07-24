<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

clear_expired_member_blocks($conn);

if (current_user()) redirect(rel_base() . 'index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $username);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row || !password_verify($password, $row['password'])) {
        $error = 'የተጠቃሚ ስም ወይም የሚስጥር ቁልፍ ትክክል አይደለም።';
    } elseif ($row['status'] === 'pending') {
        $error = 'መለያዎ በአስተዳዳሪ ማረጋገጫ በመጠባበቅ ላይ ነው።';
    } elseif ($row['status'] === 'rejected') {
        $error = 'ምዝገባዎ ተቀባይነት አላገኘም። ቤተ መጻሕፍቱን ያግኙ።';
    } elseif ($row['status'] === 'suspended') {
        $error = member_block_message($conn, $row['id']);
    } elseif (is_member_blocked($conn, $row['id'])) {
        $error = member_block_message($conn, $row['id']);
    } else {
        $_SESSION['user'] = [
            'id' => $row['id'], 'full_name' => $row['full_name'],
            'role' => $row['role'], 'username' => $row['username'],
        ];
        mysqli_query($conn, "UPDATE users SET last_login=NOW() WHERE id=" . (int)$row['id']);
        audit($conn, $row['id'], 'login', '');
        $target = ['admin' => 'admin/dashboard.php', 'librarian' => 'librarian/dashboard.php', 'member' => 'member/dashboard.php'][$row['role']] ?? 'index.php';
        redirect(rel_base() . $target);
    }
}

$pageTitle = __('login');
$hideChrome = true;
include __DIR__ . '/includes/header.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;">
  <div style="width:100%;max-width:380px;">
    <div style="text-align:center;margin-bottom:24px;">
      <span class="crest" style="width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:radial-gradient(circle at 30% 30%,var(--gold),var(--gold-600));font-size:1.5rem;color:var(--navy);box-shadow:var(--shadow-gold);overflow:hidden;"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><i class="bi bi-book-half"></i><?php endif; ?></span>
      <h1 class="font-display" style="font-size:1.3rem;color:var(--navy);margin:14px 0 2px;"><?= __('welcome_back') ?></h1>
      <p class="text-muted" style="font-size:.85rem;"><?= e($siteName) ?> <?= __("sign_in_sub") ?></p>
    </div>

    <?php if ($error): ?><div class="card card-pad mb-3" style="border-left:4px solid var(--danger);color:var(--danger);font-size:.85rem;"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="card card-pad">
      <?= csrf_field() ?>
      <div class="field">
        <label><?= __("username") ?></label>
        <div class="input-group"><i class="bi bi-person"></i><input class="input" name="username" required autofocus></div>
      </div>
      <div class="field">
        <label><?= __("password") ?></label>
        <div class="input-group"><i class="bi bi-lock"></i><input class="input" type="password" name="password" required></div>
      </div>
      <button class="btn btn-gold btn-block"><i class="bi bi-box-arrow-in-right"></i> <?= __("login") ?></button>
    </form>

    <p class="text-center text-muted mt-3" style="font-size:.85rem;">
      <?= __("new_here") ?> <a href="<?= $base ?>register.php" class="link-gold"><?= __("create_account") ?></a>
    </p>
    <p class="text-center mt-2"><a href="<?= $base ?>index.php" class="text-muted" style="font-size:.8rem;"><i class="bi bi-arrow-left"></i> <?= __("continue_as_guest") ?></a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
