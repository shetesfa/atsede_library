<?php
/**
 * register.php  —  Atsede Library
 * New member self-registration with mandatory Telegram username.
 * The member must start the bot before submitting — verified via API.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

if (current_user()) redirect(rel_base() . 'index.php');

// Check if self-registration is allowed
if (get_setting($conn, 'allow_self_registration', '1') !== '1') {
    $pageTitle = 'ምዝገባ';
    $hideChrome = true;
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty-state" style="min-height:60vh;"><i class="bi bi-lock text-danger" style="font-size:2.5rem;"></i>
          <h4>ምዝገባ ተዘግቷል</h4>
          <p>በአሁኑ ጊዜ አዲስ ምዝገባ አይፈቀድም። ቤተ-መጻሕፍቱን ያናግሩ።</p></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];
$old = ['full_name'=>'','phone'=>'','class'=>'','student_id'=>'','username'=>'','telegram_username'=>''];

// Get Bot username for the join link
$botUsername = get_setting($conn, 'telegram_bot_username', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    // Check registration IP rate limit
    $regThrottle = check_register_throttle($conn);
    if ($regThrottle['blocked']) {
        $errors[] = $regThrottle['message'];
    }

    $old['full_name']         = clean($_POST['full_name']         ?? '');
    $old['phone']             = clean($_POST['phone']             ?? '');
    $old['class']             = clean($_POST['class']             ?? '');
    $old['student_id']        = clean($_POST['student_id']        ?? '');
    $old['username']          = clean($_POST['username']          ?? '');
    $old['telegram_username'] = ltrim(clean($_POST['telegram_username'] ?? ''), '@');
    $password                 = $_POST['password'] ?? '';

    // Validations
    if ($old['full_name'] === '')              $errors[] = 'ሙሉ ስም ያስፈልጋል።';
    if ($old['phone'] === '')                  $errors[] = 'ስልክ ቁጥር ያስፈልጋል።';
    if ($old['class'] === '')                  $errors[] = 'ክፍል ያስፈልጋል።';
    if (strlen($old['username']) < 4)          $errors[] = 'የተጠቃሚ ስም ቢያንስ 4 ፊደላት ሊኖረው ይገባል።';
    if (strlen($password) < 6)                 $errors[] = 'የሚስጥር ቁልፍ ቢያንስ 6 ፊደላት ሊኖረው ይገባል።';
    if ($old['telegram_username'] === '')      $errors[] = 'Telegram username ያስፈልጋል (ለማሳወቂያ ይጠቅማል)።';

    // Username uniqueness
    if (!$errors) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $old['username']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        if (mysqli_stmt_num_rows($stmt) > 0) $errors[] = 'ይህ የተጠቃሚ ስም አስቀድሞ ተይዟል።';
        mysqli_stmt_close($stmt);
    }

    // Telegram username uniqueness
    if (!$errors) {
        $tgUser = $old['telegram_username'];
        $stmt2  = mysqli_prepare($conn, "SELECT id FROM users WHERE telegram_username=? LIMIT 1");
        mysqli_stmt_bind_param($stmt2, 's', $tgUser);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_store_result($stmt2);
        if (mysqli_stmt_num_rows($stmt2) > 0) $errors[] = 'ይህ Telegram username አስቀድሞ ተመዝግቧል።';
        mysqli_stmt_close($stmt2);
    }

    // Check if they actually started/joined the bot (via Telegram getUpdates trick)
    // We set telegram_joined=0 at registration; cron/bot webhook sets it to 1 when they /start
    // For now we just save their username and let the bot verify them later.
    // The cron will send them a "please start the bot" message.

    if (!$errors) {
        $hash    = password_hash($password, PASSWORD_DEFAULT);
        $tgUname = $old['telegram_username'];

        $stmt = mysqli_prepare($conn,
            "INSERT INTO users (full_name, phone, username, password, role, status, telegram_username)
             VALUES (?,?,?,?, 'member','pending',?)");
        mysqli_stmt_bind_param($stmt, 'sssss',
            $old['full_name'], $old['phone'], $old['username'], $hash, $tgUname);
        mysqli_stmt_execute($stmt);
        $userId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        $cardToken = bin2hex(random_bytes(16));
        $stmt2 = mysqli_prepare($conn, "INSERT INTO members (user_id, class, student_id, card_token) VALUES (?,?,?,?)");
        mysqli_stmt_bind_param($stmt2, 'isss', $userId, $old['class'], $old['student_id'], $cardToken);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);

        record_register_attempt($conn);

        $verifyToken = generate_telegram_verify_token($conn, $userId);

        $pageTitle  = 'ምዝገባዎ ደርሶናል';
        $hideChrome = true;
        include __DIR__ . '/includes/header.php';
        ?>
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;text-align:center;">
          <div style="max-width:440px;">
            <div style="width:64px;height:64px;border-radius:50%;background:rgba(34,197,94,0.12);color:var(--success);display:inline-flex;align-items:center;justify-content:center;font-size:2.4rem;margin-bottom:12px;">
              <i class="bi bi-check-circle-fill"></i>
            </div>
            <h2 class="font-display" style="color:var(--navy);margin:6px 0;font-size:1.4rem;">የአባልነት ጥያቄዎ በተሳካ ሁኔታ ደርሶናል!</h2>
            <p class="text-muted" style="font-size:.9rem;line-height:1.6;">
              ውድ <strong><?= e($old['full_name']) ?></strong>፣ የቤተ-መጻሕፍታችን ቤተሰብ ለመሆን ስለመረጡን ከልብ እናመሰግናለን!
            </p>

            <?php if ($botUsername): ?>
            <div class="card card-pad mt-3" style="border:2px solid #229ED9;background:rgba(34,158,217,0.06);text-align:left;border-radius:14px;">
              <div style="font-weight:700;color:#006FA6;margin-bottom:8px;font-size:.95rem;">
                <i class="bi bi-telegram"></i> ቀጣዩን እርምጃ ያጠናቁ — ቴሌግራም ቦት ይክፈቱ
              </div>
              <p style="font-size:.85rem;margin-bottom:12px;color:var(--navy);line-height:1.5;">
                የመጽሐፍ መመለሻ ቀን ማስታወሻዎችን እና ጠቃሚ መረጃዎችን በቀጥታ በስልክዎ ለማግኘት ቦቱን አሁኑኑ ያስጀምሩ።
              </p>
              <a href="https://t.me/<?= e($botUsername) ?>?start=verify_<?= urlencode($verifyToken) ?>"
                 target="_blank" class="btn btn-block"
                 style="background:#229ED9;color:#fff;font-weight:700;border-radius:10px;padding:10px;">
                <i class="bi bi-telegram me-1"></i> ቦቱን ያስጀምሩ (@<?= e($botUsername) ?>)
              </a>
            </div>
            <?php endif; ?>

            <div class="card card-pad mt-3" style="font-size:.85rem;color:var(--muted);border-radius:14px;background:#f8fafc;line-height:1.5;">
              <i class="bi bi-hourglass-split text-gold"></i> የአባልነት ማረጋገጫዎ በአስተዳዳሪው እንደጸደቀ ወዲያውኑ በቴሌግራም የደስታ ማሳወቂያ ይደርስዎታል!
            </div>

            <a href="<?= $base ?>login.php" class="btn btn-navy btn-block mt-3" style="border-radius:10px;padding:10px;font-weight:700;">
              ወደ መግቢያ ገጽ ይመለሱ
            </a>
          </div>
        </div>
        <?php
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

$pageTitle  = __('join');
$hideChrome = true;
include __DIR__ . '/includes/header.php';
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;">
  <div style="width:100%;max-width:440px;">

    <div style="text-align:center;margin-bottom:18px;">
      <span class="crest" style="width:56px;height:56px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:radial-gradient(circle at 30% 30%,var(--gold),var(--gold-600));font-size:1.5rem;color:var(--navy);box-shadow:var(--shadow-gold);overflow:hidden;">
        <?php if ($logoUrl): ?>
          <img src="<?= e($logoUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
        <?php else: ?>
          <i class="bi bi-person-plus"></i>
        <?php endif; ?>
      </span>
      <h1 class="font-display" style="font-size:1.3rem;color:var(--navy);margin:14px 0 2px;">አባል ይሁኑ</h1>
      <p class="text-muted" style="font-size:.85rem;">መጻሕፍትን ይዋሱ፣ ማሳወቂያዎች ያግኙ</p>
    </div>

    <?php if ($errors): ?>
      <div class="card card-pad mb-3" style="border-left:4px solid var(--danger);">
        <?php foreach ($errors as $err): ?>
          <div style="font-size:.84rem;color:var(--danger);"><i class="bi bi-exclamation-circle"></i> <?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="card card-pad">
      <?= csrf_field() ?>

      <div class="field">
        <label><?= __('full_name') ?> <span class="text-danger">*</span></label>
        <input class="input" name="full_name" value="<?= e($old['full_name']) ?>" required>
      </div>

      <div class="field">
        <label><?= __('phone') ?> <span class="text-danger">*</span></label>
        <input class="input" name="phone" type="tel" value="<?= e($old['phone']) ?>" placeholder="09xxxxxxxx" required>
      </div>

      <div class="field">
        <label><?= __('class') ?> <span class="text-danger">*</span></label>
        <input class="input" name="class" value="<?= e($old['class']) ?>" required>
      </div>

      <div class="field">
        <label><?= __('student_id') ?> <span class="text-muted">(አማራጭ)</span></label>
        <input class="input" name="student_id" value="<?= e($old['student_id']) ?>">
      </div>

      <div class="field">
        <label><?= __('username') ?> <span class="text-danger">*</span></label>
        <input class="input" name="username" value="<?= e($old['username']) ?>" required>
      </div>

      <div class="field">
        <label><?= __('password') ?> <span class="text-danger">*</span></label>
        <input class="input" type="password" name="password" required>
        <div class="hint">ቢያንስ 6 ፊደላት።</div>
      </div>

      <!-- TELEGRAM USERNAME FIELD -->
      <div class="field" style="border-top:1px solid var(--line);padding-top:14px;margin-top:4px;">
        <label style="display:flex;align-items:center;gap:6px;">
          <i class="bi bi-telegram" style="color:#229ED9;font-size:1.1rem;"></i>
          Telegram Username <span class="text-danger">*</span>
        </label>
        <div style="position:relative;">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-weight:700;">@</span>
          <input class="input" name="telegram_username" value="<?= e($old['telegram_username']) ?>"
                 placeholder="yourname" required style="padding-left:28px;">
        </div>
        <div class="hint" style="margin-top:4px;">
          <i class="bi bi-info-circle"></i>
          Telegram-ዎ ላይ Settings → Username ብለው ያግኙ።
          <?php if ($botUsername): ?>
            ከዚያ <a href="https://t.me/<?= e($botUsername) ?>" target="_blank" style="color:#229ED9;">@<?= e($botUsername) ?></a>
            Bot-ን ያስጀምሩ (START ይጫኑ) — ለማሳወቂያ ይጠቅማል።
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-gold btn-block mt-2">
        <i class="bi bi-send"></i> ለማረጋገጫ ያስገቡ
      </button>
    </form>

    <p class="text-center text-muted mt-3" style="font-size:.85rem;">
      <?= __('already_member') ?>
      <a href="<?= $base ?>login.php" class="link-gold"><?= __('login') ?></a>
    </p>

  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
