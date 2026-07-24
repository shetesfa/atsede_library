<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$user = current_user();
$borrowDays = (int)get_setting($conn, 'borrow_days', 14);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $requestId = (int)$_POST['request_id'];
    $decision = $_POST['decision'] ?? '';

    $req = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT rq.*, m.user_id AS member_user_id, b.title FROM borrow_requests rq
        JOIN members m ON m.id=rq.member_id JOIN books b ON b.id=rq.book_id
        WHERE rq.id=$requestId AND rq.status='pending'"));

    if (!$req) {
        flash('msg', 'ይህ ጥያቄ ከእንግዲህ በመጠባበቅ ላይ አይደለም።', 'warning');
    } elseif ($decision === 'reject') {
        mysqli_query($conn, "UPDATE borrow_requests SET status='rejected', decided_at=NOW(), decided_by=" . (int)$user['id'] . " WHERE id=$requestId");
        notify($conn, $req['member_user_id'], 'ጥያቄ ተቀባይነት አላገኘም', '"' . $req['title'] . '" በዚህ ጊዜ ማረጋገጥ አልተቻለም።', 'borrow_rejected', 'member/my_books.php');
        audit($conn, $user['id'], 'borrow_request_rejected', "request_id:$requestId");
        flash('msg', 'ጥያቄው ውድቅ ተደርጓል።', 'success');
    } elseif ($decision === 'approve') {
        if (is_member_blocked($conn, (int)$req['member_user_id'])) {
            flash('msg', 'አባሉ ታግዷል — መዋስ መፈቀድ አይቻልም።', 'danger');
        } elseif ($req['type'] === 'reserve') {
            mysqli_query($conn, "UPDATE borrow_requests SET status='approved', decided_at=NOW(), decided_by=" . (int)$user['id'] . " WHERE id=$requestId");
            notify($conn, $req['member_user_id'], 'ማስያዝ ተረጋግጧል', '"' . $req['title'] . '" ለእርስዎ ተይዟል። ቅጂ ለመውሰድ ዝግጁ ሲሆን እናሳውቅዎታለን።', 'general', 'member/my_books.php');
            flash('msg', 'ማስያዝ ጸድቷል።', 'success');
        } else {
            $copy = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM book_copies WHERE book_id=" . (int)$req['book_id'] . " AND status='available' LIMIT 1"));
            if (!$copy) {
                flash('msg', 'አሁን ለማበደር የሚገኝ ቅጂ የለም።', 'danger');
            } else {
                $copyId = (int)$copy['id'];
                mysqli_query($conn, "UPDATE book_copies SET status='borrowed' WHERE id=$copyId");
                $due = date('Y-m-d', strtotime("+$borrowDays days"));
                $stmt = mysqli_prepare($conn, "INSERT INTO borrow_records (request_id, member_id, book_copy_id, book_id, due_date, issued_by) VALUES (?,?,?,?,?,?)");
                $issuedBy = (int)$user['id'];
                mysqli_stmt_bind_param($stmt, 'iiiisi', $requestId, $req['member_id'], $copyId, $req['book_id'], $due, $issuedBy);
                mysqli_stmt_execute($stmt);
                mysqli_query($conn, "UPDATE borrow_requests SET status='approved', decided_at=NOW(), decided_by=$issuedBy WHERE id=$requestId");
                notify($conn, $req['member_user_id'], 'የመዋስ ጥያቄ ጸድቷል', '"' . $req['title'] . '" ለመውሰድ ዝግጁ ነው። የመመለሻ ቀን ' . formatDate($due) . '።', 'borrow_approved', 'member/my_books.php');
                audit($conn, $user['id'], 'borrow_approved', "request_id:$requestId copy_id:$copyId");
                flash('msg', 'የመዋስ ጥያቄ ጸድቆ መጽሐፉ ተሰጥቷል።', 'success');
            }
        }
    }
    redirect('requests.php');
}

$requests = mysqli_query($conn, "
  SELECT rq.*, b.title, b.author, u.full_name, u.phone,
    (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id=rq.book_id AND bc.status='available') AS available_count
  FROM borrow_requests rq
  JOIN books b ON b.id=rq.book_id JOIN members m ON m.id=rq.member_id JOIN users u ON u.id=m.user_id
  WHERE rq.status='pending' ORDER BY rq.requested_at ASC");

$pageTitle = __('requests');
$activeKey = 'requests';
include __DIR__ . '/../includes/header.php';
?>

<div class="section-title" style="margin-top:0;">በመጠባበቅ ላይ ያሉ ጥያቄዎች</div>

<?php if (mysqli_num_rows($requests) === 0): ?>
  <div class="empty-state"><i class="bi bi-check2-circle"></i><h4>ምንም የቀረ ነገር የለም</h4><p>የመዋስ ወይም የማስያዝ ጥያቄ የለም።</p></div>
<?php else: while ($r = mysqli_fetch_assoc($requests)): ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;gap:10px;">
      <div>
        <strong><?= e($r['title']) ?></strong><br>
        <span class="text-muted" style="font-size:.78rem;"><?= e($r['author']) ?></span>
      </div>
      <span class="badge <?= $r['type']==='reserve'?'badge-gold':'badge-warning' ?>"><?= $r['type']==='reserve' ? 'ማስያዝ' : 'መዋስ' ?></span>
    </div>
    <div class="text-muted" style="font-size:.8rem;margin-top:8px;">
      <i class="bi bi-person"></i> <?= e($r['full_name']) ?> · <?= e($r['phone']) ?><br>
      <i class="bi bi-clock"></i> የተጠየቀው <?= formatDate($r['requested_at']) ?> ·
      <?= $r['available_count'] ?> ቅጂ(ዎች) ይገኛል
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <form method="post" style="flex:1;">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="decision" value="approve">
        <button class="btn btn-success btn-block btn-sm" <?= ($r['type']==='borrow' && $r['available_count']==0) ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i> <?= __('approve') ?></button>
      </form>
      <form method="post" style="flex:1;" onsubmit="return confirmAction('ይህን ጥያቄ ውድቅ ማድረግ ይፈልጋሉ?', this)">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="decision" value="reject">
        <button class="btn btn-outline btn-block btn-sm" style="color:var(--danger);border-color:var(--danger);"><i class="bi bi-x-lg"></i> <?= __('reject') ?></button>
      </form>
    </div>
  </div>
<?php endwhile; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
