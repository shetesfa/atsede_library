<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (isset($_POST['library_name'])) {
        $val = mysqli_real_escape_string($conn, clean($_POST['library_name']));
        mysqli_query($conn, "UPDATE settings SET setting_value='$val' WHERE setting_key='library_name'");
    }
    if (isset($_POST['borrow_days'])) {
        $val = (int)$_POST['borrow_days'];
        mysqli_query($conn, "UPDATE settings SET setting_value='$val' WHERE setting_key='borrow_days'");
    }
    if (isset($_POST['max_active_borrows'])) {
        $val = (int)$_POST['max_active_borrows'];
        mysqli_query($conn, "UPDATE settings SET setting_value='$val' WHERE setting_key='max_active_borrows'");
    }
    if (isset($_POST['allow_self_registration'])) {
        mysqli_query($conn, "UPDATE settings SET setting_value='1' WHERE setting_key='allow_self_registration'");
    } else {
        mysqli_query($conn, "UPDATE settings SET setting_value='0' WHERE setting_key='allow_self_registration'");
    }
    audit($conn, $user['id'], 'settings_updated', '');
    flash('msg', 'ቅንብሮች ተቀምጠዋል።', 'success');
    redirect('settings.php');
}

$settings = [];
$res = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
while ($r = mysqli_fetch_assoc($res)) $settings[$r['setting_key']] = $r['setting_value'];

$pageTitle = __('settings');
$activeKey = 'settings';
include __DIR__ . '/../includes/header.php';
?>

<form method="post">
  <?= csrf_field() ?>

  <div class="section-title" style="margin-top:0;">የውሰት ደንቦች</div>
  <div class="card card-pad mb-3">
    <div class="field"><label>የቤተ መጻሕፍት ስም</label><input class="input" name="library_name" value="<?= e($settings['library_name'] ?? '') ?>"></div>
    <div class="row g-2">
      <div class="col-6"><div class="field"><label>የውሰት ጊዜ (ቀናት)</label><input class="input" type="number" name="borrow_days" min="1" value="<?= e($settings['borrow_days'] ?? 14) ?>"></div></div>
      <div class="col-6"><div class="field"><label>ከፍተኛ የውሰት ብዛት / አባል</label><input class="input" type="number" name="max_active_borrows" min="1" value="<?= e($settings['max_active_borrows'] ?? 3) ?>"></div></div>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="allow_self_registration" <?= ($settings['allow_self_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
        አባላት በራሳቸው እንዲመዘገቡ ይፈቀድ
      </label>
    </div>
  </div>

  <button class="btn btn-gold btn-block"><i class="bi bi-save"></i> <?= __('save_changes') ?></button>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
