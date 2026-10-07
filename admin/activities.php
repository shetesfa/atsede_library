<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

// Pagination
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Action filtering
$filterAction = clean($_GET['action_type'] ?? '');
$searchUser = clean($_GET['user_query'] ?? '');

$where = ["a.action NOT IN ('login', 'logout')"]; // Hide minor login/logout by default
$params = [];
$types = '';

if ($filterAction !== '') {
    $where[] = "a.action LIKE ?";
    $params[] = "%$filterAction%";
    $types .= 's';
}

if ($searchUser !== '') {
    $where[] = "(u.full_name LIKE ? OR u.phone LIKE ? OR m.student_id LIKE ?)";
    $like = "%$searchUser%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$whereSql = implode(' AND ', $where);

// Total count
$countSql = "SELECT COUNT(*) c FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN members m ON m.user_id=u.id WHERE $whereSql";
$countStmt = mysqli_prepare($conn, $countSql);
if ($params) {
    mysqli_stmt_bind_param($countStmt, $types, ...$params);
}
mysqli_stmt_execute($countStmt);
$totalRecords = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($countStmt))['c'];
$totalPages = max(1, ceil($totalRecords / $perPage));

// Main Query
$sql = "SELECT a.*, u.full_name, u.role, u.phone, m.student_id 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id=a.user_id 
        LEFT JOIN members m ON m.user_id=u.id 
        WHERE $whereSql 
        ORDER BY a.created_at DESC 
        LIMIT ? OFFSET ?";
$stmt = mysqli_prepare($conn, $sql);
$mainTypes = $types . 'ii';
$mainParams = array_merge($params, [$perPage, $offset]);
mysqli_stmt_bind_param($stmt, $mainTypes, ...$mainParams);
mysqli_stmt_execute($stmt);
$activities = mysqli_stmt_get_result($stmt);

/**
 * Helper to humanize actions in clean Amharic
 */
function humanize_action($action, $details = '') {
    $details = (string)$details;
    switch ($action) {
        case 'borrow_request_created':
            return ['badge' => 'badge-gold', 'icon' => 'bi-journal-plus', 'text' => 'የመዋስ ጥያቄ አቀረበ', 'detail' => $details];
        case 'qr_borrow_request':
            return ['badge' => 'badge-gold', 'icon' => 'bi-qr-code-scan', 'text' => 'በQR ኮድ የመዋስ ጥያቄ አቀረበ', 'detail' => $details];
        case 'borrow_request_rejected':
            return ['badge' => 'badge-danger', 'icon' => 'bi-x-circle', 'text' => 'የመዋስ ጥያቄ ውድቅ ተደረገ', 'detail' => $details];
        case 'book_created':
        case 'book_created_forced':
            return ['badge' => 'badge-success', 'icon' => 'bi-book-half', 'text' => 'አዲስ መጽሐፍ መዘገበ', 'detail' => $details];
        case 'book_updated':
            return ['badge' => 'badge-navy', 'icon' => 'bi-pencil-square', 'text' => 'የመጽሐፍ መረጃ አሻሻለ', 'detail' => $details];
        case 'book_deleted':
            return ['badge' => 'badge-danger', 'icon' => 'bi-trash', 'text' => 'መጽሐፍ ሰረዘ', 'detail' => $details];
        case 'book_copies_added_existing':
            return ['badge' => 'badge-gold', 'icon' => 'bi-plus-square', 'text' => 'ተጨማሪ የመጽሐፍ ቅጂዎችን ጨመረ', 'detail' => $details];
        case 'member_blocked':
            return ['badge' => 'badge-danger', 'icon' => 'bi-slash-circle', 'text' => 'አባልን አገደ', 'detail' => $details];
        case 'member_status_active':
            return ['badge' => 'badge-success', 'icon' => 'bi-check-circle', 'text' => 'የአባልነት ምዝገባ አጸደቀ', 'detail' => $details];
        case 'member_status_rejected':
            return ['badge' => 'badge-danger', 'icon' => 'bi-x-circle', 'text' => 'የአባልነት ምዝገባ ውድቅ አደረገ', 'detail' => $details];
        case 'notification_broadcast':
            return ['badge' => 'badge-gold', 'icon' => 'bi-megaphone', 'text' => 'አጠቃላይ ማሳወቂያ አስተላለፈ', 'detail' => $details];
        case 'notification_direct':
            return ['badge' => 'badge-navy', 'icon' => 'bi-envelope', 'text' => 'የግል ማሳወቂያ ላከ', 'detail' => $details];
        case 'minimum_payment_updated':
            return ['badge' => 'badge-warning', 'icon' => 'bi-cash-coin', 'text' => 'ዝቅተኛውን ወርሃዊ ክፍያ አሻሻለ', 'detail' => $details];
        case 'settings_updated':
            return ['badge' => 'badge-navy', 'icon' => 'bi-gear', 'text' => 'የሲስተም ደንቦችን አሻሻለ', 'detail' => $details];
        case 'password_changed':
            return ['badge' => 'badge-muted', 'icon' => 'bi-key', 'text' => 'የይለፍ ቃል ቀየረ', 'detail' => ''];
        default:
            $cleaned = str_replace('_', ' ', $action);
            return ['badge' => 'badge-muted', 'icon' => 'bi-activity', 'text' => $cleaned, 'detail' => $details];
    }
}

$pageTitle = 'የድርጊቶች ታሪክ (ማን ምን አደረገ)';
$activeKey = 'activities';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="font-display" style="font-size:1.3rem;color:var(--navy);margin:0;">
      <i class="bi bi-clock-history text-gold"></i> የድርጊቶች ታሪክ (ማን ምን አደረገ)
    </h1>
    <p class="text-muted" style="margin:2px 0 0;font-size:.85rem;">
      በሲስተሙ ውስጥ የተከናወኑ ዋና ዋና ድርጊቶች ዝርዝር (ጠቅላላ፦ <?= number_format($totalRecords) ?> ድርጊቶች)
    </p>
  </div>
  <a href="dashboard.php" class="btn btn-outline btn-sm">
    <i class="bi bi-arrow-left"></i> ወደ ዳሽቦርድ
  </a>
</div>

<!-- Filters Form -->
<form method="get" class="card card-pad mb-3" style="background:#fff;">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label style="font-size:.8rem;font-weight:700;color:var(--navy);">የተጠቃሚ ስም፣ ስልክ ወይም መታወቂያ (ID)</label>
      <input type="text" name="user_query" class="input" placeholder="በመታወቂያ (አጸደቤይ01)፣ በስም ወይም በስልክ ፈልግ…" value="<?= e($searchUser) ?>">
    </div>
    <div class="col-12 col-md-4">
      <label style="font-size:.8rem;font-weight:700;color:var(--navy);">የድርጊት ዓይነት</label>
      <select name="action_type" class="input">
        <option value="">ሁሉም ዋና ድርጊቶች</option>
        <option value="borrow" <?= $filterAction === 'borrow' ? 'selected' : '' ?>>የውሰት ጥያቄዎች</option>
        <option value="book" <?= $filterAction === 'book' ? 'selected' : '' ?>>የመጽሐፍ ምዝገባና ለውጦች</option>
        <option value="member" <?= $filterAction === 'member' ? 'selected' : '' ?>>የአባላት ምዝገባና ማገድ</option>
        <option value="notification" <?= $filterAction === 'notification' ? 'selected' : '' ?>>ማሳወቂያዎች</option>
        <option value="settings" <?= $filterAction === 'settings' ? 'selected' : '' ?>>የሲስተም ቅንብሮች</option>
      </select>
    </div>
    <div class="col-12 col-md-3 d-flex gap-2">
      <button type="submit" class="btn btn-gold btn-block"><i class="bi bi-filter"></i> አጣራ</button>
      <?php if ($filterAction || $searchUser): ?>
        <a href="activities.php" class="btn btn-outline"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</form>

<!-- Activities Table -->
<div class="card mb-3" style="overflow:hidden;">
  <div class="table-wrap">
    <table class="app-table app-stack">
      <thead>
        <tr>
          <th style="width:25%;">ማን (ተጠቃሚ)</th>
          <th style="width:30%;">ያደረገው ድርጊት</th>
          <th style="width:25%;">ዝርዝር መረጃ</th>
          <th style="width:20%;">መቼ</th>
        </tr>
      </thead>
      <tbody>
        <?php if (mysqli_num_rows($activities) === 0): ?>
          <tr>
            <td colspan="4">
              <div class="empty-state">
                <i class="bi bi-journal-x"></i>
                <h4>ምንም የተመዘገበ ድርጊት አልተገኘም</h4>
                <p class="text-muted">የተመረጠውን ማጣሪያ ይቀይሩ ወይም ዳግም ይሞክሩ።</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php while ($row = mysqli_fetch_assoc($activities)): 
            $info = humanize_action($row['action'], $row['details']);
          ?>
            <tr>
              <td data-label="ማን">
                <?php if (!empty($row['student_id'])): ?>
                  <span class="badge" style="background:#0047AB;color:#FFB703;font-weight:900;font-size:.76rem;padding:2px 7px;border-radius:10px;margin-bottom:3px;display:inline-block;"><?= e($row['student_id']) ?></span><br>
                <?php endif; ?>
                <strong><?= e($row['full_name'] ?: 'የሲስተም ስራ') ?></strong>
                <?php if ($row['role']): ?>
                  <span class="badge badge-navy" style="font-size:.7rem;padding:2px 6px;margin-left:4px;"><?= e($row['role']) ?></span>
                <?php endif; ?>
                <?php if ($row['phone']): ?>
                  <br><span class="text-muted" style="font-size:.76rem;"><i class="bi bi-telephone"></i> <?= e($row['phone']) ?></span>
                <?php endif; ?>
              </td>
              <td data-label="ድርጊት">
                <span class="badge <?= $info['badge'] ?>" style="font-size:.8rem;padding:4px 8px;">
                  <i class="bi <?= $info['icon'] ?>"></i> <?= e($info['text']) ?>
                </span>
              </td>
              <td data-label="ዝርዝር">
                <span class="text-muted" style="font-size:.8rem;word-break:break-all;">
                  <?= e($info['detail'] ?: '—') ?>
                </span>
              </td>
              <td data-label="መቼ">
                <span style="font-size:.82rem;font-weight:600;color:var(--navy);">
                  <i class="bi bi-clock"></i> <?= formatDate($row['created_at']) ?>
                </span>
                <?php if (!empty($row['ip_address'])): ?>
                  <br><span class="text-muted" style="font-size:.7rem;">IP: <?= e($row['ip_address']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination Links -->
<?php if ($totalPages > 1): ?>
  <div class="d-flex justify-content-between align-items-center mt-3">
    <div class="text-muted" style="font-size:.82rem;">
      ገጽ <?= $page ?> ከ <?= $totalPages ?> (ጠቅላላ <?= $totalRecords ?> ውጤቶች)
    </div>
    <div class="d-flex gap-1">
      <?php if ($page > 1): ?>
        <a href="?p=<?= $page - 1 ?>&action_type=<?= urlencode($filterAction) ?>&user_query=<?= urlencode($searchUser) ?>" class="btn btn-outline btn-sm">
          <i class="bi bi-chevron-left"></i> ቀዳሚ
        </a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?p=<?= $page + 1 ?>&action_type=<?= urlencode($filterAction) ?>&user_query=<?= urlencode($searchUser) ?>" class="btn btn-navy btn-sm">
          ቀጣይ <i class="bi bi-chevron-right"></i>
        </a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
