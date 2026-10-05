<?php
/**
 * cron/daily_reminders.php  —  Atsede Library
 *
 * Run daily at 08:00 (server time) via Windows Task Scheduler or cPanel cron:
 *   php C:\xampp\htdocs\atsede_library\cron\daily_reminders.php
 *
 * What it does:
 *  1. Recalculates overdue fines (5 ብር/ቀን)
 *  2. Sends Telegram reminder to members whose books are due tomorrow
 *  3. Sends Telegram overdue alert to members who are late (with fine)
 *  4. Sends monthly payment reminder on 1st of each month
 *  5. Notifies librarians of total overdue count
 */

// Allow CLI or localhost only
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit("Access denied. Run from CLI or localhost only.\n");
    }
}

define('RUNNING_CRON', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

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
            u.id AS user_id, u.full_name, u.telegram_chat_id, u.telegram_joined
     FROM borrow_records br
     JOIN members m ON m.id = br.member_id
     JOIN users u ON u.id = m.user_id
     JOIN books b ON b.id = br.book_id
     WHERE br.status = 'borrowed'
       AND br.due_date = '$tomorrow'
       AND u.telegram_joined = 1
       AND u.telegram_chat_id IS NOT NULL");

$dueSent = 0;
while ($row = mysqli_fetch_assoc($resDue)) {
    $cleanTitle = htmlspecialchars($row['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cleanDate  = htmlspecialchars(formatDate($row['due_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $msg = "⏰ <b>ማስታወሻ — ነገ ይመለሳል!</b>\n\n" .
           "📖 <b>" . $cleanTitle . "</b>\n" .
           "📅 የመመለሻ ቀን: " . $cleanDate . "\n\n" .
           "❗ ነገ ቤተ-መጻሕፍቱ ያምጡ። ካልተመለሰ ቅጣት ይጀምራል (5 ብር/ቀን)።";
    if (telegram_send($conn, $row['telegram_chat_id'], $msg)) {
        $dueSent++;
        notify($conn, $row['user_id'], 'ነገ ይመለሳል', ($row['title'] ?? '') . ' — ነገ ለቤተ-መጻሕፍቱ ይምጡ።', 'due_reminder', 'member/my_books.php');
    }
    usleep(100000); // 100ms
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
       AND br.due_date < '$today'
       AND u.telegram_joined = 1
       AND u.telegram_chat_id IS NOT NULL");

$overdueSent = 0;
while ($row = mysqli_fetch_assoc($resOverdue)) {
    $daysLate   = (int)(new DateTime($today))->diff(new DateTime($row['due_date']))->days;
    $netFine    = max(0, (float)$row['overdue_fine'] - (float)$row['fine_paid'] - (float)$row['fine_waived']);
    $cleanTitle = htmlspecialchars($row['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cleanDate  = htmlspecialchars(formatDate($row['due_date']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $msg = "🚨 <b>አሳሳቢ — መጽሐፉ ዘግይቷል!</b>\n\n" .
           "📖 <b>" . $cleanTitle . "</b>\n" .
           "📅 ይጠናቀቅ ነበረ: " . $cleanDate . "\n" .
           "⏳ ዘግይቷል: <b>$daysLate ቀን</b>\n";
    if ($netFine > 0) {
        $msg .= "💸 ቅጣት: <b>" . number_format($netFine, 2) . " ብር</b>\n";
    }
    $msg .= "\nእባክዎ ዛሬ ቤተ-መጻሕፍቱ ያምጡ። ቅጣቱ ቀን ቀን ይጨምራል።";

    if (telegram_send($conn, $row['telegram_chat_id'], $msg)) {
        $overdueSent++;
    }
    usleep(100000);
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
           AND u.telegram_joined = 1
           AND u.telegram_chat_id IS NOT NULL
           AND m.id NOT IN (
               SELECT member_id FROM membership_payments
               WHERE payment_month = '$thisMonthSafe'
               AND amount >= $minPaymentVal
           )");

    $payReminderSent = 0;
    while ($row = mysqli_fetch_assoc($resUnpaid)) {
        $msg = "💰 <b>ወርሃዊ ክፍያ ማስታወሻ</b>\n\n" .
               "📅 ወር: <b>$monthLabel</b>\n" .
               "📋 ዝቅተኛ ክፍያ: <b>$minPaymentDisplay ብር</b>\n\n" .
               "❗ ክፍያዎን ቤተ-መጻሕፍቱ ሄደው ያስፈጽሙ።\n" .
               "ካልተከፈለ ለዚህ ወር መጽሐፍ ማዋስ አይፈቀድም።";
        if (telegram_send($conn, $row['telegram_chat_id'], $msg)) {
            $payReminderSent++;
        }
        usleep(100000);
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
    // Send to all librarians and admins who have Telegram
    $resStaff = mysqli_query($conn,
        "SELECT telegram_chat_id FROM users
         WHERE role IN ('librarian','admin')
           AND telegram_joined=1 AND telegram_chat_id IS NOT NULL");
    while ($staff = mysqli_fetch_assoc($resStaff)) {
        $staffMsg = "📊 <b>ዕለታዊ ሪፖርት — $today</b>\n\n" .
                    "⚠️ ዘግይቷል: <b>$overdueCount</b> ውሰቶች\n" .
                    "💸 ጠቅላላ ቅጣት: <b>" . number_format($totalFine, 2) . " ብር</b>\n\n" .
                    "ዝርዝር ለማየት ዳሽቦርድ ይክፈቱ።";
        telegram_send($conn, $staff['telegram_chat_id'], $staffMsg);
        usleep(100000);
    }
}

echo "[OK] Cron finished at " . date('Y-m-d H:i:s') . "\n";
