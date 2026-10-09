<?php
/**
 * setup.php — RUN THIS ONCE after uploading, then delete or lock it.
 * Creates all tables (if missing) and sets a secure admin password.
 */

$lockFile = __DIR__ . '/database/.installed';
if (file_exists($lockFile)) {
    http_response_code(404);
    echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>";
    if (!defined('PHPUNIT_RUNNING')) {
        exit;
    }
    return;
}

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['setup_csrf_token'])) {
    $_SESSION['setup_csrf_token'] = bin2hex(random_bytes(32));
}

$done = false;
$error = '';
$newAdminPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['setup_csrf_token'] ?? '', $submittedToken)) {
        $error = 'የጥያቄው ትክክለኛነት አልተረጋገጠም (CSRF mismatch)። እባክዎ ገጹን አድሰው እንደገና ይሞክሩ።';
    } else {
        $newAdminPassword = trim($_POST['admin_password'] ?? '');
        if (strlen($newAdminPassword) < 12) {
            $error = 'የይለፍ ቃል ቢያንስ 12 ፊደላት ወይም ቁጥሮች መሆን አለበት።';
        } else {
            // Find schema file (production dump or schema)
            $schemaFile = __DIR__ . '/database/if0_41150294_atsede_library.sql';
            if (!file_exists($schemaFile)) {
                $schemaFile = __DIR__ . '/database/schema.sql';
            }

            $sql = file_exists($schemaFile) ? file_get_contents($schemaFile) : false;
            if ($sql === false) {
                $error = 'የዳታቤዝ ፋይል አልተገኘም።';
            } else {
                if (mysqli_multi_query($conn, $sql)) {
                    do {
                        if ($r = mysqli_store_result($conn)) {
                            mysqli_free_result($r);
                        }
                    } while (mysqli_more_results($conn) && mysqli_next_result($conn));

                    $hash = password_hash($newAdminPassword, PASSWORD_DEFAULT);
                    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE username = 'admin'");
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "s", $hash);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    // Write lock file to prevent re-execution
                    file_put_contents($lockFile, date('c') . " - Installed successfully\n");
                    unset($_SESSION['setup_csrf_token']);
                    $done = true;
                } else {
                    $error = mysqli_error($conn);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ማቀናበሪያ — አጸደ ቤተ መጻሕፍት</title>
<link rel="stylesheet" href="assets/css/style.css?v=3">
<link rel="stylesheet" href="assets/lib/fonts/fonts.css">
<link rel="stylesheet" href="assets/lib/bootstrap-icons/bootstrap-icons.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;">
<div style="max-width:420px;width:100%;">
  <?php if ($done): ?>
    <div class="card card-pad" style="text-align:center;">
      <i class="bi bi-check-circle" style="font-size:2.4rem;color:var(--success);"></i>
      <h2 class="font-display" style="color:var(--navy);">ማቀናበር ተጠናቅቋል</h2>
      <p>በተጠቃሚ ስም <strong>admin</strong> እና በመረጡት የሚስጥር ቁልፍ ይግቡ።</p>
      <p class="text-muted" style="font-size:.8rem;">ለደህንነት፣ የመቆለፊያ ፋይል ተፈጥሯል። ይህን ፋይል ከሰርቨሩ ላይ ቢያጠፉት ይመረጣል።</p>
      <a href="login.php" class="btn btn-navy btn-block">ወደ መግቢያ ይሂዱ</a>
    </div>
  <?php else: ?>
    <div class="card card-pad">
      <h2 class="font-display" style="color:var(--navy);">የአጸደ ቤተ መጻሕፍት ማቀናበሪያ</h2>
      <p class="text-muted" style="font-size:.85rem;">ይህ የዳታቤዝ ሰንጠረዦችን ይፈጥራል እና የአስተዳዳሪ የሚስጥር ቁልፍ ያስቀምጣል። መጀመሪያ <code>config.local.php</code> ትክክለኛ የዳታቤዝ መረጃ እንዳለው ያረጋግጡ።</p>
      <?php if ($error): ?><div style="color:var(--danger);font-size:.85rem;margin-bottom:10px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['setup_csrf_token'] ?? '') ?>">
        <div class="field">
          <label>አዲስ የአስተዳዳሪ የሚስጥር ቁልፍ (ቢያንስ 12 ፊደላት)</label>
          <input type="password" class="input" name="admin_password" placeholder="ቢያንስ 12 ፊደላት" minlength="12" required>
        </div>
        <button class="btn btn-gold btn-block">ማቀናበር ጀምር</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body></html>
