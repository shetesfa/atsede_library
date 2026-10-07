<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$tab = clean($_GET['tab'] ?? 'summary');
$currentMonth = current_billing_month();
$minMonthly = get_minimum_monthly_payment($conn);

// Key Stats
$totalMembers = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member'"))['c'];
$activeMembers = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='active'"))['c'];

$totalBooks = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status != 'archived'"))['c'];
$totalCopies = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies"))['c'];
$availableCopies = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM book_copies WHERE status='available'"))['c'];
$activeLoans = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE status='borrowed'"))['c'];
$overdueLoans = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE status='borrowed' AND due_date < CURDATE()"))['c'];

$nonBorrowableBooks = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE is_borrowable=0 OR borrow_status='restricted'"))['c'];
$totalPaymentsSum = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) s FROM membership_payments"))['s'];
$currentMonthPaymentsSum = (float)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) s FROM membership_payments WHERE payment_month='$currentMonth'"))['s'];

// Unpaid members calculation for current month
$activeMembersList = mysqli_query($conn, "SELECT m.id, u.full_name, u.phone, m.class, m.student_id FROM members m JOIN users u ON u.id=m.user_id WHERE u.status='active'");
$unpaidCount = 0;
$unpaidMembersData = [];
while ($m = mysqli_fetch_assoc($activeMembersList)) {
    $st = get_member_payment_status($conn, $m['id'], $currentMonth);
    if (!$st['is_paid']) {
        $unpaidCount++;
        $m['paid'] = $st['amount_paid'];
        $unpaidMembersData[] = $m;
    }
}

// Top borrowed books
$topBooks = mysqli_query($conn, "
    SELECT b.title, b.author, COUNT(br.id) AS borrow_count
    FROM borrow_records br
    JOIN books b ON b.id = br.book_id
    GROUP BY br.book_id
    ORDER BY borrow_count DESC
    LIMIT 10
");

// Overdue details
$overdueList = mysqli_query($conn, "
    SELECT br.*, b.title, bc.copy_code, u.full_name, u.phone, DATEDIFF(CURDATE(), br.due_date) AS days_overdue
    FROM borrow_records br
    JOIN books b ON b.id = br.book_id
    JOIN book_copies bc ON bc.id = br.book_copy_id
    JOIN members m ON m.id = br.member_id
    JOIN users u ON u.id = m.user_id
    WHERE br.status = 'borrowed' AND br.due_date < CURDATE()
    ORDER BY br.due_date ASC
");

// Audit logs
$auditLogs = mysqli_query($conn, "
    SELECT a.*, u.full_name, u.role
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC
    LIMIT 50
");

$pageTitle = __('reports');
$activeKey = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="no-print d-flex justify-content-between align-items-center mb-3">
  <div class="section-title" style="margin:0;"><?= __('reports') ?></div>
  <button class="btn btn-outline btn-sm" onclick="window.print()">
    <i class="bi bi-printer"></i> ሪፖርት አትም
  </button>
</div>

<!-- Tabs for report categories -->
<div class="no-print d-flex gap-2 mb-3 overflow-x-auto">
  <a href="?tab=summary" class="btn <?= $tab==='summary'?'btn-navy':'btn-outline' ?> btn-sm">ማጠቃለያ</a>
  <a href="?tab=payments" class="btn <?= $tab==='payments'?'btn-navy':'btn-outline' ?> btn-sm">የክፍያ ሪፖርት</a>
  <a href="?tab=loans" class="btn <?= $tab==='loans'?'btn-navy':'btn-outline' ?> btn-sm">የውሰትና ተመላሽ</a>
  <a href="?tab=overdue" class="btn <?= $tab==='overdue'?'btn-navy':'btn-outline' ?> btn-sm">ጊዜያቸው ያለፈ (<?= $overdueLoans ?>)</a>
  <a href="?tab=audit" class="btn <?= $tab==='audit'?'btn-navy':'btn-outline' ?> btn-sm">የኦዲት መዝገብ</a>
</div>

<?php if ($tab === 'summary'): ?>
  <!-- 1. General Summary Dashboard -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
      <div class="stat-card gold">
        <div class="num"><?= $totalBooks ?></div>
        <div class="lbl">ጠቅላላ መጻሕፍት (<?= $totalCopies ?> ቅጂዎች)</div>
        <i class="bi bi-book"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card">
        <div class="num"><?= $activeMembers ?></div>
        <div class="lbl">ንቁ አባላት (ጠቅላላ <?= $totalMembers ?>)</div>
        <i class="bi bi-people"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card outline" style="<?= $unpaidCount > 0 ? 'border-color:var(--danger);' : '' ?>">
        <div class="num" style="<?= $unpaidCount > 0 ? 'color:var(--danger);' : '' ?>"><?= $unpaidCount ?></div>
        <div class="lbl">የዚህ ወር ያልከፈሉ አባላት</div>
        <i class="bi bi-exclamation-triangle"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card outline">
        <div class="num"><?= $activeLoans ?></div>
        <div class="lbl">በውሰት ላይ ያሉ መጻሕፍት</div>
        <i class="bi bi-journal-arrow-up"></i>
      </div>
    </div>
  </div>

  <div class="section-title">በብዛት የተወሰዱ 10 መጻሕፍት</div>
  <div class="table-wrap mb-3">
    <table class="app-table app-stack">
      <thead><tr><th>መጽሐፍ</th><th>ደራሲ</th><th>የተወሰደበት ብዛት</th></tr></thead>
      <tbody>
        <?php while ($tb = mysqli_fetch_assoc($topBooks)): ?>
          <tr>
            <td data-label="መጽሐፍ"><strong><?= e($tb['title']) ?></strong></td>
            <td data-label="ደራሲ"><?= e($tb['author']) ?></td>
            <td data-label="የተወሰደበት"><span class="badge badge-gold"><?= (int)$tb['borrow_count'] ?> ጊዜ</span></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'payments'): ?>
  <!-- 2. Payments Breakdown -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-4">
      <div class="stat-card gold">
        <div class="num"><?= number_format($currentMonthPaymentsSum, 2) ?> ብር</div>
        <div class="lbl">የ<?= format_billing_month_amharic($currentMonth) ?> ገቢ</div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="stat-card">
        <div class="num"><?= number_format($totalPaymentsSum, 2) ?> ብር</div>
        <div class="lbl">የሁሉም ጊዜ ጠቅላላ ገቢ</div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="stat-card outline" style="<?= $unpaidCount > 0 ? 'border-color:var(--danger);' : '' ?>">
        <div class="num" style="<?= $unpaidCount > 0 ? 'color:var(--danger);' : '' ?>"><?= $unpaidCount ?></div>
        <div class="lbl">ያልከፈሉ አባላት ብዛት</div>
      </div>
    </div>
  </div>

  <div class="section-title">የ<?= format_billing_month_amharic($currentMonth) ?> ክፍያ ያልከፈሉ አባላት ዝርዝር</div>
  <div class="table-wrap">
    <table class="app-table app-stack">
      <thead>
        <tr>
          <th>አባል</th>
          <th>ስልክ</th>
          <th>ክፍል / መታወቂያ</th>
          <th>የተከፈለ መጠን</th>
          <th>የጎደለ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($unpaidMembersData as $unp): ?>
          <tr>
            <td data-label="አባል"><strong><?= e($unp['full_name']) ?></strong></td>
            <td data-label="ስልክ"><a href="tel:<?= e($unp['phone']) ?>"><?= e($unp['phone']) ?></a></td>
            <td data-label="ክፍል"><?= e($unp['class'] ?: '—') ?></td>
            <td data-label="የተከፈለ"><span class="badge badge-danger"><?= number_format($unp['paid'], 2) ?> ብር</span></td>
            <td data-label="የጎደለ"><?= number_format(max(0, $minMonthly - $unp['paid']), 2) ?> ብር</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'overdue'): ?>
  <!-- 3. Overdue Loans -->
  <div class="section-title" style="color:var(--danger);">የመመለሻ ቀናቸው ያለፈ መጻሕፍት (<?= $overdueLoans ?>)</div>
  <?php if (mysqli_num_rows($overdueList) === 0): ?>
    <div class="empty-state">
      <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem;"></i>
      <h4>ጊዜው ያለፈበት ምንም መጽሐፍ የለም!</h4>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="app-table app-stack">
        <thead>
          <tr>
            <th>መጽሐፍ</th>
            <th>ቅጂ ኮድ</th>
            <th>አባል</th>
            <th>ስልክ</th>
            <th>የመመለሻ ቀን</th>
            <th>ያለፈው ቀናት</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($ol = mysqli_fetch_assoc($overdueList)): ?>
            <tr>
              <td data-label="መጽሐፍ"><strong><?= e($ol['title']) ?></strong></td>
              <td data-label="ኮድ"><span class="shelf-tag"><?= e($ol['copy_code']) ?></span></td>
              <td data-label="አባል"><?= e($ol['full_name']) ?></td>
              <td data-label="ስልክ"><a href="tel:<?= e($ol['phone']) ?>"><?= e($ol['phone']) ?></a></td>
              <td data-label="የመመለሻ ቀን"><?= formatDate($ol['due_date']) ?></td>
              <td data-label="ያለፈው"><span class="badge badge-danger"><?= (int)$ol['days_overdue'] ?> ቀናት</span></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'audit'): ?>
  <!-- 4. Audit Log -->
  <div class="section-title">የቅርብ ጊዜ የሲስተም ኦዲት መዝገብ</div>
  <div class="table-wrap">
    <table class="app-table app-stack">
      <thead>
        <tr>
          <th>ተጠቃሚ</th>
          <th>ድርጊት</th>
          <th>ዝርዝር</th>
          <th>ቀንና ሰዓት</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($al = mysqli_fetch_assoc($auditLogs)): ?>
          <tr>
            <td data-label="ተጠቃሚ">
              <strong><?= e($al['full_name'] ?: 'ሲስተም') ?></strong>
              <span class="badge badge-muted" style="font-size:.65rem;"><?= e($al['role'] ?: 'system') ?></span>
            </td>
            <td data-label="ድርጊት"><code><?= e($al['action']) ?></code></td>
            <td data-label="ዝርዝር"><span class="text-muted" style="font-size:.8rem;"><?= e($al['details']) ?></span></td>
            <td data-label="ቀን"><?= formatDate($al['created_at']) ?> <?= date('H:i', strtotime($al['created_at'])) ?></td>
            <td data-label="IP"><span class="mono" style="font-size:.75rem;"><?= e($al['ip_address']) ?></span></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
