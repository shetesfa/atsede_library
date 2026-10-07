<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();
clear_expired_member_blocks($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $userId = (int)$_POST['user_id'];
    $decision = $_POST['decision'] ?? '';

    if ($decision === 'block') {
        $days = max(1, (int)($_POST['block_days'] ?? 7));
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        mysqli_query($conn, "UPDATE users SET status='suspended' WHERE id=$userId AND role='member'");
        mysqli_query($conn, "UPDATE members SET blocked_until='$until' WHERE user_id=$userId");
        notify($conn, $userId, 'መለያዎ ታግዷል', "ለ $days ቀናት መግባት እና መዋስ ተከለከለ። እስከ " . formatDate($until) . " ድረስ።", 'general', 'login.php');
        
        $uRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, telegram_chat_id FROM users WHERE id=$userId"));
        if ($uRow && $uRow['telegram_chat_id']) {
            $blockMsg = "🚫 <b>ሰላም፣ " . htmlspecialchars($uRow['full_name']) . "</b>\n\n" .
                        "የቤተ-መጻሕፍት መለያዎ ለ $days ቀናት ታግዷል። እስከ " . formatDate($until) . " ድረስ መዋስ አይችሉም።";
            telegram_send($conn, $uRow['telegram_chat_id'], $blockMsg);
        }

        audit($conn, $user['id'], 'member_blocked', "user_id:$userId days:$days until:$until");
        flash('msg', "አባሉ ለ $days ቀናት ታግዷል።", 'success');
    } elseif (in_array($decision, ['active','rejected','suspended'])) {
        mysqli_query($conn, "UPDATE users SET status='$decision' WHERE id=$userId AND role='member'");
        if ($decision === 'active') {
            mysqli_query($conn, "UPDATE members SET blocked_until=NULL WHERE user_id=$userId");
            notify($conn, $userId, 'እንኳን ወደ ቤተ መጻሕፍት በደህና መጡ', 'የአባልነት ጥያቄዎ ጸድቷል። አሁን መጻሕፍትን መዋስ ይችላሉ።', 'registration_approved', 'member/dashboard.php');
            
            $uRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, telegram_chat_id FROM users WHERE id=$userId"));
            if ($uRow && $uRow['telegram_chat_id']) {
                $approveMsg = "🎉 <b>እንኳን ደስ አለዎት፣ " . htmlspecialchars($uRow['full_name']) . "!</b>\n\n" .
                              "✅ <b>የአባልነት ጥያቄዎ በአስተዳዳሪ ጸድቋል!</b>\n\n" .
                              "አሁን በመተግበሪያው ወይም በዚህ ቦት አማካኝነት መጻሕፍትን መፈለግ እና መዋስ ይችላሉ።\n\n" .
                              "📌 አገልግሎቱን ለመጀመር ከታች ያሉትን አዝራሮች ይጠቀሙ ወይም /start ይጫኑ።";
                telegram_send($conn, $uRow['telegram_chat_id'], $approveMsg, get_bot_main_keyboard());
            }
        } elseif ($decision === 'rejected') {
            mysqli_query($conn, "UPDATE members SET blocked_until=NULL WHERE user_id=$userId");
            $uRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, telegram_chat_id FROM users WHERE id=$userId"));
            if ($uRow && $uRow['telegram_chat_id']) {
                $rejectMsg = "❌ <b>ሰላም፣ " . htmlspecialchars($uRow['full_name']) . "</b>\n\n" .
                             "የአባልነት ምዝገባ ጥያቄዎ ተቀባይነት አላገኘም። ለተጨማሪ መረጃ ቤተ-መጻሕፍቱን በአካል ያነጋግሩ።";
                telegram_send($conn, $uRow['telegram_chat_id'], $rejectMsg, ['remove_keyboard' => true]);
            }
        } elseif ($decision === 'suspended') {
            $uRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT full_name, telegram_chat_id FROM users WHERE id=$userId"));
            if ($uRow && $uRow['telegram_chat_id']) {
                $blockMsg = "🚫 <b>ሰላም፣ " . htmlspecialchars($uRow['full_name']) . "</b>\n\n" .
                            "የቤተ-መጻሕፍት መለያዎ ለጊዜው ታግዷል። ለተጨማሪ መረጃ ቤተ-መጻሕፍቱን ያነጋግሩ።";
                telegram_send($conn, $uRow['telegram_chat_id'], $blockMsg);
            }
        }
        audit($conn, $user['id'], 'member_status_' . $decision, "user_id:$userId");
        flash('msg', 'የአባል ሁኔታ ዘምኗል።', 'success');
    }
    redirect('members.php?status=' . ($_GET['status'] ?? 'pending'));
}

$filter = $_GET['status'] ?? 'pending';
$q = clean($_GET['q'] ?? '');
$where = "u.role='member' AND u.status='" . mysqli_real_escape_string($conn, $filter) . "'";
if ($q !== '') {
    $like = mysqli_real_escape_string($conn, $q);
    $where .= " AND (u.full_name LIKE '%$like%' OR u.phone LIKE '%$like%' OR u.username LIKE '%$like%' OR m.student_id LIKE '%$like%')";
}

$members = mysqli_query($conn, "
  SELECT u.*, m.class, m.student_id, m.blocked_until,
    (SELECT COUNT(*) FROM borrow_records br JOIN members mb ON mb.id=br.member_id WHERE mb.user_id=u.id AND br.status='borrowed') AS active_loans,
    (SELECT COUNT(*) FROM borrow_records br JOIN members mb ON mb.id=br.member_id WHERE mb.user_id=u.id) AS total_borrows
  FROM users u JOIN members m ON m.user_id=u.id
  WHERE $where ORDER BY u.created_at DESC");

$pageTitle = __('members');
$activeKey = 'members';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;gap:8px;margin-bottom:14px;overflow-x:auto;">
  <?php foreach (['pending'=>__('pending'),'active'=>__('active'),'rejected'=>__('rejected'),'suspended'=>__('suspended')] as $k=>$lbl): ?>
    <a href="?status=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn <?= $filter===$k?'btn-navy':'btn-outline' ?> btn-sm"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<form method="get" class="card card-pad mb-3">
  <input type="hidden" name="status" value="<?= e($filter) ?>">
  <div class="input-group">
    <i class="bi bi-search"></i>
    <input class="input" name="q" value="<?= e($q) ?>" placeholder="በመታወቂያ ቁጥር (አጸደቤይ01)፣ በስም ወይም በስልክ ይፈልጉ…">
  </div>
</form>

<?php if (mysqli_num_rows($members) === 0): ?>
  <div class="empty-state"><i class="bi bi-people"></i><h4>ምንም አባል አልተገኘም</h4></div>
<?php else: while ($m = mysqli_fetch_assoc($members)): ?>
  <div class="card card-pad mb-2">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
      <div style="flex:1;cursor:pointer;" onclick="openMemberDetail(<?= htmlspecialchars(json_encode([
        'id' => (int)$m['id'],
        'full_name' => $m['full_name'],
        'phone' => $m['phone'],
        'username' => $m['username'],
        'class' => $m['class'] ?: '—',
        'student_id' => $m['student_id'] ?: '—',
        'created_at' => formatDate($m['created_at']),
        'last_login' => $m['last_login'] ? formatDate($m['last_login']) : '—',
        'active_loans' => (int)$m['active_loans'],
        'total_borrows' => (int)$m['total_borrows'],
        'blocked_until' => $m['blocked_until'] ? formatDate($m['blocked_until']) : null,
        'status' => $m['status'],
      ]), ENT_QUOTES, 'UTF-8') ?>)">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
          <span class="badge" style="background:#0047AB;color:#FFB703;font-weight:900;font-size:.82rem;padding:3px 10px;border-radius:12px;letter-spacing:0.5px;">
            <?= e($m['student_id'] ?: 'አልተሰጠም') ?>
          </span>
          <strong style="color:var(--navy);font-size:1.02rem;"><?= e($m['full_name']) ?></strong>
          <i class="bi bi-chevron-right text-muted" style="font-size:.75rem;"></i>
        </div>
        <div class="text-muted" style="font-size:.82rem;line-height:1.5;">
          <span><i class="bi bi-telephone"></i> <?= e($m['phone']) ?></span> · 
          <span>ክፍል <?= e($m['class'] ?: '—') ?></span><br>
          <span style="font-size:.74rem;">የተመዘገበው <?= formatDate($m['created_at']) ?></span>
        </div>
        <?php if ($m['blocked_until'] && strtotime($m['blocked_until']) > time()): ?>
          <span class="badge badge-danger" style="margin-top:6px;">እስከ <?= formatDate($m['blocked_until']) ?> ታግዷል</span>
        <?php endif; ?>
      </div>
      <button type="button" class="btn btn-outline btn-sm" onclick="event.stopPropagation(); openMemberDetail(<?= htmlspecialchars(json_encode([
        'id' => (int)$m['id'],
        'full_name' => $m['full_name'],
        'phone' => $m['phone'],
        'username' => $m['username'],
        'class' => $m['class'] ?: '—',
        'student_id' => $m['student_id'] ?: '—',
        'created_at' => formatDate($m['created_at']),
        'last_login' => $m['last_login'] ? formatDate($m['last_login']) : '—',
        'active_loans' => (int)$m['active_loans'],
        'total_borrows' => (int)$m['total_borrows'],
        'blocked_until' => $m['blocked_until'] ? formatDate($m['blocked_until']) : null,
        'status' => $m['status'],
      ]), ENT_QUOTES, 'UTF-8') ?>)"><i class="bi bi-eye"></i></button>
    </div>
    <?php if ($filter === 'pending'): ?>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <form method="post" style="flex:1;"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="decision" value="active"><button class="btn btn-success btn-block btn-sm"><?= __("approve") ?></button></form>
      <form method="post" style="flex:1;"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="btn btn-outline btn-block btn-sm" style="color:var(--danger);border-color:var(--danger);"><?= __("reject") ?></button></form>
    </div>
    <?php elseif ($filter === 'active'): ?>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <button class="btn btn-outline btn-sm" style="color:var(--warning);border-color:var(--warning);" onclick="openBlockSheet(<?= (int)$m['id'] ?>, '<?= e(addslashes($m['full_name'])) ?>')"><i class="bi bi-slash-circle"></i> አግድ</button>
    </div>
    <?php elseif ($filter === 'suspended'): ?>
    <div style="margin-top:10px;">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="decision" value="active"><button class="btn btn-success btn-sm"><i class="bi bi-unlock"></i> መቆለፊያ አስወግድ</button></form>
    </div>
    <?php endif; ?>
  </div>
<?php endwhile; endif; ?>

<div class="sheet-overlay" id="member-detail-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" id="md-name">የአባል ዝርዝር</div>
    <div id="md-body" style="font-size:.86rem;line-height:1.7;"></div>
    <button class="btn btn-outline btn-block mt-2" type="button" onclick="closeSheet('member-detail-sheet')"><?= __('close') ?></button>
  </div>
</div>

<div class="sheet-overlay" id="block-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">አባል አግድ</div>
    <p class="text-muted" style="font-size:.84rem;" id="block-target-name"></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="user_id" id="block-user-id" value="">
      <input type="hidden" name="decision" value="block">
      <div class="field"><label>ለምን ያህል ቀናት? (መግባት እና መዋስ ይከለከላል)</label><input class="input" type="number" name="block_days" min="1" max="365" value="7" required></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('block-sheet')"><?= __('cancel') ?></button>
        <button class="btn btn-gold btn-block" onclick="return confirm('ይህን አባል ለተመዘገቡት ቀናት ማገድ ይፈልጋሉ?')">አግድ</button>
      </div>
    </form>
  </div>
</div>

<script>
function openMemberDetail(d) {
  document.getElementById('md-name').textContent = d.full_name;
  let html = '<div style="display:grid;gap:6px;">';
  html += '<div style="margin-bottom:8px;"><span class="badge" style="background:#0047AB;color:#FFB703;font-weight:900;font-size:.9rem;padding:5px 12px;border-radius:12px;">' + d.student_id + '</span></div>';
  html += '<div><span class="text-muted">ስልክ፦</span> ' + d.phone + '</div>';
  html += '<div><span class="text-muted">የተጠቃሚ ስም፦</span> ' + d.username + '</div>';
  html += '<div><span class="text-muted">ክፍል፦</span> ' + d.class + '</div>';
  html += '<div><span class="text-muted">መታወቂያ፦</span> ' + d.student_id + '</div>';
  html += '<div><span class="text-muted">የተመዘገበበት፦</span> ' + d.created_at + '</div>';
  html += '<div><span class="text-muted">የመጨረሻ መግቢያ፦</span> ' + d.last_login + '</div>';
  html += '<div><span class="text-muted">በስራ ላይ ያሉ ውሶች፦</span> ' + d.active_loans + '</div>';
  html += '<div><span class="text-muted">ጠቅላላ ውሶች፦</span> ' + d.total_borrows + '</div>';
  if (d.blocked_until) html += '<div><span class="badge badge-danger">እስከ ' + d.blocked_until + ' ታግዷል</span></div>';
  html += '</div>';
  document.getElementById('md-body').innerHTML = html;
  openSheet('member-detail-sheet');
}
function openBlockSheet(userId, name) {
  document.getElementById('block-user-id').value = userId;
  document.getElementById('block-target-name').textContent = name + ' — በዚህ ጊዜ መግባት እና መዋስ አይችልም።';
  openSheet('block-sheet');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
