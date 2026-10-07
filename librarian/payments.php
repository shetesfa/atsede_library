<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian', 'admin']);

$user = current_user();
$minMonthly = get_minimum_monthly_payment($conn);
$currentMonth = current_billing_month();

// Handle new payment entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify();
    $memberId = (int)($_POST['member_id'] ?? 0);
    $month = clean($_POST['payment_month'] ?? $currentMonth);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = clean($_POST['payment_method'] ?? 'Cash');
    $ref = clean($_POST['reference_number'] ?? '');
    $notes = clean($_POST['notes'] ?? '');

    if ($memberId <= 0 || $amount <= 0 || empty($month)) {
        flash('msg', 'እባክዎ አባል፣ ወር እና ትክክለኛ የብር መጠን ያስገቡ።', 'danger');
    } else {
        $paymentId = record_membership_payment($conn, $memberId, $month, $amount, $method, (int)$user['id'], $ref, null, $notes);
        
        $status = get_member_payment_status($conn, $memberId, $month);
        if ($status['is_paid']) {
            flash('msg', '✓ ክፍያው ተመዝግቧል። የዚህ ወር ክፍያ ተሟልቷል (ተከፍሏል)።', 'success');
        } else {
            flash('msg', '⚠ ክፍያው ተመዝግቧል። ነገር ግን መጠኑ ከዝቅተኛው ክፍያ (' . number_format($minMonthly, 2) . ' ብር) በታች ስለሆነ ሁኔታው "አልተከፈለም" ነው።', 'warning');
        }
        redirect('payments.php?member_id=' . $memberId);
    }
}

// Filters
$selectedMemberId = (int)($_GET['member_id'] ?? 0);
$selectedMonth = clean($_GET['month'] ?? $currentMonth);
$viewFilter = clean($_GET['view'] ?? 'all'); // 'all', 'unpaid'

// Load members list for dropdown
$membersList = mysqli_query($conn, "
    SELECT m.id, m.user_id, u.full_name, u.phone, m.class, m.student_id 
    FROM members m 
    JOIN users u ON u.id = m.user_id 
    WHERE u.status = 'active' 
    ORDER BY u.full_name ASC
");

// Fetch unpaid members if requested
$unpaidMembers = [];
if ($viewFilter === 'unpaid') {
    mysqli_data_seek($membersList, 0);
    while ($m = mysqli_fetch_assoc($membersList)) {
        $st = get_member_payment_status($conn, $m['id'], $selectedMonth);
        if (!$st['is_paid']) {
            $m['paid_amount'] = $st['amount_paid'];
            $unpaidMembers[] = $m;
        }
    }
}

// Payment history list
$whereClauses = ["1=1"];
if ($selectedMemberId > 0) {
    $whereClauses[] = "p.member_id = $selectedMemberId";
}
if (!empty($selectedMonth) && $viewFilter !== 'unpaid') {
    $whereClauses[] = "p.payment_month = '" . mysqli_real_escape_string($conn, $selectedMonth) . "'";
}
$whereSql = implode(' AND ', $whereClauses);

$paymentsHistory = mysqli_query($conn, "
    SELECT p.*, u.full_name, u.phone, rec.full_name AS recorded_by_name
    FROM membership_payments p
    JOIN members m ON m.id = p.member_id
    JOIN users u ON u.id = m.user_id
    LEFT JOIN users rec ON rec.id = p.recorded_by
    WHERE $whereSql
    ORDER BY p.paid_at DESC
    LIMIT 100
");

// Total summary for selected month
$summaryRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(amount), 0) AS total_sum, COUNT(*) AS total_count 
    FROM membership_payments 
    WHERE payment_month = '" . mysqli_real_escape_string($conn, $selectedMonth) . "'
"));

$pageTitle = __('payments');
$activeKey = 'payments';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="section-title" style="margin:0;"><?= __('payments') ?></div>
  <button type="button" class="btn btn-gold btn-sm" onclick="openSheet('new-payment-sheet')">
    <i class="bi bi-plus-circle"></i> <?= __('record_payment') ?>
  </button>
</div>

<!-- Quick stats cards -->
<div class="row g-2 mb-3">
  <div class="col-6 col-md-4">
    <div class="stat-card gold">
      <div class="num"><?= number_format($minMonthly, 0) ?> ብር</div>
      <div class="lbl">ዝቅተኛ ወርሃዊ ክፍያ</div>
      <i class="bi bi-shield-check"></i>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="stat-card">
      <div class="num"><?= number_format($summaryRow['total_sum'], 2) ?> ብር</div>
      <div class="lbl">የ<?= format_billing_month_amharic($selectedMonth) ?> ጠቅላላ ገቢ</div>
      <i class="bi bi-wallet2"></i>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="stat-card outline">
      <div class="num"><?= (int)$summaryRow['total_count'] ?></div>
      <div class="lbl">የተከናወኑ ክፍያዎች ብዛት</div>
      <i class="bi bi-receipt"></i>
    </div>
  </div>
</div>

<!-- Tabs: All vs Unpaid -->
<div class="d-flex gap-2 mb-3 overflow-x-auto">
  <a href="?view=all&month=<?= urlencode($selectedMonth) ?>" class="btn <?= $viewFilter==='all'?'btn-navy':'btn-outline' ?> btn-sm">
    <i class="bi bi-list-check"></i> ሁሉም የክፍያ ታሪክ
  </a>
  <a href="?view=unpaid&month=<?= urlencode($selectedMonth) ?>" class="btn <?= $viewFilter==='unpaid'?'btn-navy':'btn-outline' ?> btn-sm">
    <i class="bi bi-exclamation-circle"></i> የዚህ ወር ያልከፈሉ አባላት
  </a>
</div>

<?php if ($viewFilter === 'unpaid'): ?>
  <!-- Unpaid members list for follow up -->
  <div class="card card-pad mb-3">
    <h3 style="font-size:1rem;color:var(--navy);margin:0 0 10px;">
      <i class="bi bi-bell-fill text-warning"></i> የ<?= format_billing_month_amharic($selectedMonth) ?> ክፍያ ያልከፈሉ አባላት (<?= count($unpaidMembers) ?>)
    </h3>
    <p class="text-muted" style="font-size:.84rem;margin-bottom:12px;">እባክዎ አባሉን ያግኙ፤ ክፍያ እስካልተከፈለ ድረስ መጽሐፍ መዋስ አይፈቀድም።</p>
    
    <?php if (empty($unpaidMembers)): ?>
      <div class="empty-state">
        <i class="bi bi-check-circle-fill text-success" style="font-size:2rem;"></i>
        <h4>እንኳን ደስ አለዎት! ሁሉም አባላት ከፍለዋል።</h4>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="app-table app-stack">
          <thead>
            <tr>
              <th>አባል</th>
              <th>ስልክ ቁጥር</th>
              <th>ክፍል / መታወቂያ</th>
              <th>የተከፈለው</th>
              <th>ተግባር</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($unpaidMembers as $unp): ?>
              <tr>
                <td data-label="አባል"><strong><?= e($unp['full_name']) ?></strong></td>
                <td data-label="ስልክ"><a href="tel:<?= e($unp['phone']) ?>"><i class="bi bi-telephone"></i> <?= e($unp['phone']) ?></a></td>
                <td data-label="ክፍል"><?= e($unp['class'] ?: '—') ?> <?= $unp['student_id'] ? '· '.e($unp['student_id']) : '' ?></td>
                <td data-label="የተከፈለው"><span class="badge badge-danger"><?= number_format($unp['paid_amount'], 2) ?> / <?= number_format($minMonthly, 2) ?> ብር</span></td>
                <td data-label="ተግባር">
                  <button type="button" class="btn btn-gold btn-sm" onclick="selectMemberForPayment(<?= (int)$unp['id'] ?>, '<?= e(addslashes($unp['full_name'])) ?>')">
                    <i class="bi bi-cash"></i> ክፍያ መዝግብ
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <!-- Filter Card -->
  <form method="get" class="card card-pad mb-3">
    <input type="hidden" name="view" value="all">
    <div class="row g-2">
      <div class="col-12 col-md-5">
        <label style="font-size:.8rem;font-weight:600;">አባል ምረጥ</label>
        <select class="input" name="member_id" onchange="this.form.submit()">
          <option value="0">-- ሁሉም አባላት --</option>
          <?php mysqli_data_seek($membersList, 0); while ($m = mysqli_fetch_assoc($membersList)): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $selectedMemberId === (int)$m['id'] ? 'selected' : '' ?>>
              <?= e($m['full_name']) ?> (<?= e($m['phone']) ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label style="font-size:.8rem;font-weight:600;">የክፍያ ወር</label>
        <input type="month" class="input" name="month" value="<?= e($selectedMonth) ?>" onchange="this.form.submit()">
      </div>
      <div class="col-12 col-md-3 d-flex align-items-end">
        <a href="payments.php" class="btn btn-outline btn-block btn-sm" style="height:42px;display:flex;align-items:center;justify-content:center;">
          <i class="bi bi-arrow-counterclockwise"></i> አጽዳ
        </a>
      </div>
    </div>
  </form>

  <!-- Payment History Table -->
  <div class="section-title">የተመዘገቡ ክፍያዎች ዝርዝር</div>
  <?php if (mysqli_num_rows($paymentsHistory) === 0): ?>
    <div class="empty-state">
      <i class="bi bi-cash-stack"></i>
      <h4>ምንም የተመዘገበ ክፍያ አልተገኘም</h4>
      <p>በተመረጠው ወር ወይም አባል ምንም ክፍያ አልተመዘገበም።</p>
      <button type="button" class="btn btn-gold btn-sm" onclick="openSheet('new-payment-sheet')">
        <i class="bi bi-plus"></i> አዲስ ክፍያ መዝግብ
      </button>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="app-table app-stack">
        <thead>
          <tr>
            <th>አባል</th>
            <th>ወር</th>
            <th>መጠን</th>
            <th>ሁኔታ</th>
            <th>ቀን</th>
            <th>የክፍያ ዘዴ</th>
            <th>የመዘገበው</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($p = mysqli_fetch_assoc($paymentsHistory)): ?>
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
              <td data-label="የክፍያ ዘዴ"><?= e($p['payment_method']) ?><?= $p['reference_number'] ? ' ('.e($p['reference_number']).')' : '' ?></td>
              <td data-label="የመዘገበው"><?= e($p['recorded_by_name'] ?: 'ላይብረሪያን') ?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>

<!-- Bottom Sheet: Record Payment Modal -->
<div class="sheet-overlay" id="new-payment-sheet">
  <div class="sheet" style="max-height:90vh;overflow-y:auto;">
    <div class="sheet-handle"></div>
    <div class="sheet-title"><i class="bi bi-cash-coin text-gold"></i> <?= __('record_payment') ?></div>
    
    <form method="post" id="record-payment-form">
      <?= csrf_field() ?>
      <input type="hidden" name="record_payment" value="1">
      
      <div class="field">
        <label><?= __('members') ?> <span class="text-danger">*</span></label>
        <select class="input" name="member_id" id="modal-member-id" required>
          <option value="">-- አባል ይምረጡ --</option>
          <?php mysqli_data_seek($membersList, 0); while ($m = mysqli_fetch_assoc($membersList)): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $selectedMemberId === (int)$m['id'] ? 'selected' : '' ?>>
              <?= e($m['full_name']) ?> — <?= e($m['phone']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="row g-2">
        <div class="col-6">
          <div class="field">
            <label><?= __('month') ?> <span class="text-danger">*</span></label>
            <input type="month" class="input" name="payment_month" value="<?= e($selectedMonth) ?>" required>
          </div>
        </div>
        <div class="col-6">
          <div class="field">
            <label><?= __('amount') ?> (ብር) <span class="text-danger">*</span></label>
            <input type="number" step="0.5" min="1" class="input" name="amount" value="<?= (int)$minMonthly ?>" placeholder="50" required>
          </div>
        </div>
      </div>

      <div class="field">
        <label>የክፍያ ዘዴ</label>
        <select class="input" name="payment_method">
          <option value="Cash">በጥሬ ገንዘብ (Cash)</option>
          <option value="Telebirr">ቴሌብር (Telebirr)</option>
          <option value="CBE Birr">ሲቢኢ ብር (CBE Birr)</option>
          <option value="Bank Transfer">የባንክ ሒሳብ ማስተላለፍ</option>
          <option value="Other">ሌላ</option>
        </select>
      </div>

      <div class="field">
        <label>የግብይት መለያ ቁጥር (Transaction / Reference)</label>
        <input type="text" class="input" name="reference_number" placeholder="አስፈላጊ ከሆነ...">
      </div>

      <div class="field">
        <label>ተጨማሪ ማስታወሻ</label>
        <input type="text" class="input" name="notes" placeholder="አስፈላጊ ከሆነ...">
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-gold btn-block">
          <i class="bi bi-check2-circle"></i> ክፍያ መዝግብ
        </button>
        <button type="button" class="btn btn-outline" onclick="closeSheet('new-payment-sheet')">
          <?= __('cancel') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function selectMemberForPayment(memberId, memberName) {
  const sel = document.getElementById('modal-member-id');
  if (sel) {
    sel.value = memberId;
  }
  openSheet('new-payment-sheet');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
