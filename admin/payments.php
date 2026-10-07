<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();
$minMonthly = get_minimum_monthly_payment($conn);
$currentMonth = current_billing_month();

// Update minimum monthly payment directly if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_min_payment'])) {
    csrf_verify();
    $newMin = max(1.0, (float)$_POST['minimum_monthly_payment']);
    $newMinStr = mysqli_real_escape_string($conn, (string)$newMin);
    mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('minimum_monthly_payment', '$newMinStr') 
        ON DUPLICATE KEY UPDATE setting_value = '$newMinStr'");
    audit($conn, $user['id'], 'minimum_payment_updated', "new_min:$newMin");
    flash('msg', 'ዝቅተኛው ወርሃዊ የአባልነት ክፍያ ወደ ' . number_format($newMin, 2) . ' ብር ተቀይሯል።', 'success');
    redirect('payments.php');
}

$selectedMonth = clean($_GET['month'] ?? '');
$search = clean($_GET['q'] ?? '');

$whereClauses = ["1=1"];
if (!empty($selectedMonth)) {
    $whereClauses[] = "p.payment_month = '" . mysqli_real_escape_string($conn, $selectedMonth) . "'";
}
if (!empty($search)) {
    $esc = mysqli_real_escape_string($conn, $search);
    $whereClauses[] = "(u.full_name LIKE '%$esc%' OR u.phone LIKE '%$esc%' OR p.reference_number LIKE '%$esc%')";
}
$whereSql = implode(' AND ', $whereClauses);

$payments = mysqli_query($conn, "
    SELECT p.*, u.full_name, u.phone, rec.full_name AS recorded_by_name
    FROM membership_payments p
    JOIN members m ON m.id = p.member_id
    JOIN users u ON u.id = m.user_id
    LEFT JOIN users rec ON rec.id = p.recorded_by
    WHERE $whereSql
    ORDER BY p.paid_at DESC
    LIMIT 100
");

// Overall statistics
$overallTotal = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) s FROM membership_payments"))['s'];
$currentMonthTotal = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) s FROM membership_payments WHERE payment_month = '$currentMonth'"))['s'];
$activeMembersCount = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='active'"))['c'];

$pageTitle = __('payments');
$activeKey = 'payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="section-title" style="margin:0;">የአባልነት ክፍያዎች አስተዳደር</div>
  <button type="button" class="btn btn-gold btn-sm" onclick="openSheet('config-sheet')">
    <i class="bi bi-gear"></i> ዝቅተኛ ክፍያ ቀይር
  </button>
</div>

<!-- Stats -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4">
    <div class="stat-card gold">
      <div class="num"><?= number_format($minMonthly, 0) ?> ብር</div>
      <div class="lbl">ዝቅተኛ ወርሃዊ ክፍያ (አሁን)</div>
      <i class="bi bi-tag"></i>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="num"><?= number_format($currentMonthTotal, 2) ?> ብር</div>
      <div class="lbl">የዚህ ወር ገቢ (<?= format_billing_month_amharic($currentMonth) ?>)</div>
      <i class="bi bi-wallet2"></i>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card outline">
      <div class="num"><?= number_format($overallTotal, 2) ?> ብር</div>
      <div class="lbl">የሁሉም ጊዜ ጠቅላላ ገቢ</div>
      <i class="bi bi-cash-stack"></i>
    </div>
  </div>
</div>

<!-- Filters -->
<form method="get" class="card card-pad mb-3">
  <div class="row g-2">
    <div class="col-12 col-md-6">
      <div class="input-group">
        <i class="bi bi-search"></i>
        <input class="input" name="q" value="<?= e($search) ?>" placeholder="በአባል ስም፣ በስልክ ወይም በደረሰኝ ቁጥር ይፈልጉ…">
      </div>
    </div>
    <div class="col-6 col-md-4">
      <input type="month" class="input" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()">
    </div>
    <div class="col-6 col-md-2">
      <a href="payments.php" class="btn btn-outline btn-block btn-sm" style="height:42px;display:flex;align-items:center;justify-content:center;">
        አጽዳ
      </a>
    </div>
  </div>
</form>

<!-- Table -->
<div class="section-title">የክፍያዎች ዝርዝር መዝገብ</div>
<?php if (mysqli_num_rows($payments) === 0): ?>
  <div class="empty-state">
    <i class="bi bi-inbox"></i>
    <h4>ምንም ክፍያ አልተገኘም</h4>
  </div>
<?php else: ?>
  <div class="table-wrap">
    <table class="app-table app-stack">
      <thead>
        <tr>
          <th>አባል</th>
          <th>ወር</th>
          <th>የተከፈለ መጠን</th>
          <th>ሁኔታ</th>
          <th>ቀን</th>
          <th>የመዘገበው ኃላፊ</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($p = mysqli_fetch_assoc($payments)): ?>
          <tr>
            <td data-label="አባል">
              <strong><?= e($p['full_name']) ?></strong><br>
              <span class="text-muted" style="font-size:.76rem;"><?= e($p['phone']) ?></span>
            </td>
            <td data-label="ወር"><strong><?= format_billing_month_amharic($p['payment_month']) ?></strong></td>
            <td data-label="መጠን"><strong style="color:var(--gold-600);"><?= number_format($p['amount'], 2) ?> ብር</strong></td>
            <td data-label="ሁኔታ">
              <?php 
                $st = get_member_payment_status($conn, $p['member_id'], $p['payment_month']);
              ?>
              <span class="badge <?= $st['badge_class'] ?>"><?= $st['status_text'] ?></span>
            </td>
            <td data-label="ቀን"><?= formatDate($p['paid_at']) ?></td>
            <td data-label="ኃላፊ"><?= e($p['recorded_by_name'] ?: 'ላይብረሪያን') ?></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- Sheet: Configure Minimum Monthly Payment -->
<div class="sheet-overlay" id="config-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title"><i class="bi bi-gear text-gold"></i> ዝቅተኛ ወርሃዊ ክፍያ ማስተካከያ</div>
    
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="update_min_payment" value="1">
      
      <div class="field">
        <label>ዝቅተኛ ወርሃዊ የአባልነት ክፍያ (ብር) <span class="text-danger">*</span></label>
        <input type="number" step="1" min="1" class="input" name="minimum_monthly_payment" value="<?= (int)$minMonthly ?>" required>
        <span class="text-muted" style="font-size:.78rem;margin-top:4px;display:block;">
          ነባሪው መጠን 50 ብር ነው። አባሉ ይህንን መጠን ወይም ከዚያ በላይ ሲከፍል ብቻ የዚያ ወር ሁኔታ "ተከፍሏል" ይሆናል።
        </span>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-gold btn-block">
          <i class="bi bi-save"></i> ቅንብሩን አስቀምጥ
        </button>
        <button type="button" class="btn btn-outline" onclick="closeSheet('config-sheet')">
          <?= __('cancel') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
