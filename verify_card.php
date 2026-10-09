<?php
/**
 * verify_card.php  —  Atsede Library
 * Public Digital Member ID Verification Pass.
 * Clean, modern, standalone mobile-first verification card designed for QR scanning.
 * Accessible by anyone without login. Shows only official ID card information.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

global $conn;

$token     = clean($_GET['token'] ?? $_GET['card_token'] ?? '');
$uid       = (int)($_GET['uid'] ?? 0);
$studentId = clean($_GET['student_id'] ?? $_GET['id'] ?? '');

$member = null;

if (!empty($token)) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.id, u.full_name, u.phone, u.status, u.profile_photo, u.created_at,
                m.id AS member_id, m.class, m.student_id, m.card_token, m.blocked_until
         FROM members m 
         JOIN users u ON u.id = m.user_id 
         WHERE m.card_token = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $token);
        mysqli_stmt_execute($stmt);
        $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

if (!$member && $uid > 0) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.id, u.full_name, u.phone, u.status, u.profile_photo, u.created_at,
                m.id AS member_id, m.class, m.student_id, m.card_token, m.blocked_until
         FROM users u 
         JOIN members m ON m.user_id = u.id 
         WHERE u.id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $uid);
        mysqli_stmt_execute($stmt);
        $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

if (!$member && !empty($studentId)) {
    $stmt = mysqli_prepare($conn,
        "SELECT u.id, u.full_name, u.phone, u.status, u.profile_photo, u.created_at,
                m.id AS member_id, m.class, m.student_id, m.card_token, m.blocked_until
         FROM members m 
         JOIN users u ON u.id = m.user_id 
         WHERE m.student_id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $studentId);
        mysqli_stmt_execute($stmt);
        $member = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
}

$libName = library_name($conn);
$photoUrl = '';
if ($member && !empty($member['profile_photo']) && file_exists(__DIR__ . '/' . $member['profile_photo'])) {
    $photoUrl = rtrim(BASE_URL, '/') . '/' . ltrim($member['profile_photo'], '/');
}

$idNumber = '';
if ($member) {
    $idNumber = !empty($member['student_id']) ? $member['student_id'] : 'አጸደቤይ' . sprintf('%02d', (int)$member['member_id']);
}

$isBlocked = $member && is_member_blocked($conn, (int)$member['id']);
$isActive  = $member && $member['status'] === 'active' && !$isBlocked;
?>
<!DOCTYPE html>
<html lang="am">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?= $member ? e($member['full_name']) . ' — ዲጂታል መታወቂያ' : 'የአባልነት ማረጋገጫ' ?></title>
  <link rel="stylesheet" href="assets/lib/fonts/fonts.css">
  <link rel="stylesheet" href="assets/lib/bootstrap-icons/bootstrap-icons.css">
  <style>
    :root {
      --primary-blue: #0047AB;
      --deep-navy: #0A2540;
      --golden-yellow: #FFB703;
      --gold-dark: #D97706;
      --bg-dark: #071526;
      --card-bg: #FFFFFF;
      --text-main: #0B2545;
      --text-muted: #64748B;
      --success: #10B981;
      --danger: #EF4444;
      --border-line: #E2E8F0;
    }
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      -webkit-tap-highlight-color: transparent;
    }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background: radial-gradient(circle at 50% 20%, #0D2847 0%, #051324 100%);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px 14px;
    }
    .wrapper {
      width: 100%;
      max-width: 440px;
      margin: 0 auto;
    }
    .pass-card {
      background: var(--card-bg);
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
      position: relative;
    }
    /* Brand Header */
    .pass-header {
      background: linear-gradient(135deg, var(--deep-navy) 0%, var(--primary-blue) 100%);
      padding: 20px 20px 18px;
      color: #fff;
      text-align: center;
      position: relative;
      border-bottom: 4px solid var(--golden-yellow);
    }
    .pass-header-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255, 183, 3, 0.18);
      border: 1px solid rgba(255, 183, 3, 0.45);
      border-radius: 30px;
      padding: 4px 14px;
      font-size: 0.72rem;
      font-weight: 800;
      color: var(--golden-yellow);
      letter-spacing: 0.6px;
      text-transform: uppercase;
      margin-bottom: 8px;
    }
    .pass-header-title {
      font-size: 1.05rem;
      font-weight: 900;
      line-height: 1.3;
      letter-spacing: 0.2px;
      color: #FFFFFF;
    }
    /* Pass Body */
    .pass-body {
      padding: 24px 20px 20px;
      text-align: center;
    }
    /* Avatar Photo Frame */
    .avatar-wrap {
      width: 110px;
      height: 110px;
      margin: -10px auto 14px;
      border-radius: 50%;
      padding: 4px;
      background: linear-gradient(135deg, var(--primary-blue), var(--golden-yellow));
      box-shadow: 0 8px 24px rgba(0, 71, 171, 0.25);
      position: relative;
    }
    .avatar-inner {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      overflow: hidden;
      background: #F1F5F9;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .avatar-inner img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .avatar-inner i {
      font-size: 3.5rem;
      color: #94A3B8;
    }
    .avatar-badge {
      position: absolute;
      bottom: 2px;
      right: 4px;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--success);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      border: 3px solid #fff;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .avatar-badge.blocked {
      background: var(--danger);
    }
    /* Member Name */
    .member-name {
      font-size: 1.45rem;
      font-weight: 900;
      color: var(--deep-navy);
      margin-bottom: 8px;
      line-height: 1.25;
      letter-spacing: -0.3px;
    }
    /* ID Number Pill */
    .id-pill {
      display: inline-flex;
      align-items: center;
      background: #0047AB;
      color: #FFB703;
      font-size: 1.05rem;
      font-weight: 900;
      padding: 6px 18px;
      border-radius: 20px;
      letter-spacing: 0.6px;
      box-shadow: 0 4px 14px rgba(0, 71, 171, 0.3);
      margin-bottom: 16px;
    }
    /* Verification Status Banner */
    .status-alert {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 0.85rem;
      font-weight: 800;
      margin-bottom: 18px;
    }
    .status-alert.active {
      background: rgba(16, 185, 129, 0.12);
      border: 1.5px solid rgba(16, 185, 129, 0.35);
      color: #065F46;
    }
    .status-alert.blocked {
      background: rgba(239, 68, 68, 0.12);
      border: 1.5px solid rgba(239, 68, 68, 0.35);
      color: #991B1B;
    }
    .status-alert.pending {
      background: rgba(245, 158, 11, 0.12);
      border: 1.5px solid rgba(245, 158, 11, 0.35);
      color: #92400E;
    }
    /* Info Grid */
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      text-align: left;
      margin-bottom: 18px;
    }
    .info-box {
      background: #F8FAFC;
      border: 1px solid var(--border-line);
      border-radius: 12px;
      padding: 10px 12px;
    }
    .info-box-label {
      font-size: 0.7rem;
      color: var(--text-muted);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      margin-bottom: 3px;
    }
    .info-box-val {
      font-size: 0.92rem;
      font-weight: 800;
      color: var(--deep-navy);
      word-break: break-word;
    }
    /* Official Seal Footnote */
    .official-seal {
      background: #F8FAFC;
      border-top: 1.5px dashed var(--border-line);
      padding: 14px 16px;
      font-size: 0.76rem;
      color: var(--text-muted);
      line-height: 1.5;
      text-align: center;
    }
    .official-seal i {
      color: var(--golden-yellow);
      font-size: 0.95rem;
      margin-right: 4px;
    }
    /* Scan timestamp */
    .verified-time {
      margin-top: 14px;
      font-size: 0.72rem;
      color: rgba(255, 255, 255, 0.6);
      text-align: center;
      letter-spacing: 0.4px;
    }
    /* Empty / Not Found */
    .not-found-card {
      background: #fff;
      border-radius: 20px;
      padding: 40px 24px;
      text-align: center;
      box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    .not-found-icon {
      font-size: 3.5rem;
      color: var(--danger);
      margin-bottom: 14px;
    }
  </style>
</head>
<body>

<div class="wrapper">
  <?php if (!$member): ?>
    <div class="not-found-card">
      <div class="not-found-icon"><i class="bi bi-shield-x"></i></div>
      <h2 style="color:var(--deep-navy);font-size:1.3rem;margin-bottom:8px;">የአባልነት መረጃ አልተገኘም</h2>
      <p style="color:var(--text-muted);font-size:.88rem;line-height:1.6;margin-bottom:20px;">
        ይህ የዲጂታል መታወቂያ QR ኮድ በቤተ-መጻሕፍቱ መዝገብ ውስጥ አልተገኘም ወይም ትክክል አይደለም።
      </p>
      <a href="<?= rel_base() ?>index.php" style="display:inline-block;padding:10px 24px;background:var(--primary-blue);color:#fff;text-decoration:none;border-radius:12px;font-weight:700;font-size:.88rem;">
        ወደ ዋና ገጽ
      </a>
    </div>
  <?php else: ?>
    <div class="pass-card">
      <!-- Header -->
      <div class="pass-header">
        <div class="pass-header-badge">
          <i class="bi bi-shield-check"></i> ይፋዊ ዲጂታል አባልነት ፓስ
        </div>
        <div class="pass-header-title">
          <?= e($libName) ?>
        </div>
      </div>

      <!-- Body -->
      <div class="pass-body">
        <!-- Photo -->
        <div class="avatar-wrap">
          <div class="avatar-inner">
            <?php if ($photoUrl): ?>
              <img src="<?= e($photoUrl) ?>" alt="<?= e($member['full_name']) ?>">
            <?php else: ?>
              <i class="bi bi-person-fill"></i>
            <?php endif; ?>
          </div>
          <?php if ($isActive): ?>
            <div class="avatar-badge" title="የተረጋገጠ ንቁ አባል"><i class="bi bi-check-lg"></i></div>
          <?php else: ?>
            <div class="avatar-badge blocked" title="የታገደ ወይም ያላለቀ"><i class="bi bi-exclamation"></i></div>
          <?php endif; ?>
        </div>

        <!-- Name -->
        <h1 class="member-name"><?= e($member['full_name']) ?></h1>

        <!-- ID Number (Without 'ID:' letter - pure ID only) -->
        <div>
          <span class="id-pill"><?= e($idNumber) ?></span>
        </div>

        <!-- Verification Status -->
        <?php if ($isBlocked): ?>
          <div class="status-alert blocked">
            <i class="bi bi-slash-circle-fill"></i> ይህ መለያ ለጊዜው ታግዷል (Suspended)
          </div>
        <?php elseif ($isActive): ?>
          <div class="status-alert active">
            <i class="bi bi-patch-check-fill" style="color:var(--success);"></i> ህጋዊና ንቁ የቤተ-መጻሕፍት አባል (Verified)
          </div>
        <?php elseif ($member['status'] === 'pending'): ?>
          <div class="status-alert pending">
            <i class="bi bi-hourglass-split"></i> ምዝገባው በመጠባበቅ ላይ ነው (Pending)
          </div>
        <?php else: ?>
          <div class="status-alert blocked">
            <i class="bi bi-x-circle-fill"></i> ንቁ ያልሆነ አባል (Inactive)
          </div>
        <?php endif; ?>

        <!-- Info Grid (Only clean member ID details) -->
        <div class="info-grid">
          <div class="info-box">
            <div class="info-box-label">መለያ ቁጥር</div>
            <div class="info-box-val" style="color:var(--primary-blue);"><?= e($idNumber) ?></div>
          </div>
          <div class="info-box">
            <div class="info-box-label">ክፍል ደረጃ</div>
            <div class="info-box-val"><?= e($member['class'] ?: '—') ?></div>
          </div>
          <div class="info-box">
            <div class="info-box-label">ስልክ ቁጥር</div>
            <div class="info-box-val"><?= e($member['phone'] ?: '—') ?></div>
          </div>
          <div class="info-box">
            <div class="info-box-label">የአባልነት ዘመን</div>
            <div class="info-box-val"><?= formatDate($member['created_at']) ?></div>
          </div>
        </div>

      </div>

      <!-- Official Seal -->
      <div class="official-seal">
        <i class="bi bi-patch-check-fill"></i>
        ይህ ዲጂታል መታወቂያ በአጸደ ትጉሃን ሰንበት ትምህርት ቤት ቤተ ይትባረክ ቤተ-መጽሃፍት የታወቀ ይፋዊ የዲጂታል አባልነት ማረጋገጫ ነው።
      </div>
    </div>

    <!-- Verified Timestamp -->
    <div class="verified-time">
      <i class="bi bi-clock-history me-1"></i> የተረጋገጠበት ቅጽበት፦ <?= date('M d, Y h:i A') ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
