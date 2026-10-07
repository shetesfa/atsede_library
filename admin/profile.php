<?php
/**
 * admin/profile.php  —  Atsede Library
 * Administrator Profile Page with Personal Telegram Account Integration
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();
$error = ''; 
$ok = '';

// Reload fresh user data
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $user['id']);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

// Handle Profile Updates (Name & Phone)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    csrf_verify();
    $fullName = clean($_POST['full_name'] ?? '');
    $phone    = clean($_POST['phone'] ?? '');

    if ($fullName === '') {
        $error = 'እባክዎ ሙሉ ስም ያስገቡ።';
    } else {
        $stmtUp = mysqli_prepare($conn, "UPDATE users SET full_name=?, phone=? WHERE id=?");
        mysqli_stmt_bind_param($stmtUp, 'ssi', $fullName, $phone, $user['id']);
        mysqli_stmt_execute($stmtUp);
        mysqli_stmt_close($stmtUp);
        $row['full_name'] = $fullName;
        $row['phone'] = $phone;
        $ok = 'መረጃዎ በተሳካ ሁኔታ ተሻሽሏል!';
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    csrf_verify();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    if (!password_verify($current, $row['password'])) {
        $error = 'የአሁኑ የሚስጥር ቁልፍ ትክክል አይደለም።';
    } elseif (strlen($new) < 6) {
        $error = 'አዲሱ የሚስጥር ቁልፍ ቢያንስ 6 ፊደላት ሊኖረው ይገባል።';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt2 = mysqli_prepare($conn, "UPDATE users SET password=? WHERE id=?");
        mysqli_stmt_bind_param($stmt2, 'si', $hash, $user['id']);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);
        audit($conn, $user['id'], 'password_changed', 'Admin updated password');
        $ok = 'የሚስጥር ቁልፍ በትክክል ተቀይሯል።';
    }
}

$tgJoined = (int)($row['telegram_joined'] ?? 0);
$tgChatId = $row['telegram_chat_id'] ?? '';
$botUsername = get_setting($conn, 'telegram_bot_username', 'Atsedeteguhan_library_bot');
$tgVerifyToken = !$tgJoined ? generate_telegram_verify_token($conn, $user['id']) : '';
$tgDeepLink = "https://t.me/{$botUsername}?start=verify_{$tgVerifyToken}";

$pageTitle = 'የአስተዳዳሪ መገለጫ (Profile)';
$activeKey = 'settings';
include __DIR__ . '/../includes/header.php';
?>

<div style="max-width:760px;margin:0 auto;padding-bottom:30px;">
  
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
    <div style="width:48px;height:48px;border-radius:14px;background:#0047AB;color:#FFB703;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:900;">
      <i class="bi bi-shield-lock-fill"></i>
    </div>
    <div>
      <h2 style="margin:0;font-size:1.35rem;font-weight:900;color:#0f172a;">የአስተዳዳሪ መገለጫ (Admin Profile)</h2>
      <div style="font-size:0.88rem;color:#64748b;">የመለያዎን ደህንነት እና የግል ቴሌግራምዎን ያስተዳድሩ</div>
    </div>
  </div>

  <?php if ($ok): ?>
    <div class="card card-pad mb-3" style="border-left:4px solid #16a34a;color:#16a34a;background:#f0fdf4;font-weight:700;">
      <i class="bi bi-check-circle-fill"></i> <?= e($ok) ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="card card-pad mb-3" style="border-left:4px solid #dc2626;color:#dc2626;background:#fef2f2;font-weight:700;">
      <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
    </div>
  <?php endif; ?>

  <!-- ── 1. Telegram Account Connection Card ── -->
  <div class="card card-pad mb-3" style="border:2px solid #229ED9;border-radius:16px;background:#f8fafc;box-shadow:0 4px 16px rgba(34,158,217,0.08);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:44px;height:44px;border-radius:12px;background:#229ED9;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.5rem;">
          <i class="bi bi-telegram"></i>
        </div>
        <div>
          <h3 style="margin:0;font-size:1.1rem;font-weight:800;color:#0f172a;">የእርስዎ የግል ቴሌግራም መለያ</h3>
          <div style="font-size:0.84rem;color:#64748b;">ለአስተዳዳሪ ማሳወቂያዎች እና ፈጣን ቁጥጥር</div>
        </div>
      </div>
      <div>
        <?php if ($tgJoined && $tgChatId): ?>
          <span style="display:inline-flex;align-items:center;gap:6px;background:#dcfce7;color:#15803d;padding:6px 14px;border-radius:20px;font-size:0.85rem;font-weight:800;">
            <i class="bi bi-check-circle-fill"></i> ተገናኝቷል (Connected)
          </span>
        <?php else: ?>
          <span style="display:inline-flex;align-items:center;gap:6px;background:#fee2e2;color:#991b1b;padding:6px 14px;border-radius:20px;font-size:0.85rem;font-weight:800;">
            <i class="bi bi-x-circle-fill"></i> አልተገናኘም
          </span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($tgJoined && $tgChatId): ?>
      <div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #e2e8f0;">
        <div style="font-size:0.92rem;color:#1e293b;line-height:1.6;">
          🎉 <b>የእርስዎ ቴሌግራም በተሳካ ሁኔታ ተገናኝቷል!</b><br>
          <span style="color:#64748b;">የቴሌግራም Chat ID፦</span> <code><?= e($tgChatId) ?></code>
        </div>
      </div>
    <?php else: ?>
      <div style="background:#fff;border-radius:12px;padding:16px;border:1px solid #e2e8f0;margin-bottom:14px;">
        <p style="margin:0 0 10px;font-size:0.92rem;color:#334155;line-height:1.6;">
          የእርስዎን የግል ቴሌግራም ከአጸደ ቤተ-መጻሕፍት ጋር ለማገናኘት ከታች ያለውን ሰማያዊ አዝራር ይጫኑ፤ ቴሌግራም ሲከፈት <b>Start</b> የሚለውን ይንኩ። ቦቱ በራሱ መለያዎን ያረጋግጣል።
        </p>
        <a href="<?= e($tgDeepLink) ?>" target="_blank" class="btn" style="background:#229ED9;color:#fff;font-weight:800;display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:10px;text-decoration:none;box-shadow:0 4px 12px rgba(34,158,217,0.3);">
          <i class="bi bi-telegram" style="font-size:1.3rem;"></i> ቴሌግራምዎን አሁን ያገናኙ (Connect Telegram)
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── 2. Account Details ── -->
  <div class="card card-pad mb-3">
    <div class="section-title" style="margin-top:0;">👤 የመለያ መረጃ</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="update_profile" value="1">

      <div class="field">
        <label>የተጠቃሚ ስም (Username)</label>
        <input class="input" value="<?= e($row['username']) ?>" disabled style="background:#f1f5f9;cursor:not-allowed;">
      </div>

      <div class="field">
        <label>ሙሉ ስም</label>
        <input class="input" name="full_name" value="<?= e($row['full_name']) ?>" required>
      </div>

      <div class="field">
        <label>ስልክ ቁጥር</label>
        <input class="input" name="phone" value="<?= e($row['phone']) ?>" placeholder="0911000000">
      </div>

      <button type="submit" class="btn btn-primary" style="background:#0047AB;">
        <i class="bi bi-save"></i> መረጃውን አድስ
      </button>
    </form>
  </div>

  <!-- ── 3. Password Change ── -->
  <div class="card card-pad mb-3">
    <div class="section-title" style="margin-top:0;">🔒 የሚስጥር ቁልፍ (Password) መቀየሪያ</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="change_password" value="1">

      <div class="field">
        <label>የአሁኑ የሚስጥር ቁልፍ</label>
        <input type="password" class="input" name="current_password" required>
      </div>

      <div class="field">
        <label>አዲስ የሚስጥር ቁልፍ</label>
        <input type="password" class="input" name="new_password" required minlength="6">
      </div>

      <button type="submit" class="btn btn-gold">
        <i class="bi bi-key-fill"></i> የሚስጥር ቁልፉን ቀይር
      </button>
    </form>
  </div>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
