<?php
/**
 * setup.php — RUN THIS ONCE after uploading, then delete it.
 * Creates all tables (if missing) and sets a secure admin password.
 */
$host = "localhost"; $user = "root"; $pass = ""; $db = "atsede_library";

$conn = mysqli_connect($host, $user, $pass);
if (!$conn) die('የዳታቤዝ ግንኙነት አልተሳካም: ' . mysqli_connect_error());

$sql = file_get_contents(__DIR__ . '/database/schema.sql');
if ($sql === false) die('schema.sql አልተገኘም።');

$done = false; $error = '';
$newAdminPassword = 'Admin@123';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newAdminPassword = $_POST['admin_password'] ?? 'Admin@123';
    if (mysqli_multi_query($conn, $sql)) {
        do { if ($r = mysqli_store_result($conn)) mysqli_free_result($r); } while (mysqli_more_results($conn) && mysqli_next_result($conn));
        mysqli_select_db($conn, $db);
        $hash = password_hash($newAdminPassword, PASSWORD_DEFAULT);
        mysqli_query($conn, "UPDATE users SET password='" . mysqli_real_escape_string($conn, $hash) . "' WHERE username='admin'");
        $done = true;
    } else {
        $error = mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ማቀናበሪያ — አጸደ ቤተ መጻሕፍት</title>
<link rel="stylesheet" href="assets/css/style.css?v=3">
<link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@600&family=Noto+Sans+Ethiopic:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;">
<div style="max-width:420px;width:100%;">
  <?php if ($done): ?>
    <div class="card card-pad" style="text-align:center;">
      <i class="bi bi-check-circle" style="font-size:2.4rem;color:var(--success);"></i>
      <h2 class="font-display" style="color:var(--navy);">ማቀናበር ተጠናቅቋል</h2>
      <p>በተጠቃሚ ስም <strong>admin</strong> እና በመረጡት የሚስጥር ቁልፍ ይግቡ።</p>
      <p class="text-muted" style="font-size:.8rem;">ለደህንነት፣ አሁን setup.php ከሰርቪሩ ላይ ያጥፉ።</p>
      <a href="login.php" class="btn btn-navy btn-block">ወደ መግቢያ ይሂዱ</a>
    </div>
  <?php else: ?>
    <div class="card card-pad">
      <h2 class="font-display" style="color:var(--navy);">የአጸደ ቤተ መጻሕፍት ማቀናበሪያ</h2>
      <p class="text-muted" style="font-size:.85rem;">ይህ የዳታቤዝ ሰንጠረዦችን ይፈጥራል እና የአስተዳዳሪ የሚስጥር ቁልፍ ያስቀምጣል። መጀመሪያ <code>config.php</code> ትክክለኛ የዳታቤዝ መረጃ እንዳለው ያረጋግጡ።</p>
      <?php if ($error): ?><div style="color:var(--danger);font-size:.85rem;margin-bottom:10px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <div class="field"><label>አዲስ የአስተዳዳሪ የሚስጥር ቁልፍ</label><input class="input" name="admin_password" value="Admin@123" required></div>
        <button class="btn btn-gold btn-block">ማቀናበር ጀምር</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body></html>
