<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

if (current_user()) redirect(rel_base() . 'index.php');

$errors = [];
$old = ['full_name'=>'', 'phone'=>'', 'class'=>'', 'student_id'=>'', 'username'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $old['full_name'] = clean($_POST['full_name'] ?? '');
    $old['phone'] = clean($_POST['phone'] ?? '');
    $old['class'] = clean($_POST['class'] ?? '');
    $old['student_id'] = clean($_POST['student_id'] ?? '');
    $old['username'] = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($old['full_name'] === '') $errors[] = 'ሙሉ ስም ያስፈልጋል።';
    if ($old['phone'] === '') $errors[] = 'ስልክ ቁጥር ያስፈልጋል።';
    if ($old['class'] === '') $errors[] = 'ክፍል ያስፈልጋል።';
    if (strlen($old['username']) < 4) $errors[] = 'የተጠቃሚ ስም ቢያንስ 4 ፊደላት ሊኖረው ይገባል።';
    if (strlen($password) < 6) $errors[] = 'የሚስጥር ቁልፍ ቢያንስ 6 ፊደላት ሊኖረው ይገባል።';

    if (!$errors) {
        $check = mysqli_query($conn, "SELECT id FROM users WHERE username='" . mysqli_real_escape_string($conn, $old['username']) . "'");
        if (mysqli_num_rows($check) > 0) $errors[] = 'ይህ የተጠቃሚ ስም አስቀድሞ ተይዟል።';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, phone, username, password, role, status) VALUES (?,?,?,?, 'member','pending')");
        mysqli_stmt_bind_param($stmt, 'ssss', $old['full_name'], $old['phone'], $old['username'], $hash);
        mysqli_stmt_execute($stmt);
        $userId = mysqli_insert_id($conn);

        $stmt2 = mysqli_prepare($conn, "INSERT INTO members (user_id, class, student_id) VALUES (?,?,?)");
        mysqli_stmt_bind_param($stmt2, 'iss', $userId, $old['class'], $old['student_id']);
        mysqli_stmt_execute($stmt2);

        audit($conn, $userId, 'member_registered', '');
        $pageTitle = 'ተመዝግቧል';
        $hideChrome = true;
        include __DIR__ . '/includes/header.php';
        ?>
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;text-align:center;">
          <div style="max-width:380px;">
            <i class="bi bi-check-circle" style="font-size:3rem;color:var(--success);"></i>
            <h2 class="font-display" style="color:var(--navy);margin:14px 0 6px;">ጥያቄዎ ደርሷል</h2>
            <p class="text-muted">እናመስግናለን፣ <?= e($old['full_name']) ?>። የአባልነት ጥያቄዎ በአስተዳዳሪ ማረጋገጫ በመጠባበቅ ላይ ነው። ሲጸድቅ እናሳውቅዎታለን።</p>
            <a href="<?= $base ?>login.php" class="btn btn-navy btn-block mt-3">ወደ መግቢያ ይሂዱ</a>
          </div>
        </div>
        <?php
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

$pageTitle = __('join');
$hideChrome = true;
include __DIR__ . '/includes/header.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;">
  <div style="width:100%;max-width:420px;">
    <div style="text-align:center;margin-bottom:18px;">
      <span class="crest" style="width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:radial-gradient(circle at 30% 30%,var(--gold),var(--gold-600));font-size:1.5rem;color:var(--navy);box-shadow:var(--shadow-gold);overflow:hidden;"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><i class="bi bi-person-plus"></i><?php endif; ?></span>
      <h1 class="font-display" style="font-size:1.3rem;color:var(--navy);margin:14px 0 2px;">አባል ይሁኑ</h1>
      <p class="text-muted" style="font-size:.85rem;">መጻሕፍትን ይዋሱ እና አዳዲስ መጻሕፍት ሲገቡ ይወቁ</p>
    </div>

    <?php if ($errors): ?>
      <div class="card card-pad mb-3" style="border-left:4px solid var(--danger);">
        <?php foreach ($errors as $err): ?><div style="font-size:.84rem;color:var(--danger);"><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="card card-pad">
      <?= csrf_field() ?>
      <div class="field"><label><?= __("full_name") ?></label><input class="input" name="full_name" value="<?= e($old['full_name']) ?>" required></div>
      <div class="field"><label><?= __("phone") ?></label><input class="input" name="phone" value="<?= e($old['phone']) ?>" required></div>
      <div class="field"><label><?= __("class") ?></label><input class="input" name="class" value="<?= e($old['class']) ?>" required></div>
      <div class="field"><label><?= __("student_id") ?> <span class="text-muted">(አማራጭ)</span></label><input class="input" name="student_id" value="<?= e($old['student_id']) ?>"></div>
      <div class="field"><label><?= __("username") ?></label><input class="input" name="username" value="<?= e($old['username']) ?>" required></div>
      <div class="field"><label><?= __("password") ?></label><input class="input" type="password" name="password" required><div class="hint">ቢያንስ 6 ፊደላት።</div></div>
      <button class="btn btn-gold btn-block"><i class="bi bi-send"></i> ለማረጋገጫ ያስገቡ</button>
    </form>
    <p class="text-center text-muted mt-3" style="font-size:.85rem;"><?= __("already_member") ?> <a href="<?= $base ?>login.php" class="link-gold"><?= __("login") ?></a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
