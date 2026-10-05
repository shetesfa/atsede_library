<?php
/**
 * cron/daily_reminders.php  —  Atsede Library
 *
 * Run daily at 08:00 (server time) via Windows Task Scheduler or cPanel cron:
 *   php C:\xampp\htdocs\atsede_library\cron\daily_reminders.php
 * Or via secured HTTP GET request:
 *   https://example.com/cron/daily_reminders.php?token=CRON_TOKEN
 *
 * What it does:
 *  1. Recalculates overdue fines (5 ብር/ቀን)
 *  2. Sends due-tomorrow reminders (with reminder_log deduplication)
 *  3. Sends overdue alerts (with reminder_log deduplication)
 *  4. Sends monthly payment reminder on 1st of Ethiopian month
 *  5. Notifies librarians of total overdue stats
 */

define('RUNNING_CRON', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifier.php';

// Allow CLI or valid CRON_TOKEN query parameter only
if (PHP_SAPI !== 'cli') {
    $cronToken = get_setting($conn, 'cron_token', 'atsede_cron_sec_89d3fa6b');
    $providedToken = $_GET['token'] ?? '';
    if (empty($providedToken) || !hash_equals($cronToken, $providedToken)) {
        http_response_code(403);
        exit("Access denied. Run from CLI or provide valid ?token=CRON_TOKEN.\n");
    }
}

$today     = date('Y-m-d');
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$thisMonth = current_billing_month();

echo "[" . date('Y-m-d H:i:s') . "] Cron started.\n";

// =====================================================================
// STEP 1: Recalculate all overdue fines
// =====================================================================
recalculate_all_fines($conn);
echo "[OK] Fines recalculated.\n";

// =====================================================================
// STEP 2: Due-tomorrow reminders
// =====================================================================
$resDue = mysqli_query($conn,
    "SELECT br.id, br.due_date, br.book_id, b.title,
            u.id AS user_id, u.full_name, u.telegram_chat_id, u.telegram_joined,
            m.id AS member_id
     FROM borrow_records br
     JOIN members m ON m.id = br.member_id
     JOIN users u ON u.id = m.user_id
     JOIN books b ON b.id = br.book_id
     WHERE br.status = 'borrowed'
       AND br.due_date = '$tomorrow'");

$dueSent = 0;
while ($row = mysqli_fetch_assoc($resDue)) {
    $memberId = (int)$row['member_id'];
    if (!should_send_reminder($conn, $memberId, 'due_tomorrow', $today)) {
        continue;
    }

    $cleanTitle = $row['title'] ?? '';
    $cleanDate  = formatDate($row['due_date']);
    $msgText = '"' . $cleanTitle . '" — የመመለሻ ቀን ነገ (' . $cleanDate . ') ነው። እባክዎ ነገ ለቤተ-መጻሕፍቱ ያምጡ። ካልተመለሰ ቅጣት ይጀምራል (5 ብር/ቀን)።';

    try {
        notify_user(
            $conn,
            (int)$row['user_id'],
            'ነገ ይመለሳል',
            $msgText,
            'due_reminder',
            'member/my_books.php',
            ['inapp', 'telegram', 'push']
        );
        $dueSent++;
    } catch (Throwable $e) {
        // Individual notification failure must not crash cron
        error_log("Cron due reminder failed for user {$row['user_id']}: " . $e->getMessage());
    }
    usleep(50000); // 50ms
}
echo "[OK] Due-tomorrow reminders sent: $dueSent\n";

// =====================================================================
// STEP 3: Overdue alerts (books already late)
// =====================================================================
$resOverdue = mysqli_query($conn,
    "SELECT br.id, br.due_date, br.overdue_fine, br.fine_paid, br.fine_waived,
            b.title,
            u.id AS user_id, u.full_name, u.telegram_chat_id, u.telegram_joined,
            m.id AS member_id
     FROM borrow_records br
     JOIN members m ON m.id = br.member_id
     JOIN users u ON u.id = m.user_id
     JOIN books b ON b.id = br.book_id
     WHERE br.status = 'borrowed'
       AND br.due_date < '$today'");

$overdueSent = 0;
while ($row = mysqli_fetch_assoc($resOverdue)) {
    $memberId = (int)$row['member_id'];
    if (!should_send_reminder($conn, $memberId, 'overdue', $today)) {
        continue;
    }

    $daysLate   = (int)(new DateTime($today))->diff(new DateTime($row['due_date']))->days;
    $netFine    = max(0.00, (float)$row['overdue_fine'] - (float)$row['fine_paid'] - (float)$row['fine_waived']);
    $cleanTitle = $row['title'] ?? '';
    $cleanDate  = formatDate($row['due_date']);

    $msgText = '📖 "' . $cleanTitle . '" የመመለሻ ቀን (' . $cleanDate . ') አልፏል (' . $daysLate . ' ቀን ዘግይቷል)።';
    if ($netFine > 0) {
        $msgText .= ' 💸 ቅጣት፦ ' . number_format($netFine, 2) . ' ብር።';
    }
    $msgText .= ' እባክዎ ዛሬውኑ ቤተ-መጻሕፍቱ ያምጡ። ቅጣቱ ቀን በቀን ይጨምራል።';

    try {
        notify_user(
            $conn,
            (int)$row['user_id'],
            'አሳሳቢ — መጽሐፉ ዘግይቷል!',
            $msgText,
            'overdue',
            'member/my_books.php',
            ['inapp', 'telegram', 'push']
        );
        $overdueSent++;
    } catch (Throwable $e) {
        error_log("Cron overdue alert failed for user {$row['user_id']}: " . $e->getMessage());
    }
    usleep(50000);
}
echo "[OK] Overdue alerts sent: $overdueSent\n";

// =====================================================================
// STEP 4: Monthly payment reminder (1st of each Ethiopian month)
// =====================================================================
$ethToday = gregorianToEthParts($today);
$isFirstEthDay = ($ethToday !== null && (int)$ethToday['day'] === 1);

if ($isFirstEthDay) {
    $monthLabel        = format_billing_month_amharic($thisMonth);
    $minPaymentVal     = (float)get_minimum_monthly_payment($conn);
    $minPaymentDisplay = number_format($minPaymentVal, 2);
    $thisMonthSafe     = mysqli_real_escape_string($conn, $thisMonth);

    $resUnpaid = mysqli_query($conn,
        "SELECT u.id AS user_id, u.full_name, u.telegram_chat_id, u.telegram_joined,
                m.id AS member_id
         FROM members m
         JOIN users u ON u.id = m.user_id
         WHERE u.status = 'active'
           AND m.id NOT IN (
               SELECT member_id FROM membership_payments
               WHERE payment_month = '$thisMonthSafe'
               AND amount >= $minPaymentVal
           )");

    $payReminderSent = 0;
    while ($row = mysqli_fetch_assoc($resUnpaid)) {
        $memberId = (int)$row['member_id'];
        if (!should_send_reminder($conn, $memberId, 'monthly_payment', $today)) {
            continue;
        }

        $payMsg = "💰 ወርሃዊ ክፍያ ማስታወሻ (" . $monthLabel . ")\nዝቅተኛ ክፍያ፦ " . $minPaymentDisplay . " ብር። እባክዎ ክፍያዎን ቤተ-መጻሕፍቱ ሄደው ያስፈጽሙ።";

        try {
            notify_user(
                $conn,
                (int)$row['user_id'],
                'ወርሃዊ ክፍያ ማስታወሻ',
                $payMsg,
                'payment_reminder',
                'member/payments.php',
                ['inapp', 'telegram', 'push']
            );
            $payReminderSent++;
        } catch (Throwable $e) {
            error_log("Cron payment reminder failed for user {$row['user_id']}: " . $e->getMessage());
        }
        usleep(50000);
    }
    echo "[OK] Monthly payment reminders sent: $payReminderSent\n";
}

// =====================================================================
// STEP 5: Notify librarians/admin of overdue stats
// =====================================================================
$statsRow = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(overdue_fine - fine_paid - fine_waived),0) AS total_fine
     FROM borrow_records WHERE status='borrowed' AND due_date < '$today'"));

$overdueCount = (int)($statsRow['cnt']        ?? 0);
$totalFine    = (float)($statsRow['total_fine'] ?? 0);

if ($overdueCount > 0) {
    $resStaff = mysqli_query($conn,
        "SELECT id, telegram_chat_id FROM users
         WHERE role IN ('librarian','admin')
           AND telegram_joined=1 AND telegram_chat_id IS NOT NULL");
    while ($staff = mysqli_fetch_assoc($resStaff)) {
        $staffMsg = "📊 <b>ዕለታዊ ሪፖርት — $today</b>\n\n" .
                    "⚠️ ዘግይቷል: <b>$overdueCount</b> ውሰቶች\n" .
                    "💸 ጠቅላላ ቅጣት: <b>" . number_format($totalFine, 2) . " ብር</b>\n\n" .
                    "ዝርዝር ለማየት ዳሽቦርድ ይክፈቱ።";
        try {
            telegram_send($conn, $staff['telegram_chat_id'], $staffMsg);
        } catch (Throwable $e) {
            // Non-fatal
        }
        usleep(50000);
    }
}

echo "[OK] Cron finished at " . date('Y-m-d H:i:s') . "\n";
