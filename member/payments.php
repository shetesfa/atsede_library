<?php
/**
 * member/payments.php  —  Atsede Library
 * Modern, focused payment page showing current Ethiopian month status and real receipts.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user = current_user();
$member = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM members WHERE user_id=" . (int)$user['id']));
$memberId = (int)$member['id'];

$minMonthly    = get_minimum_monthly_payment($conn);
$currentStatus = get_member_payment_status($conn, $memberId);
$history       = get_member_payment_history($conn, $memberId);

// Get real payment receipts/transactions made by this member
$receipts = mysqli_query($conn, "
    SELECT * FROM membership_payments 
    WHERE member_id = $memberId 
    ORDER BY paid_at DESC
");

$pageTitle = __('payments');
$activeKey = 'payments';
include __DIR__ . '/../includes/header.php';
?>

<!-- Title -->
<div class="section-title" style="margin-top:0;">
  <i class="bi bi-wallet2 text-gold me-1"></i> የወርሃዊ አባልነት ክፍያ
</div>

<!-- 1. Hero Card: Current Month Status -->
<div class="card mb-4" style="border-radius:18px;overflow:hidden;border:2px solid <?= $currentStatus['is_paid'] ? '#22c55e' : '#f59e0b' ?>;box-shadow:0 8px 24px rgba(0,0,0,0.06);">
  <div style="background:<?= $currentStatus['is_paid'] ? 'linear-gradient(135deg, rgba(34,197,94,0.12), rgba(34,197,94,0.04))' : 'linear-gradient(135deg, rgba(245,158,11,0.12), rgba(245,158,11,0.04))' ?>;padding:20px;">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
      <span style="font-weight:700;font-size:.95rem;color:var(--navy);display:flex;align-items:center;gap:6px;">
        <i class="bi bi-calendar-check text-gold"></i> የዚህ ወር ክፍያ (<?= e($currentStatus['month_label']) ?>)
      </span>
      <span class="badge <?= $currentStatus['badge_class'] ?>" style="font-size:.85rem;padding:6px 14px;border-radius:20px;font-weight:700;">
        <?= $currentStatus['is_paid'] ? '✓ ተከፍሏል' : '⚠ አልተከፈለም' ?>
      </span>
    </div>

    <div class="d-flex align-items-baseline gap-2 mb-2">
      <div style="font-size:2.2rem;font-weight:800;color:var(--navy);line-height:1;">
        <?= number_format($currentStatus['amount_paid'], 2) ?>
      </div>
      <div style="font-size:1rem;color:var(--navy);font-weight:700;">ብር</div>
      <div style="font-size:.85rem;color:var(--muted);margin-left:auto;">
        የሚፈለገው ዝቅተኛ፦ <strong><?= number_format($minMonthly, 0) ?> ብር</strong>
      </div>
    </div>

    <!-- Progress bar -->
    <?php 
      $pct = min(100, $minMonthly > 0 ? ($currentStatus['amount_paid'] / $minMonthly) * 100 : 0);
    ?>
    <div style="height:8px;background:rgba(0,0,0,0.08);border-radius:10px;overflow:hidden;margin:12px 0;">
      <div style="height:100%;width:<?= $pct ?>%;background:<?= $currentStatus['is_paid'] ? '#22c55e' : '#f59e0b' ?>;border-radius:10px;transition:width 0.4s ease;"></div>
    </div>

    <?php if ($currentStatus['is_paid']): ?>
      <div style="color:var(--success);font-size:.86rem;font-weight:600;display:flex;align-items:center;gap:6px;">
        <i class="bi bi-check-circle-fill"></i> የዚህ ወር የአባልነት ክፍያዎ ተሟልቷል። መጻሕፍትን በነፃነት መዋስ ይችላሉ።
      </div>
    <?php else: ?>
      <div style="color:#b45309;font-size:.86rem;display:flex;align-items:center;gap:6px;line-height:1.5;">
        <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
        <span>የዚህ ወር ክፍያ ስላልተሟላ መጽሐፍ መዋስ አይፈቀድም። እባክዎ ቤተ-መጻሕፍቱ በአካል በመሄድ ክፍያዎን ያስፈጽሙ።</span>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- 2. Actual Receipts History -->
<div class="section-title">
  <i class="bi bi-receipt-cutoff text-gold me-1"></i> የተከፈሉ ደረሰኞች ታሪክ
</div>

<?php if (mysqli_num_rows($receipts) === 0): ?>
  <div class="card card-pad text-center mb-4" style="border-radius:14px;">
    <div style="width:54px;height:54px;border-radius:50%;background:var(--slate-50);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px;color:var(--muted);font-size:1.6rem;">
      <i class="bi bi-receipt"></i>
    </div>
    <div style="font-weight:700;color:var(--navy);font-size:.95rem;">እስካሁን ምንም የተከፈለ ክፍያ ደረሰኝ የለም</div>
    <p class="text-muted" style="font-size:.82rem;margin:4px 0 0;">
      በቤተ-መጻሕፍቱ ክፍያ ሲፈጽሙ ደረሰኝዎ እዚህ በዝርዝር ይቀመጣል።
    </p>
  </div>
<?php else: ?>
  <div class="card mb-4" style="border-radius:14px;overflow:hidden;border:1.5px solid var(--line);">
    <?php while ($rc = mysqli_fetch_assoc($receipts)): ?>
      <div style="padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div>
          <div style="font-weight:800;color:var(--navy);font-size:.95rem;">
            <?= number_format($rc['amount'], 2) ?> ብር
          </div>
          <div class="text-muted" style="font-size:.78rem;margin-top:2px;">
            ለ<?= e(format_billing_month_amharic($rc['payment_month'])) ?> ክፍያ · በ<?= e(formatDate($rc['paid_at'])) ?>
          </div>
        </div>
        <div style="text-align:right;">
          <span class="badge badge-success" style="font-size:.72rem;padding:4px 10px;border-radius:20px;">
            <i class="bi bi-check-circle"></i> የተረጋገጠ
          </span>
          <?php if (!empty($rc['reference_no'])): ?>
            <div style="font-size:.7rem;color:var(--muted);font-family:monospace;margin-top:2px;">
              #<?= e($rc['reference_no']) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endwhile; ?>
  </div>
<?php endif; ?>

<!-- 3. Optional: Expandable Monthly Breakdown since registration -->
<?php if (count($history) > 1): ?>
  <div class="mb-4">
    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('monthly-breakdown').style.display = document.getElementById('monthly-breakdown').style.display === 'none' ? 'block' : 'none';" style="border-radius:20px;font-size:.8rem;">
      <i class="bi bi-calendar3 me-1"></i> የቀደሙ ወራት ሁኔታን ይመልከቱ (<?= count($history) ?> ወራት)
    </button>

    <div id="monthly-breakdown" style="display:none;margin-top:14px;">
      <div class="card" style="border-radius:14px;overflow:hidden;border:1.5px solid var(--line);">
        <?php foreach ($history as $h): ?>
          <div style="padding:12px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;">
            <div>
              <div style="font-weight:700;color:var(--navy);font-size:.88rem;">
                <?= e($h['month_label']) ?>
              </div>
              <div class="text-muted" style="font-size:.76rem;">
                የተከፈለው፦ <?= number_format($h['amount_paid'], 2) ?> ብር
              </div>
            </div>
            <span class="badge <?= $h['badge_class'] ?>" style="font-size:.75rem;">
              <?= e($h['status_text']) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
