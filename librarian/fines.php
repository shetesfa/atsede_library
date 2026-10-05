<?php
/**
 * librarian/fines.php — Atsede Library
 *
 * Manage member overdue fines:
 *  - List members with outstanding fines
 *  - Record fine payments (partial or full)
 *  - Waive fines with mandatory written reason
 *  - Print fine payment receipts
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_once __DIR__ . '/../includes/LibraryService.php';
require_role(['librarian', 'admin']);

$user = current_user();

// Handle Fine Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_fine') {
    csrf_verify();
    $recordId = (int)($_POST['record_id'] ?? 0);
    $amount   = (float)($_POST['amount'] ?? 0);
    $notes    = clean($_POST['notes'] ?? 'በላይብረሪያን የተከፈለ ቅጣት');

    if ($recordId <= 0 || $amount <= 0) {
        flash('msg', 'ትክክለኛ የውሰት መዝገብ እና የክፍያ መጠን ያስገቡ።', 'danger');
        redirect('fines.php');
    }

    $rec = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT br.*, m.id AS member_id, u.full_name, b.title 
        FROM borrow_records br
        JOIN members m ON m.id = br.member_id
        JOIN users u ON u.id = m.user_id
        JOIN books b ON b.id = br.book_id
        WHERE br.id = $recordId
    "));

    if (!$rec) {
        flash('msg', 'የውሰት መዝገቡ አልተገኘም።', 'danger');
        redirect('fines.php');
    }

    $currentNet = max(0.00, (float)$rec['overdue_fine'] - (float)$rec['fine_paid'] - (float)$rec['fine_waived']);
    if ($amount > $currentNet) {
        $amount = $currentNet;
    }

    record_fine_payment($conn, $recordId, (int)$rec['member_id'], $amount, 0.00, (int)$user['id'], $notes);
    $payId = mysqli_insert_id($conn);

    flash('msg', 'የ ' . number_format($amount, 2) . ' ብር የቅጣት ክፍያ ተመዝግቧል።', 'success');
    redirect('fines.php?receipt_id=' . $payId);
}

// Handle Fine Waiver
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'waive_fine') {
    csrf_verify();
    $recordId = (int)($_POST['record_id'] ?? 0);
    $amount   = (float)($_POST['amount'] ?? 0);
    $reason   = clean($_POST['reason'] ?? '');

    if (empty($reason)) {
        flash('msg', 'የይቅርታ ምክንያት መጻፍ ግዴታ ነው (ያለ ምክንያት ቅጣት ይቅር ማለት አይቻልም)።', 'danger');
        redirect('fines.php');
    }

    if ($recordId <= 0 || $amount <= 0) {
        flash('msg', 'ትክክለኛ የውሰት መዝገብ እና የይቅርታ መጠን ያስገቡ።', 'danger');
        redirect('fines.php');
    }

    $res = waive_fine($conn, $recordId, $amount, (int)$user['id'], $reason);
    if ($res['success']) {
        flash('msg', $res['message'], 'success');
    } else {
        flash('msg', $res['message'], 'danger');
    }
    redirect('fines.php');
}

// Printable Receipt View
$receiptId = (int)($_GET['receipt_id'] ?? 0);
if ($receiptId > 0) {
    $payQuery = mysqli_query($conn, "
        SELECT fp.*, br.due_date, br.status AS borrow_status, br.overdue_fine, br.fine_paid, br.fine_waived,
               b.title AS book_title, bc.copy_code,
               u.full_name AS member_name, u.phone AS member_phone, m.member_code,
               staff.full_name AS staff_name
        FROM fine_payments fp
        JOIN borrow_records br ON br.id = fp.record_id
        JOIN members m ON m.id = fp.member_id
        JOIN users u ON u.id = m.user_id
        JOIN books b ON b.id = br.book_id
        LEFT JOIN book_copies bc ON bc.id = br.book_copy_id
        LEFT JOIN users staff ON staff.id = fp.recorded_by
        WHERE fp.id = $receiptId LIMIT 1
    ");
    $receipt = mysqli_fetch_assoc($payQuery);

    if ($receipt) {
        $pageTitle = 'የቅጣት ክፍያ ደረሰኝ #' . $receipt['id'];
        $activeKey = 'fines';
        include __DIR__ . '/../includes/header.php';
        ?>
        <div class="card card-pad mb-3" style="max-width:550px;margin:20px auto;border:2px dashed var(--primary);">
            <div style="text-align:center;border-bottom:1px solid #eee;padding-bottom:12px;margin-bottom:15px;">
                <h3 style="margin:0 0 5px 0;">የዓጸደ ማርያም ቤተ-መጻሕፍት</h3>
                <h4 style="margin:0;color:var(--primary);">የቅጣት ክፍያ ደረሰኝ</h4>
                <div class="text-muted" style="font-size:0.85rem;margin-top:4px;">ደረሰኝ ቁጥር፦ #<?= e($receipt['id']) ?> | ቀን፦ <?= e(formatDate(substr($receipt['paid_at'], 0, 10))) ?></div>
            </div>

            <div style="font-size:0.95rem;line-height:1.8;">
                <div><strong>የአባል ስም፦</strong> <?= e($receipt['member_name']) ?> (<?= e($receipt['member_code'] ?? '—') ?>)</div>
                <div><strong>ስልክ ቁጥር፦</strong> <?= e($receipt['member_phone']) ?></div>
                <div><strong>የመጽሐፍ ርዕስ፦</strong> <?= e($receipt['book_title']) ?> (ኮድ፦ <?= e($receipt['copy_code']) ?>)</div>
                <div><strong>የመመለሻ ቀን የነበረው፦</strong> <?= e(formatDate($receipt['due_date'])) ?></div>
                <hr style="border:0;border-top:1px solid #ddd;margin:12px 0;">
                <div style="font-size:1.15rem;color:var(--success);display:flex;justify-content:space-between;">
                    <strong>የተከፈለ መጠን፦</strong>
                    <strong><?= number_format($receipt['amount'], 2) ?> ብር</strong>
                </div>
                <?php
                $rem = max(0.00, (float)$receipt['overdue_fine'] - (float)$receipt['fine_paid'] - (float)$receipt['fine_waived']);
                ?>
                <div style="display:flex;justify-content:space-between;color:var(--text-muted);font-size:0.9rem;margin-top:4px;">
                    <span>ቀሪ ያልተከፈለ ቅጣት፦</span>
                    <span><?= number_format($rem, 2) ?> ብር</span>
                </div>
                <div style="margin-top:10px;font-size:0.85rem;color:var(--text-muted);">
                    <strong>የተቀባይ ፊርማ/ስም፦</strong> <?= e($receipt['staff_name'] ?? 'ላይብረሪያን') ?>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:20px;" class="no-print">
                <button class="btn btn-primary btn-block" onclick="window.print()"><i class="bi bi-printer"></i> ደረሰኝ አትም</button>
                <a href="fines.php" class="btn btn-outline btn-block">ተመለስ</a>
            </div>
        </div>
        <?php
        include __DIR__ . '/../includes/footer.php';
        exit;
    }
}

// Search and filter
$q = clean($_GET['q'] ?? '');
$where = "br.overdue_fine > (br.fine_paid + br.fine_waived)";

if ($q !== '') {
    $like = mysqli_real_escape_string($conn, $q);
    $where .= " AND (u.full_name LIKE '%$like%' OR u.phone LIKE '%$like%' OR b.title LIKE '%$like%' OR bc.copy_code LIKE '%$like%')";
}

$records = mysqli_query($conn, "
    SELECT br.*, b.title, b.author, bc.copy_code, u.full_name, u.phone, m.id AS member_table_id,
           (br.overdue_fine - br.fine_paid - br.fine_waived) AS net_fine
    FROM borrow_records br
    JOIN books b ON b.id = br.book_id
    LEFT JOIN book_copies bc ON bc.id = br.book_copy_id
    JOIN members m ON m.id = br.member_id
    JOIN users u ON u.id = m.user_id
    WHERE $where
    ORDER BY net_fine DESC, br.due_date ASC
");

$pageTitle = 'ያልተከፈሉ ቅጣቶች';
$activeKey = 'fines';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
    <h3 style="margin:0;">የአባላት ያልተከፈለ ቅጣት መቆጣጠሪያ</h3>
</div>

<form method="get" class="card card-pad mb-3">
    <div class="input-group">
        <i class="bi bi-search"></i>
        <input class="input" name="q" value="<?= e($q) ?>" placeholder="በአባል ስም፣ ስልክ፣ መጽሐፍ ወይም ቅጂ ኮድ ይፈልጉ…">
    </div>
</form>

<?php if (mysqli_num_rows($records) === 0): ?>
    <div class="empty-state">
        <i class="bi bi-check-circle" style="color:var(--success);font-size:3rem;"></i>
        <h4>ምንም ያልተከፈለ ቅጣት የለም</h4>
        <p class="text-muted">ሁሉም አባላት ቅጣታቸውን አጠናቀዋል ወይም ወቅቱን ጠብቀው መልሰዋል።</p>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:12px;">
    <?php while ($r = mysqli_fetch_assoc($records)): 
        $net = max(0.00, (float)$r['net_fine']);
    ?>
        <div class="card card-pad">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                <div>
                    <h4 style="margin:0 0 4px 0;"><?= e($r['full_name']) ?> <span class="text-muted" style="font-size:0.85rem;">(<?= e($r['phone']) ?>)</span></h4>
                    <div><strong>📖 <?= e($r['title']) ?></strong> <span class="shelf-tag"><?= e($r['copy_code'] ?? '—') ?></span></div>
                    <div class="text-muted" style="font-size:0.85rem;margin-top:4px;">
                        የመመለሻ ቀን፦ <?= e(formatDate($r['due_date'])) ?> · 
                        ሁኔታ፦ <span class="badge <?= $r['status'] === 'borrowed' ? 'badge-warning' : 'badge-secondary' ?>"><?= e($r['status']) ?></span>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:1.25rem;font-weight:700;color:var(--danger);"><?= number_format($net, 2) ?> ብር</div>
                    <div class="text-muted" style="font-size:0.8rem;">ጠቅላላ፦ <?= number_format($r['overdue_fine'], 2) ?> | የተከፈለ፦ <?= number_format($r['fine_paid'], 2) ?></div>
                </div>
            </div>

            <div style="display:flex;gap:10px;margin-top:14px;border-top:1px solid #eee;padding-top:10px;flex-wrap:wrap;">
                <!-- Payment Form -->
                <form method="post" style="display:flex;gap:6px;flex:1;min-width:260px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pay_fine">
                    <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                    <input type="number" step="0.5" min="0.5" max="<?= $net ?>" name="amount" value="<?= $net ?>" class="input" style="width:110px;padding:6px 10px;" required>
                    <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-cash"></i> ክፍያ ተቀበል</button>
                </form>

                <!-- Waive Button with Form Prompt -->
                <button class="btn btn-outline btn-sm" style="color:var(--warning);border-color:var(--warning);" type="button" onclick="showWaiveModal(<?= (int)$r['id'] ?>, <?= $net ?>, '<?= e(addslashes($r['full_name'])) ?>')">
                    <i class="bi bi-shield-x"></i> ይቅርታ አድርግ
                </button>
            </div>
        </div>
    <?php endwhile; ?>
    </div>
<?php endif; ?>

<!-- Waive Modal -->
<div id="waiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div class="card card-pad" style="max-width:440px;width:90%;margin:auto;">
        <h4 style="margin:0 0 10px 0;color:var(--warning);"><i class="bi bi-exclamation-triangle"></i> የቅጣት ይቅርታ</h4>
        <p style="font-size:0.9rem;" id="waiveModalText"></p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="waive_fine">
            <input type="hidden" name="record_id" id="waiveRecordId" value="">
            
            <div class="form-group mb-2">
                <label>የይቅርታ መጠን (ብር)፦</label>
                <input type="number" step="0.5" min="0.5" id="waiveAmount" name="amount" class="input" required>
            </div>

            <div class="form-group mb-3">
                <label>የይቅርታ ምክንያት (ግዴታ)፦</label>
                <textarea name="reason" class="input" rows="3" placeholder="ምክንያቱን በግልጽ ይጻፉ (ምሳሌ፦ በሕመም ወይም በልዩ ፈቃድ ምክንያት)..." required></textarea>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-warning btn-block">አረጋግጥና ይቅር በል</button>
                <button type="button" class="btn btn-outline btn-block" onclick="hideWaiveModal()">ሰርዝ</button>
            </div>
        </form>
    </div>
</div>

<script>
function showWaiveModal(recordId, maxNet, memberName) {
    document.getElementById('waiveRecordId').value = recordId;
    document.getElementById('waiveAmount').value = maxNet;
    document.getElementById('waiveAmount').max = maxNet;
    document.getElementById('waiveModalText').textContent = 'ለአባል ' + memberName + ' የተጣለውን ቅጣት በከፊል ወይም ሙሉ በሙሉ ይቅር ለማለት ምክንያቱን ይጻፉ።';
    document.getElementById('waiveModal').style.display = 'flex';
}
function hideWaiveModal() {
    document.getElementById('waiveModal').style.display = 'none';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
