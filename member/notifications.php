<?php
/**
 * member/notifications.php — Atsede Library
 *
 * Member in-app notifications screen:
 *  - Per-user and broadcast read tracking
 *  - Click to mark single notification as read and follow link
 *  - "Mark all as read" button
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('member');

$user   = current_user();
$userId = (int)$user['id'];
$base   = rel_base();

// Handle Mark All As Read
if (isset($_POST['mark_all_read']) || (isset($_GET['action']) && $_GET['action'] === 'mark_all_read')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
    }
    // 1. Mark personal notifications read
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = $userId");
    // 2. Mark broadcast notifications read via notification_reads table
    mysqli_query($conn, "INSERT IGNORE INTO notification_reads (user_id, notification_id) SELECT $userId, id FROM notifications WHERE user_id IS NULL");

    flash('msg', 'ሁሉም ማሳወቂያዎች እንደተነበቡ ተደርገዋል።', 'success');
    redirect('notifications.php');
}

// Handle Single Notification Click / Mark Read
if (isset($_GET['read_id'])) {
    $readId = (int)$_GET['read_id'];
    $stmt = mysqli_prepare($conn, "SELECT * FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
    mysqli_stmt_bind_param($stmt, 'ii', $readId, $userId);
    mysqli_stmt_execute($stmt);
    $notifRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($notifRow) {
        if ($notifRow['user_id'] !== null) {
            mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = $readId");
        } else {
            mysqli_query($conn, "INSERT IGNORE INTO notification_reads (user_id, notification_id) VALUES ($userId, $readId)");
        }

        if (!empty($notifRow['link'])) {
            $dest = $notifRow['link'];
            $destUrl = (strpos($dest, 'http') === 0) ? $dest : $base . ltrim($dest, '/');
            redirect($destUrl);
        }
    }
    redirect('notifications.php');
}

// Fetch notifications with individual read status
$notifs = mysqli_query($conn, "
    SELECT n.*, 
           CASE 
               WHEN n.user_id IS NOT NULL THEN n.is_read
               WHEN nr.id IS NOT NULL THEN 1
               ELSE 0
           END AS computed_is_read
    FROM notifications n
    LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = $userId
    WHERE n.user_id = $userId OR n.user_id IS NULL 
    ORDER BY n.created_at DESC LIMIT 50
");

$unreadCount = unread_count($conn, $userId);

$icons = [
    'borrow_approved'       => 'bi-check-circle',
    'borrow_rejected'       => 'bi-x-circle',
    'new_book'              => 'bi-book',
    'registration_approved' => 'bi-person-check',
    'suggestion_approved'   => 'bi-lightbulb',
    'due_reminder'          => 'bi-alarm',
    'overdue'               => 'bi-exclamation-triangle',
    'payment_reminder'      => 'bi-cash-coin',
    'book_available'        => 'bi-bell',
    'general'               => 'bi-bell'
];

$colors = [
    'borrow_approved'       => 'var(--success)',
    'borrow_rejected'       => 'var(--danger)',
    'new_book'              => 'var(--gold-600)',
    'registration_approved' => 'var(--success)',
    'suggestion_approved'   => 'var(--gold-600)',
    'due_reminder'          => 'var(--warning)',
    'overdue'               => 'var(--danger)',
    'payment_reminder'      => 'var(--gold-600)',
    'book_available'        => 'var(--primary)',
    'general'               => 'var(--navy)'
];

$pageTitle = __('notifications');
$activeKey = 'notifications';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px;">
    <div class="section-title" style="margin:0;"><?= __('notifications') ?></div>
    <?php if ($unreadCount > 0): ?>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <button type="submit" name="mark_all_read" value="1" class="btn btn-outline btn-sm">
                <i class="bi bi-check2-all"></i> ሁሉንም አንብቤያለሁ
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if (mysqli_num_rows($notifs) === 0): ?>
    <div class="empty-state">
        <i class="bi bi-bell-slash"></i>
        <h4>እስካሁን ማሳወቂያ የለም</h4>
        <p>አዲስ ማሳወቂያ ሲኖር እዚህ ያገኙታል።</p>
    </div>
<?php else: ?>
    <?php while ($n = mysqli_fetch_assoc($notifs)): 
        $isRead = (int)$n['computed_is_read'] === 1;
        $clickHref = 'notifications.php?read_id=' . (int)$n['id'];
    ?>
        <a href="<?= e($clickHref) ?>" 
           class="card card-pad mb-2" 
           style="display:flex;gap:12px;text-decoration:none;border-left:<?= $isRead ? '3px solid transparent' : '4px solid var(--primary)' ?>;background:<?= $isRead ? 'inherit' : 'var(--slate-50, #f8fafc)' ?>;">
            <i class="bi <?= e($icons[$n['type']] ?? 'bi-bell') ?>" style="font-size:1.25rem;color:<?= $colors[$n['type']] ?? 'var(--navy)' ?>;margin-top:2px;"></i>
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                    <div style="font-weight:<?= $isRead ? '600' : '700' ?>;color:var(--navy);font-size:.9rem;">
                        <?= e($n['title']) ?>
                    </div>
                    <?php if (!$isRead): ?>
                        <span class="badge badge-primary" style="font-size:0.68rem;padding:2px 6px;">አዲስ</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:.82rem;margin-top:2px;"><?= e($n['message']) ?></div>
                <div class="text-muted" style="font-size:.7rem;margin-top:4px;"><?= formatDate(substr($n['created_at'], 0, 10)) ?></div>
            </div>
        </a>
    <?php endwhile; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
