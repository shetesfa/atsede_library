<?php
/**
 * admin/settings.php  —  Atsede Library
 * Library settings: name, borrow rules, payment, Telegram bot, overdue fines.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $settingsToUpdate = [
        'library_name'              => clean($_POST['library_name']              ?? 'አጸደ ቤተ-መጻሕፍት'),
        'borrow_days'               => max(1, (int)($_POST['borrow_days']               ?? 14)),
        'max_active_borrows'        => max(1, (int)($_POST['max_active_borrows']        ?? 3)),
        'minimum_monthly_payment'   => max(1.0, (float)($_POST['minimum_monthly_payment']  ?? 50.0)),
        'reminder_days_before_due'  => max(1, (int)($_POST['reminder_days_before_due']  ?? 2)),
        'allow_self_registration'   => isset($_POST['allow_self_registration'])  ? '1' : '0',
        // Telegram
        'telegram_bot_token'        => clean($_POST['telegram_bot_token']        ?? ''),
        'telegram_bot_username'     => ltrim(clean($_POST['telegram_bot_username'] ?? ''), '@'),
        // Fines
        'overdue_fine_per_day'      => max(0, (float)($_POST['overdue_fine_per_day']    ?? 5)),
        'fine_grace_days'           => max(0, (int)($_POST['fine_grace_days']           ?? 0)),
        'max_unpaid_fine'           => max(0, (float)($_POST['max_unpaid_fine']          ?? 0)),
    ];

    foreach ($settingsToUpdate as $k => $val) {
        $kEsc   = mysqli_real_escape_string($conn, $k);
        $valEsc = mysqli_real_escape_string($conn, (string)$val);
        mysqli_query($conn,
            "INSERT INTO settings (setting_key, setting_value) VALUES ('$kEsc', '$valEsc')
             ON DUPLICATE KEY UPDATE setting_value = '$valEsc'");
    }

    audit($conn, $user['id'], 'settings_updated', 'Updated library, Telegram and fine rules');
    flash('msg', 'ቅንብሮች በተሳካ ሁኔታ ተቀምጠዋል።', 'success');
    redirect('settings.php');
}

// Load all settings
$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($r = mysqli_fetch_assoc($res)) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// Test Telegram connection
$tgTestMsg = '';
if (isset($_GET['test_telegram'])) {
    $token = $settings['telegram_bot_token'] ?? '';
    if ($token) {
        $r = telegram_api('getMe', [], $token);
        if (isset($r['ok']) && $r['ok']) {
            $tgTestMsg = '<span style="color:var(--success)"><i class="bi bi-check-circle"></i> Bot ተገናኘ! @' . e($r['result']['username'] ?? '') . '</span>';
        } else {
            $tgTestMsg = '<span style="color:var(--danger)"><i class="bi bi-x-circle"></i> Bot token ትክክል አይደለም።</span>';
        }
    } else {
        $tgTestMsg = '<span style="color:var(--danger)">Bot token አልተቀናጀም።</span>';
    }
}

$pageTitle = __('settings');
$activeKey = 'settings';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($f = flash('msg')): ?>
  <div class="card card-pad mb-3" style="border-left:4px solid var(--<?= $f['type'] === 'success' ? 'success' : 'danger' ?>);color:var(--<?= $f['type'] === 'success' ? 'success' : 'danger' ?>);">
    <?= e($f['msg']) ?>
  </div>
<?php endif; ?>

<form method="post">
  <?= csrf_field() ?>

  <!-- ── Library Info ── -->
  <div class="section-title" style="margin-top:0;">📚 የቤተ-መጻሕፍቱ መረጃ</div>
  <div class="card card-pad mb-3">
    <div class="field">
      <label>የቤተ-መጻሕፍት ስም</label>
      <input class="input" name="library_name" value="<?= e($settings['library_name'] ?? 'አጸደ ቤተ-መጻሕፍት') ?>" required>
    </div>
  </div>

  <!-- ── Borrowing Rules ── -->
  <div class="section-title">📖 የውሰት ደንቦች</div>
  <div class="card card-pad mb-3">
    <div class="row g-2">
      <div class="col-6">
        <div class="field">
          <label>የውሰት ጊዜ (ቀናት)</label>
          <input class="input" type="number" name="borrow_days" min="1" value="<?= e($settings['borrow_days'] ?? 14) ?>" required>
        </div>
      </div>
      <div class="col-6">
        <div class="field">
          <label>ከፍተኛ ውሰት / አባል</label>
          <input class="input" type="number" name="max_active_borrows" min="1" value="<?= e($settings['max_active_borrows'] ?? 3) ?>" required>
        </div>
      </div>
    </div>
    <div class="field">
      <label>ማስታወሻ (ቀን ቀደም ሲል ስንት ቀን)</label>
      <input class="input" type="number" name="reminder_days_before_due" min="1" max="10" value="<?= e($settings['reminder_days_before_due'] ?? 2) ?>" required>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
        <input type="checkbox" name="allow_self_registration" <?= ($settings['allow_self_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
        <span>አባላት በራሳቸው እንዲመዘገቡ ይፈቀድ</span>
      </label>
    </div>
  </div>

  <!-- ── Payment Rules ── -->
  <div class="section-title">💰 ወርሃዊ ክፍያ ደንቦች</div>
  <div class="card card-pad mb-3" style="border-left:4px solid var(--gold);">
    <div class="field">
      <label>ዝቅተኛ ወርሃዊ ክፍያ (ብር)</label>
      <input class="input" type="number" step="0.5" min="1" name="minimum_monthly_payment" value="<?= e($settings['minimum_monthly_payment'] ?? '50') ?>" required>
      <div class="hint">አባሉ ይህን ያህል ወይም ከዚያ በላይ ሲከፍል ብቻ ለዚያ ወር ማዋስ ይፈቀዳል።</div>
    </div>
  </div>

  <!-- ── Overdue Fine ── -->
  <div class="section-title">⚠️ ቅጣት ደንቦች (Overdue Fine)</div>
  <div class="card card-pad mb-3" style="border-left:4px solid var(--danger);">
    <div class="row g-2">
      <div class="col-6">
        <div class="field">
          <label>ቅጣት ፣ ቀን (ብር)</label>
          <input class="input" type="number" step="0.5" min="0" name="overdue_fine_per_day" value="<?= e($settings['overdue_fine_per_day'] ?? '5') ?>">
          <div class="hint">ነባሪ: 5 ብር/ቀን</div>
        </div>
      </div>
      <div class="col-6">
        <div class="field">
          <label>ነፃ ቀናት (Grace Days)</label>
          <input class="input" type="number" min="0" name="fine_grace_days" value="<?= e($settings['fine_grace_days'] ?? '0') ?>">
          <div class="hint">ቅጣት ከቀኑ ስንት ቀን በኋላ ይጀምር</div>
        </div>
      </div>
      <div class="col-12 mt-2">
        <div class="field">
          <label>ከፍተኛ ያልተከፈለ ቅጣት ገደብ (ብር)</label>
          <input class="input" type="number" step="0.5" min="0" name="max_unpaid_fine" value="<?= e($settings['max_unpaid_fine'] ?? '0') ?>">
          <div class="hint">ያልተከፈለ ቅጣት ከዚህ መጠን በላይ ሲሆን መጽሐፍ መዋስ ይታገዳል (0 ማለት ማንኛውም ያልተከፈለ ቅጣት ወዲያውኑ ያግዳል)።</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Telegram Bot ── -->
  <div class="section-title">
    <i class="bi bi-telegram" style="color:#229ED9;"></i> Telegram Bot ቅንብሮች
  </div>
  <div class="card card-pad mb-3" style="border-left:4px solid #229ED9;">

    <div style="font-size:.84rem;color:var(--muted);margin-bottom:12px;line-height:1.6;">
      <strong>እንዴት ያቀናጃሉ:</strong><br>
      1. Telegram-ሁ <a href="https://t.me/BotFather" target="_blank">@BotFather</a> ላይ ሄደው /newbot ብለው Bot ይፍጠሩ<br>
      2. Token-ውን ከዚህ ያስቀምጡ<br>
      3. Bot Username-ን ያስቀምጡ (@ ሳይጨምሩ)<br>
      4. Webhook ለማቀናጀት ዩአርኤሉን ወደ Telegram ይላኩ:
      <code style="display:block;margin-top:4px;font-size:.76rem;word-break:break-all;">
        https://api.telegram.org/bot<b>TOKEN</b>/setWebhook?url=<?= rtrim(BASE_URL, '/') ?>/telegram_bot.php
      </code>
    </div>

    <div class="field">
      <label>Bot Token (BotFather ከሰጠዎ)</label>
      <input class="input" name="telegram_bot_token"
             type="password"
             value="<?= e($settings['telegram_bot_token'] ?? '') ?>"
             placeholder="1234567890:ABCdefGhIjKlMnOpQrStUvWxYz">
    </div>

    <div class="field">
      <label>Bot Username (@ ሳይጨምሩ)</label>
      <div style="position:relative;">
        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-weight:700;">@</span>
        <input class="input" name="telegram_bot_username" style="padding-left:28px;"
               value="<?= e($settings['telegram_bot_username'] ?? '') ?>"
               placeholder="atsede_library_bot">
      </div>
    </div>

    <?php if ($tgTestMsg): ?>
      <div class="card card-pad mb-2" style="font-size:.85rem;"><?= $tgTestMsg ?></div>
    <?php endif; ?>

    <a href="?test_telegram=1" class="btn btn-outline btn-sm">
      <i class="bi bi-wifi"></i> Bot ግንኙነት ሙከራ
    </a>
  </div>

  <button type="submit" class="btn btn-gold btn-block">
    <i class="bi bi-save"></i> <?= __('save_changes') ?>
  </button>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
