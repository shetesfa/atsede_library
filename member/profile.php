<?php
/**
 * member/profile.php  —  Atsede Library
 * 2-Sided International Member ID Pass (Front & Back),
 * Profile Photo Upload, Telegram Sync, and Clean Security Controls.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$libName = library_name($conn);

// Load user & member details
$stmt = mysqli_prepare($conn,
    "SELECT u.*, m.id AS member_id, m.class, m.student_id, m.created_at AS member_since
     FROM users u JOIN members m ON m.user_id=u.id WHERE u.id=? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $user['id']);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$memberId  = (int)($row['member_id'] ?? 0);
$error = ''; $ok = '';

// 1. Photo Upload Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo_file'])) {
    csrf_verify();
    $file = $_FILES['photo_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $avatarDir = __DIR__ . '/../uploads/avatars';
        $uploadResult = secure_process_image($file['tmp_name'], $avatarDir, 'avatar_' . $user['id'], 5 * 1024 * 1024);
        if ($uploadResult['success']) {
            $dbPath = 'uploads/avatars/' . $uploadResult['file_name'];
            $stmtUp = mysqli_prepare($conn, "UPDATE users SET profile_photo=? WHERE id=?");
            mysqli_stmt_bind_param($stmtUp, 'si', $dbPath, $user['id']);
            mysqli_stmt_execute($stmtUp);
            mysqli_stmt_close($stmtUp);
            $row['profile_photo'] = $dbPath;
            $ok = 'የመታወቂያ ፎቶዎ በተሳካ ሁኔታ ተጭኗል!';
        } else {
            $error = $uploadResult['error'];
        }
    } else {
        $error = 'እባክዎ ምስል ይምረጡ።';
    }
}

// 2. Password Change Handler
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
        audit($conn, $user['id'], 'password_changed', '');
        $ok = 'የሚስጥር ቁልፍ በትክክል ተቀይሯል።';
    }
}

// Payment and borrow stats
$ps          = $memberId ? get_member_payment_status($conn, $memberId) : null;
$totalFine   = $memberId ? get_member_outstanding_fine($conn, $memberId) : 0;
$borrowCount = $memberId ? (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM borrow_records WHERE member_id=$memberId AND status='borrowed'"))['c'] : 0;

$idCardQrData = rtrim(BASE_URL, '/') . '/verify_card.php?token=' . urlencode($row['card_token'] ?? '') . '&uid=' . (int)$user['id'];
$tgJoined     = (int)($row['telegram_joined'] ?? 0);
$botUsername  = get_setting($conn, 'telegram_bot_username', 'Atsedeteguhan_library_bot');
$tgVerifyToken = !$tgJoined ? generate_telegram_verify_token($conn, $user['id']) : '';

// Standard Member ID format starting with 'አጸደቤይ' followed by numbers (አጸደቤይ01, አጸደቤይ02, ...)
if (!empty($row['student_id'])) {
    if (str_starts_with($row['student_id'], 'አጸደቤይ')) {
        $idFormatted = $row['student_id'];
    } else {
        $cleanNum = preg_replace('/[^0-9]/', '', $row['student_id']);
        $idFormatted = 'አጸደቤይ' . (strlen($cleanNum) < 2 ? str_pad($cleanNum, 2, '0', STR_PAD_LEFT) : $cleanNum);
    }
} else {
    $idFormatted = 'አጸደቤይ' . sprintf('%02d', $memberId);
}

$photoUrl = '';
if (!empty($row['profile_photo']) && file_exists(__DIR__ . '/../' . $row['profile_photo'])) {
    $photoUrl = rtrim(BASE_URL, '/') . '/' . ltrim($row['profile_photo'], '/');
}

$pageTitle = __('profile');
$activeKey = 'profile';
include __DIR__ . '/../includes/header.php';
?>

<!-- Title -->
<div class="section-title" style="margin-top:0;">
  <i class="bi bi-person-badge text-gold me-1"></i> የዲጂታል አባልነት መታወቂያ ካርድ (ዓለም አቀፍ ደረጃ)
</div>

<?php if ($error): ?>
  <div class="card card-pad mb-3" style="border-left:4px solid var(--danger);color:var(--danger);font-size:.86rem;border-radius:12px;">
    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= e($error) ?>
  </div>
<?php endif; ?>

<?php if ($ok): ?>
  <div class="card card-pad mb-3" style="border-left:4px solid var(--success);color:var(--success);font-size:.86rem;border-radius:12px;">
    <i class="bi bi-check-circle-fill me-1"></i> <?= e($ok) ?>
  </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- FLIP CARD CONTAINER (FRONT & BACK)                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div style="perspective:1000px;max-width:540px;margin:0 auto 16px;">
  <div id="id-card-flipper" style="position:relative;width:100%;transition:transform 0.6s;transform-style:preserve-3d;">

    <!-- ───── FRONT FACE (WHITE PROFESSIONAL WITH BRAND BLUE & YELLOW) ───── -->
    <div id="card-front" class="card" style="background:#FFFFFF;border:2px solid #0047AB;border-radius:20px;overflow:hidden;box-shadow:0 12px 35px rgba(0,71,171,0.15);color:#0B2545;min-height:310px;position:relative;">
      
      <!-- Top Brand Ribbon (Deep Royal Blue with Golden Yellow Accents) -->
      <div style="background:linear-gradient(135deg, #0A2540 0%, #0047AB 100%);padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:8px;color:#fff;border-bottom:3.5px solid #FFB703;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="width:38px;height:38px;border-radius:50%;background:#FFB703;display:inline-flex;align-items:center;justify-content:center;color:#0A2540;font-size:1.2rem;font-weight:900;box-shadow:0 2px 8px rgba(0,0,0,0.3);">
            <i class="bi bi-book-half"></i>
          </span>
          <div>
            <div style="font-weight:800;font-size:.95rem;color:#FFFFFF;line-height:1.2;letter-spacing:0.3px;">
              <?= e($libName) ?>
            </div>
            <div style="font-size:.7rem;color:#FFE57F;letter-spacing:0.5px;font-weight:700;margin-top:2px;">
              የአባልነት መታወቂያ ካርድ
            </div>
          </div>
        </div>
        <span class="badge" style="background:#FFB703;color:#0A2540;font-weight:900;font-size:.92rem;padding:6px 14px;border-radius:20px;letter-spacing:0.5px;box-shadow:0 2px 8px rgba(0,0,0,0.2);">
          <?= e($idFormatted) ?>
        </span>
      </div>

      <!-- Front Body -->
      <div style="padding:18px;display:flex;align-items:center;gap:18px;background:#FFFFFF;">
        <!-- Photo Frame (Double Border: Blue and Yellow) -->
        <div style="flex-shrink:0;text-align:center;">
          <div style="width:105px;height:120px;border-radius:14px;border:3px solid #0047AB;outline:2px solid #FFB703;overflow:hidden;background:#F8FAFC;box-shadow:0 4px 14px rgba(0,71,171,0.15);display:flex;align-items:center;justify-content:center;position:relative;">
            <?php if ($photoUrl): ?>
              <img src="<?= e($photoUrl) ?>" alt="Member Photo" style="width:100%;height:100%;object-fit:cover;display:block;">
            <?php else: ?>
              <i class="bi bi-person-fill" style="font-size:4rem;color:#94A3B8;"></i>
            <?php endif; ?>
          </div>
          <span class="badge" style="background:rgba(0,71,171,0.08);color:#0047AB;border:1.5px solid #0047AB;font-size:.68rem;margin-top:8px;display:inline-block;font-weight:800;border-radius:20px;padding:3px 8px;">
            <i class="bi bi-patch-check-fill" style="color:#FFB703;"></i> ንቁ አባል
          </span>
        </div>

        <!-- Info Fields -->
        <div style="flex:1;">
          <div style="font-size:.7rem;color:#D97706;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;">ሙሉ ስም</div>
          <div style="font-size:1.3rem;font-weight:900;color:#0A2540;line-height:1.2;margin-bottom:12px;">
            <?= e($row['full_name']) ?>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:.82rem;">
            <div>
              <div style="color:#64748B;font-size:.7rem;font-weight:600;">መለያ ቁጥር</div>
              <div style="font-weight:900;color:#0047AB;font-size:.95rem;"><?= e($idFormatted) ?></div>
            </div>
            <div>
              <div style="color:#64748B;font-size:.7rem;font-weight:600;">ክፍል ደረጃ</div>
              <div style="font-weight:700;color:#1E293B;"><?= e($row['class'] ?: '—') ?></div>
            </div>
            <div>
              <div style="color:#64748B;font-size:.7rem;font-weight:600;">ስልክ ቁጥር</div>
              <div style="font-weight:700;color:#1E293B;"><?= e($row['phone'] ?: '—') ?></div>
            </div>
            <div>
              <div style="color:#64748B;font-size:.7rem;font-weight:600;">የአባልነት ዘመን</div>
              <div style="font-weight:700;color:#1E293B;"><?= e(formatDate($row['member_since'])) ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Front Bottom Ribbon -->
      <div style="background:#F8FAFC;padding:10px 18px;border-top:1.5px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;font-size:.74rem;color:#64748B;font-weight:600;">
        <span><i class="bi bi-shield-check" style="color:#FFB703;"></i> ህጋዊና ዕውቅና ያለው የቤተ-መጻሕፍት መታወቂያ</span>
        <span style="font-weight:900;color:#0047AB;font-size:.82rem;"><?= e($idFormatted) ?></span>
      </div>
    </div>

    <!-- ───── BACK FACE (WHITE PROFESSIONAL WITH BRAND BLUE & YELLOW) ───── -->
    <div id="card-back" class="card" style="display:none;background:#FFFFFF;border:2px solid #0047AB;border-radius:20px;overflow:hidden;box-shadow:0 12px 35px rgba(0,71,171,0.15);color:#0B2545;min-height:310px;text-align:center;">
      
      <!-- Top Brand Ribbon -->
      <div style="background:linear-gradient(135deg, #0A2540 0%, #0047AB 100%);padding:14px 18px;border-bottom:3.5px solid #FFB703;display:flex;align-items:center;justify-content:space-between;color:#fff;">
        <span style="font-size:.95rem;font-weight:800;color:#FFFFFF;letter-spacing:0.5px;">
          <?= e($libName) ?>
        </span>
        <span class="badge" style="background:#FFB703;color:#0A2540;font-weight:800;font-size:.78rem;padding:5px 12px;border-radius:14px;">
          ማረጋገጫ QR
        </span>
      </div>

      <!-- Back Body: QR Code Centered -->
      <div style="padding:20px 18px;display:flex-direction:column;align-items:center;justify-content:center;background:#FFFFFF;">
        
        <div style="background:#fff;padding:12px;border-radius:16px;box-shadow:0 6px 20px rgba(0,71,171,0.12);display:inline-block;border:3px solid #FFB703;outline:1.5px solid #0047AB;">
          <div id="memberIdQrCanvas" style="display:block;"></div>
        </div>

        <div style="font-size:.95rem;font-weight:800;color:#0A2540;margin-top:14px;">
          ማንነትን ለማረጋገጥ ይህንን QR ኮድ በስልክ ስካን ያድርጉ
        </div>
        <div style="font-size:.78rem;color:#64748B;max-width:380px;margin:4px auto 0;line-height:1.5;">
          ይህ ዲጂታል መታወቂያ የአጸደ ትጉሃን ሰንበት ትምህርት ቤት ቤተ ይትባረክ ቤተ-መጽሃፍት ንብረት ነው።
        </div>
      </div>

      <!-- Back Bottom -->
      <div style="background:#F8FAFC;padding:10px 18px;border-top:1.5px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;font-size:.74rem;color:#64748B;font-weight:600;">
        <span>የአባል መለያ ቁጥር</span>
        <span style="font-weight:900;color:#0047AB;font-size:.82rem;"><?= e($idFormatted) ?></span>
      </div>
    </div>

  </div>
</div>

<!-- Controls: Flip Button & Download Button -->
<div class="d-flex justify-content-center gap-2 mb-4" style="max-width:540px;margin:0 auto 20px;">
  <button type="button" onclick="flipIdCard()" class="btn btn-navy" style="border-radius:20px;padding:8px 18px;font-weight:700;font-size:.85rem;">
    <i class="bi bi-arrow-repeat me-1"></i> <span id="flip-btn-text">የካርዱን ጀርባ ይመልከቱ (QR ኮድ)</span>
  </button>
  <button type="button" onclick="downloadMemberIdCard()" class="btn btn-gold" style="border-radius:20px;padding:8px 18px;font-weight:700;font-size:.85rem;">
    <i class="bi bi-download me-1"></i> ካርዱን አውርድ (PNG)
  </button>
</div>

<!-- Hidden Canvas for PNG Export -->
<canvas id="fullIdCardCanvas" style="display:none;"></canvas>

<!-- ───── PHOTO UPLOAD CARD ───── -->
<div class="card card-pad mb-4" style="border-radius:14px;border:1.5px solid var(--line);">
  <div style="font-weight:800;color:var(--navy);font-size:.95rem;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-camera-fill text-gold"></i> የመታወቂያ ፎቶ ይጫኑ ወይም ይቀይሩ
  </div>
  <p class="text-muted" style="font-size:.82rem;margin-bottom:12px;">
    በመታወቂያ ካርድዎ ላይ የሚታይ ግልጽ የፊት ፎቶዎን እዚህ ይጫኑ (JPG, PNG ወይም WEBP)።
  </p>

  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2">
    <?= csrf_field() ?>
    <input type="file" name="photo_file" accept="image/*" class="input" style="flex:1;min-width:200px;padding:7px 10px;font-size:.84rem;" required>
    <button type="submit" class="btn btn-navy btn-sm" style="border-radius:8px;padding:8px 18px;font-weight:700;">
      <i class="bi bi-upload me-1"></i> ፎቶ ጫን
    </button>
  </form>
</div>

<!-- ───── TELEGRAM BOT NOTIFICATIONS ───── -->
<div class="section-title">
  <i class="bi bi-telegram" style="color:#229ED9;"></i> የቴሌግራም ማሳወቂያ ሁኔታ
</div>

<div class="card card-pad mb-4" style="border-radius:14px;border:1.5px solid var(--line);">
  <?php if ($tgJoined): ?>
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(34,197,94,0.12);color:var(--success);display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;">
        <i class="bi bi-check-circle-fill"></i>
      </div>
      <div>
        <div style="font-weight:800;color:var(--navy);font-size:.95rem;">ቴሌግራም በተሳካ ሁኔታ ተገናኝቷል ✅</div>
        <div class="text-muted" style="font-size:.82rem;margin-top:2px;">
          መለያ፦ <strong>@<?= e($row['telegram_username'] ?: '—') ?></strong> — የውሰት ማስታወሻዎች እና የክፍያ መረጃዎች ይደርሱዎታል።
        </div>
      </div>
    </div>
  <?php else: ?>
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:44px;height:44px;border-radius:50%;background:rgba(34,158,217,0.12);color:#229ED9;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;">
        <i class="bi bi-telegram"></i>
      </div>
      <div style="flex:1;">
        <div style="font-weight:800;color:var(--navy);font-size:.95rem;">ቴሌግራም ገና አልተገናኘም</div>
        <div class="text-muted" style="font-size:.82rem;margin-top:2px;">
          የመመለሻ ቀን ማስታወሻዎች እና የቤተ-መጻሕፍቱ ፈጣን መልዕክቶች እንዲደርስዎት ቦቱን ያስጀምሩ።
        </div>
        <?php if ($botUsername && $tgVerifyToken): ?>
          <a href="https://t.me/<?= e($botUsername) ?>?start=verify_<?= urlencode($tgVerifyToken) ?>"
             target="_blank" class="btn btn-sm mt-2"
             style="background:#229ED9;color:#fff;font-weight:700;border-radius:20px;padding:6px 16px;">
            <i class="bi bi-telegram me-1"></i> ቦቱን ያስጀምሩ (@<?= e($botUsername) ?>)
          </a>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ───── PASSWORD CHANGE ───── -->
<div class="section-title">
  <i class="bi bi-key text-gold me-1"></i> የሚስጥር ቁልፍ ይቀይሩ
</div>

<form method="post" class="card card-pad mb-4" style="border-radius:14px;border:1.5px solid var(--line);">
  <?= csrf_field() ?>
  <input type="hidden" name="change_password" value="1">

  <div class="field">
    <label><?= __('current_password') ?> <span class="text-danger">*</span></label>
    <input class="input" type="password" name="current_password" required placeholder="የአሁኑን የሚስጥር ቁልፍ ያስገቡ">
  </div>

  <div class="field">
    <label><?= __('new_password') ?> <span class="text-danger">*</span></label>
    <input class="input" type="password" name="new_password" required placeholder="አዲሱን የሚስጥር ቁልፍ ያስገቡ (ቢያንስ 6 ፊደላት)">
  </div>

  <button type="submit" class="btn btn-navy btn-block" style="border-radius:10px;padding:10px;font-weight:700;">
    <i class="bi bi-shield-lock me-1"></i> የሚስጥር ቁልፍ ያዘምኑ
  </button>
</form>

<!-- ───── LOGOUT ───── -->
<div class="mb-4 text-center">
  <a href="<?= $base ?>logout.php" class="btn btn-outline btn-block"
     style="color:var(--danger);border-color:var(--danger);border-radius:10px;padding:10px;font-weight:700;">
    <i class="bi bi-box-arrow-right me-1"></i> ከመለያዎ ይውጡ
  </a>
</div>

<!-- Scripts for QR & High-Res PNG Download -->
<script src="<?= $base ?>assets/js/qrcode.min.js"></script>
<script>
(function() {
  var qrContainer = document.getElementById('memberIdQrCanvas');
  var qrData      = <?= json_encode($idCardQrData) ?>;
  var memName     = <?= json_encode($row['full_name']) ?>;
  var memId       = <?= json_encode($idFormatted) ?>;
  var libName     = <?= json_encode($libName) ?>;
  var memClass    = <?= json_encode($row['class'] ?: '—') ?>;
  var memPhone    = <?= json_encode($row['phone'] ?: '—') ?>;
  var photoSrc    = <?= json_encode($photoUrl) ?>;

  // Render QR Code inside back container
  var qrCodeInstance = null;
  if (qrContainer && typeof QRCode !== 'undefined') {
    qrCodeInstance = new QRCode(qrContainer, {
      text:         qrData,
      width:        140,
      height:       140,
      colorDark:    '#0A1128',
      colorLight:   '#ffffff',
      correctLevel: QRCode.CorrectLevel.H
    });
  }

  // Flip Card logic
  var isBack = false;
  window.flipIdCard = function() {
    isBack = !isBack;
    var front = document.getElementById('card-front');
    var back  = document.getElementById('card-back');
    var btnText = document.getElementById('flip-btn-text');

    if (isBack) {
      front.style.display = 'none';
      back.style.display  = 'block';
      btnText.innerText   = 'የካርዱን ፊት ይመልከቱ';
    } else {
      front.style.display = 'block';
      back.style.display  = 'none';
      btnText.innerText   = 'የካርዱን ጀርባ ይመልከቱ (QR ኮድ)';
    }
  };

  // Download High-Res PNG (Includes both Front & Back Side-by-Side!)
  window.downloadMemberIdCard = function() {
    var fc = document.getElementById('fullIdCardCanvas');
    fc.width  = 1280;
    fc.height = 420;
    var ctx = fc.getContext('2d');

    // Background Fill
    ctx.fillStyle = '#070C1E';
    ctx.fillRect(0, 0, 1280, 420);

    // ─────────────────────────────────────────
    // DRAW CARD 1: FRONT PASS (WHITE PROFESSIONAL) (Left: 30 to 620)
    // ─────────────────────────────────────────
    ctx.fillStyle = '#FFFFFF';
    roundRect(ctx, 30, 20, 590, 380, 18, true, false);

    // Border (Royal Blue)
    ctx.strokeStyle = '#0047AB';
    ctx.lineWidth = 2.5;
    roundRect(ctx, 30, 20, 590, 380, 18, false, true);

    // Front Header Bar (Deep Blue)
    ctx.fillStyle = '#0A2540';
    ctx.fillRect(32, 22, 586, 56);

    // Yellow Accent Stripe under Header
    ctx.fillStyle = '#FFB703';
    ctx.fillRect(32, 75, 586, 3.5);
    
    // Header Crest Icon (Yellow Circle)
    ctx.fillStyle = '#FFB703';
    ctx.beginPath();
    ctx.arc(60, 50, 16, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = '#FFFFFF';
    ctx.font = 'bold 16px sans-serif';
    ctx.fillText(libName, 88, 48);

    ctx.fillStyle = '#FFE57F';
    ctx.font = 'bold 11px sans-serif';
    ctx.fillText('የአባልነት መታወቂያ ካርድ', 88, 66);

    // ID Badge on Header (Golden Yellow Badge with Blue Text)
    ctx.fillStyle = '#FFB703';
    roundRect(ctx, 490, 35, 110, 28, 14, true, false);
    ctx.fillStyle = '#0A2540';
    ctx.font = 'bold 13px sans-serif';
    ctx.fillText(memId, 505, 54);

    // Photo Box on Front (Royal Blue Frame with Yellow Accent)
    ctx.fillStyle = '#F8FAFC';
    ctx.fillRect(55, 105, 115, 135);
    ctx.strokeStyle = '#0047AB';
    ctx.lineWidth = 2.5;
    ctx.strokeRect(55, 105, 115, 135);

    var drawInfoAndFinish = function(userImg) {
      if (userImg) {
        try {
          ctx.drawImage(userImg, 56, 106, 113, 133);
        } catch(e) {}
      } else {
        ctx.fillStyle = '#94A3B8';
        ctx.font = 'bold 50px sans-serif';
        ctx.fillText('👤', 85, 190);
      }

      // Member Text
      ctx.fillStyle = '#D97706';
      ctx.font = 'bold 11px sans-serif';
      ctx.fillText('ሙሉ ስም', 190, 120);

      ctx.fillStyle = '#0A2540';
      ctx.font = 'bold 22px sans-serif';
      ctx.fillText(memName, 190, 148);

      ctx.fillStyle = '#64748B';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText('መለያ ቁጥር', 190, 185);
      ctx.fillStyle = '#0047AB';
      ctx.font = 'bold 16px sans-serif';
      ctx.fillText(memId, 190, 205);

      ctx.fillStyle = '#64748B';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText('ክፍል ደረጃ', 350, 185);
      ctx.fillStyle = '#1E293B';
      ctx.font = 'bold 15px sans-serif';
      ctx.fillText(memClass || '—', 350, 205);

      ctx.fillStyle = '#64748B';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText('ስልክ ቁጥር', 190, 235);
      ctx.fillStyle = '#1E293B';
      ctx.font = 'bold 15px sans-serif';
      ctx.fillText(memPhone || '—', 190, 255);

      // Active Badge
      ctx.fillStyle = 'rgba(0,71,171,0.08)';
      roundRect(ctx, 55, 250, 115, 24, 6, true, false);
      ctx.strokeStyle = '#0047AB';
      ctx.lineWidth = 1.5;
      roundRect(ctx, 55, 250, 115, 24, 6, false, true);
      ctx.fillStyle = '#0047AB';
      ctx.font = 'bold 11px sans-serif';
      ctx.fillText('✓ ንቁ አባል', 82, 266);

      // Front Footer
      ctx.fillStyle = '#F8FAFC';
      ctx.fillRect(32, 350, 586, 48);
      ctx.strokeStyle = '#E2E8F0';
      ctx.strokeRect(32, 350, 586, 1);
      ctx.fillStyle = '#64748B';
      ctx.font = '11px sans-serif';
      ctx.fillText('ህጋዊና ዕውቅና ያለው የቤተ-መጻሕፍት መታወቂያ', 55, 378);

      ctx.fillStyle = '#0047AB';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText(memId, 520, 378);

      // ─────────────────────────────────────────
      // DRAW CARD 2: BACK PASS (WHITE PROFESSIONAL) (Right: 660 to 1250)
      // ─────────────────────────────────────────
      ctx.fillStyle = '#FFFFFF';
      roundRect(ctx, 660, 20, 590, 380, 18, true, false);

      ctx.strokeStyle = '#0047AB';
      ctx.lineWidth = 2.5;
      roundRect(ctx, 660, 20, 590, 380, 18, false, true);

      // Back Header Bar
      ctx.fillStyle = '#0A2540';
      ctx.fillRect(662, 22, 586, 56);

      // Yellow Accent Stripe
      ctx.fillStyle = '#FFB703';
      ctx.fillRect(662, 75, 586, 3.5);

      ctx.fillStyle = '#FFFFFF';
      ctx.font = 'bold 16px sans-serif';
      ctx.fillText(libName, 690, 55);

      ctx.fillStyle = '#FFB703';
      roundRect(ctx, 1110, 35, 115, 28, 14, true, false);
      ctx.fillStyle = '#0A2540';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText('ማረጋገጫ QR', 1130, 54);

      // Grab QR Code from DOM
      var qrSource = qrContainer.querySelector('canvas') || qrContainer.querySelector('img');
      if (qrSource) {
        try {
          ctx.fillStyle = '#FFFFFF';
          ctx.fillRect(865, 100, 180, 180);
          ctx.strokeStyle = '#FFB703';
          ctx.lineWidth = 3;
          ctx.strokeRect(865, 100, 180, 180);
          ctx.drawImage(qrSource, 875, 110, 160, 160);
        } catch(e) {
          console.error(e);
        }
      }

      ctx.fillStyle = '#0A2540';
      ctx.font = 'bold 14px sans-serif';
      ctx.fillText('ማንነትን ለማረጋገጥ ይህንን QR ኮድ በስልክ ስካን ያድርጉ', 740, 310);

      ctx.fillStyle = '#64748B';
      ctx.font = '12px sans-serif';
      ctx.fillText('ይህ ዲጂታል መታወቂያ የአጸደ ትጉሃን ሰንበት ትምህርት ቤት ቤተ ይትባረክ ቤተ-መጽሃፍት ንብረት ነው።', 700, 335);

      // Back Footer
      ctx.fillStyle = '#F8FAFC';
      ctx.fillRect(662, 350, 586, 48);
      ctx.fillStyle = '#64748B';
      ctx.font = '11px sans-serif';
      ctx.fillText('የአባል መለያ ቁጥር', 685, 378);

      ctx.fillStyle = '#0047AB';
      ctx.font = 'bold 12px sans-serif';
      ctx.fillText(memId, 1160, 378);

      // Trigger Download
      var link      = document.createElement('a');
      link.download = 'Library_Pass_' + memId.replace(/[^a-zA-Z0-9_-]/g, '_') + '.png';
      link.href     = fc.toDataURL('image/png');
      link.click();
    };

    // Load photo if available
    if (photoSrc) {
      var img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = function() { drawInfoAndFinish(img); };
      img.onerror = function() { drawInfoAndFinish(null); };
      img.src = photoSrc;
    } else {
      drawInfoAndFinish(null);
    }
  };

  // Canvas helper for rounded rectangles
  function roundRect(ctx, x, y, width, height, radius, fill, stroke) {
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + width - radius, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
    ctx.lineTo(x + width, y + height - radius);
    ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
    ctx.lineTo(x + radius, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
    if (fill) ctx.fill();
    if (stroke) ctx.stroke();
  }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
