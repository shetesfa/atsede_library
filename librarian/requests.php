<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_once __DIR__ . '/../includes/LibraryService.php';
require_role(['librarian','admin']);

$user = current_user();
$borrowDays = (int)get_setting($conn, 'borrow_days', 14);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $rejectionReason = clean($_POST['rejection_reason'] ?? '');
    $selectedCopyId = (int)($_POST['copy_id'] ?? 0);

    $req = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rq.*, m.user_id AS member_user_id, b.title, b.author 
        FROM borrow_requests rq
        JOIN members m ON m.id = rq.member_id 
        JOIN books b ON b.id = rq.book_id
        WHERE rq.id = $requestId AND rq.status = 'pending'"));

    if (!$req) {
        flash('msg', 'ይህ ጥያቄ ከእንግዲህ በመጠባበቅ ላይ አይደለም።', 'warning');
    } elseif ($decision === 'reject') {
        $reasonEsc = mysqli_real_escape_string($conn, $rejectionReason ?: 'በላይብረሪያን ውድቅ ተደርጓል።');
        $decidedBy = (int)$user['id'];
        
        mysqli_query($conn, "UPDATE borrow_requests 
            SET status='rejected', rejection_reason='$reasonEsc', decided_at=NOW(), decided_by=$decidedBy 
            WHERE id=$requestId");
            
        notify($conn, $req['member_user_id'], 'የመዋስ ጥያቄ ውድቅ ተደርጓል', 
            '"' . $req['title'] . '"፦ ' . ($rejectionReason ?: 'ጥያቄዎ ተቀባይነት አላገኘም።'), 
            'borrow_rejected', 'member/my_books.php');
            
        audit($conn, $user['id'], 'borrow_request_rejected', "request_id:$requestId reason:$rejectionReason");
        flash('msg', 'ጥያቄው ውድቅ ተደርጓል። ምክንያቱም ለአባሉ ተልኳል።', 'success');
        
    } elseif ($decision === 'approve') {
        $targetCopyId = $selectedCopyId;
        if (!$targetCopyId) {
            $ver = verify_borrow_eligibility($conn, $req['member_id'], $req['book_id'], null);
            $targetCopyId = (int)($ver['copy_id'] ?? 0);
        }

        if (!$targetCopyId) {
            flash('msg', 'ጥያቄውን ማጽደቅ አልተቻለም፦ የሚገኝ ቅጂ የለም ወይም አባሉ ብቁ አይደለም።', 'danger');
        } else {
            $due = date('Y-m-d', strtotime("+$borrowDays days"));
            $result = issue_copy($conn, (int)$req['member_id'], $targetCopyId, $due, (int)$user['id'], '', $requestId);
            if ($result['success']) {
                flash('msg', $result['message'], 'success');
            } else {
                flash('msg', $result['message'], 'danger');
            }
        }
    }
    redirect('requests.php');
}

// Fetch pending requests with eligibility status
$requestsQuery = mysqli_query($conn, "
    SELECT rq.*, b.title, b.author, b.cover_image, b.is_borrowable, b.non_borrowable_reason,
           u.full_name, u.phone, m.id AS member_table_id, m.class, m.student_id
    FROM borrow_requests rq
    JOIN books b ON b.id = rq.book_id
    JOIN members m ON m.id = rq.member_id
    JOIN users u ON u.id = m.user_id
    WHERE rq.status = 'pending'
    ORDER BY rq.requested_at ASC
");

$pageTitle = __('requests');
$activeKey = 'requests';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">በመጠባበቅ ላይ ያሉ የመዋስ ጥያቄዎች</div>

<?php if (mysqli_num_rows($requestsQuery) === 0): ?>
  <div class="empty-state">
    <i class="bi bi-check2-circle text-success" style="font-size:2.5rem;"></i>
    <h4>ምንም በመጠባበቅ ላይ ያለ ጥያቄ የለም</h4>
    <p>ሁሉም ጥያቄዎች ተስተናግደዋል።</p>
  </div>
<?php else: 
  while ($r = mysqli_fetch_assoc($requestsQuery)): 
    $ver = verify_borrow_eligibility($conn, $r['member_table_id'], $r['book_id']);
    $availableCopies = mysqli_query($conn, "SELECT id, copy_code FROM book_copies WHERE book_id=" . (int)$r['book_id'] . " AND status='available'");
?>
  <div class="card card-pad mb-3" style="border-left: 5px solid <?= $ver['can_borrow'] ? 'var(--success)' : 'var(--warning)' ?>;">
    
    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
      <div>
        <h3 style="font-size:1.05rem;color:var(--navy);margin:0 0 3px;"><?= e($r['title']) ?></h3>
        <span class="text-muted" style="font-size:.8rem;">በ <?= e($r['author']) ?></span>
      </div>
      <span class="badge <?= $ver['can_borrow'] ? 'badge-success' : 'badge-warning' ?>">
        <?= $ver['can_borrow'] ? 'ለማጽደቅ ዝግጁ' : 'ማረጋገጫ ያስፈልጋል' ?>
      </span>
    </div>

    <div class="text-muted" style="font-size:.82rem;margin-bottom:10px;">
      <span class="badge" style="background:#0047AB;color:#FFB703;font-weight:900;font-size:.82rem;padding:3px 10px;border-radius:12px;letter-spacing:0.5px;margin-right:6px;">
        <?= e($r['student_id'] ?: '—') ?>
      </span>
      <i class="bi bi-person"></i> <strong><?= e($r['full_name']) ?></strong> (<?= e($r['phone']) ?>)
      <?php if ($r['class']): ?> · ክፍል <?= e($r['class']) ?><?php endif; ?>
      <br>
      <i class="bi bi-clock"></i> የተጠየቀው፦ <?= formatDate($r['requested_at']) ?>
    </div>

    <!-- Verification Checklist Box -->
    <div style="background:var(--slate-50);border-radius:10px;padding:10px 12px;margin-bottom:12px;border:1px solid var(--line);">
      <div style="font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:6px;">
        <i class="bi bi-list-check"></i> የውሰት ማረጋገጫ ቼክሊስት
      </div>
      <div class="d-flex flex-column gap-1" style="font-size:.83rem;">
        <?php foreach ($ver['checks'] as $chkKey => $chk): ?>
          <div class="d-flex align-items-center justify-content-between" style="padding:2px 0;">
            <span>
              <?php if ($chk['passed']): ?>
                <i class="bi bi-check-circle-fill text-success me-1"></i>
              <?php else: ?>
                <i class="bi bi-x-circle-fill text-danger me-1"></i>
              <?php endif; ?>
              <?= e($chk['title']) ?>
            </span>
            <span class="<?= $chk['passed'] ? 'text-success' : 'text-danger font-bold' ?>" style="font-size:.78rem;">
              <?= e($chk['detail']) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Actions Form -->
    <div class="d-flex gap-2">
      <?php if ($ver['can_borrow']): ?>
        <form method="post" style="flex:1;">
          <?= csrf_field() ?>
          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="decision" value="approve">
          
          <?php if (mysqli_num_rows($availableCopies) > 1): ?>
            <div class="field mb-2">
              <label style="font-size:.78rem;">የቅጂ ኮድ ምረጥ</label>
              <select class="input input-sm" name="copy_id">
                <?php while ($cp = mysqli_fetch_assoc($availableCopies)): ?>
                  <option value="<?= (int)$cp['id'] ?>">ቅጂ ኮድ፦ <?= e($cp['copy_code']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-success btn-block btn-sm">
            <i class="bi bi-check-lg"></i> አጽድቅ እና መጽሐፍ አስረክብ
          </button>
        </form>
      <?php else: ?>
        <div style="flex:1;">
          <button type="button" class="btn btn-outline btn-block btn-sm" disabled style="opacity:0.6;cursor:not-allowed;">
            <i class="bi bi-shield-x"></i> ማጽደቅ አይቻልም (መስፈርት አልተሟላም)
          </button>
        </div>
      <?php endif; ?>

      <button type="button" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);"
              onclick="openRejectModal(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['title'])) ?>', '<?= e(addslashes($r['full_name'])) ?>')">
        <i class="bi bi-x-lg"></i> <?= __('reject') ?>
      </button>
    </div>

  </div>
<?php endwhile; endif; ?>

<!-- Sheet: Rejection Modal with Reason -->
<div class="sheet-overlay" id="reject-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" style="color:var(--danger);"><i class="bi bi-x-circle"></i> ጥያቄውን ውድቅ አድርግ</div>
    
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="request_id" id="reject-request-id" value="0">
      <input type="hidden" name="decision" value="reject">

      <p id="reject-summary" class="text-muted" style="font-size:.85rem;margin-bottom:12px;"></p>

      <div class="field">
        <label>የውድቅ ምክንያት <span class="text-danger">*</span></label>
        <select class="input mb-2" onchange="if(this.value) document.getElementById('reject-reason-input').value = this.value;">
          <option value="">-- የተለመደ ምክንያት ምረጥ --</option>
          <option value="የዚህ ወር የአባልነት ክፍያ ስላልተከፈለ">የዚህ ወር የአባልነት ክፍያ ስላልተከፈለ</option>
          <option value="ይህ መጽሐፍ ለውሰት የማይፈቀድ ስለሆነ">ይህ መጽሐፍ ለውሰት የማይፈቀድ ስለሆነ</option>
          <option value="ለጊዜው የሚገኝ አካላዊ ቅጂ ባለመኖሩ">ለጊዜው የሚገኝ አካላዊ ቅጂ ባለመኖሩ</option>
          <option value="የተፈቀደው ከፍተኛ የውሰት ገደብ ላይ ስለደረሱ">የተፈቀደው ከፍተኛ የውሰት ገደብ ላይ ስለደረሱ</option>
        </select>
        <textarea class="input" name="rejection_reason" id="reject-reason-input" rows="2" placeholder="የውድቅ ምክንያቱን እዚህ ይጻፉ..." required></textarea>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-block btn-sm" style="background:var(--danger);color:#fff;">
          <i class="bi bi-x-circle"></i> ውድቅ ማድረጉን አረጋግጥ
        </button>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeSheet('reject-sheet')">
          <?= __('cancel') ?>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openRejectModal(reqId, title, memberName) {
  document.getElementById('reject-request-id').value = reqId;
  document.getElementById('reject-summary').textContent = 'አባል፦ ' + memberName + ' | መጽሐፍ፦ ' + title;
  document.getElementById('reject-reason-input').value = '';
  openSheet('reject-sheet');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
