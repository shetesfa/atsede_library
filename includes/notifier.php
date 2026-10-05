<?php
/**
 * includes/notifier.php — Atsede Library
 *
 * Reliable multi-channel notification dispatcher with delivery audit tracking.
 * Channels supported: 'inapp', 'push', 'telegram'
 */

require_once __DIR__ . '/functions.php';

/**
 * Checks and records reminder deduplication in reminder_log.
 * Returns true if reminder has NOT been sent yet today (safe to send).
 */
function should_send_reminder($conn, int $memberId, string $type, string $date): bool {
    $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO reminder_log (member_id, reminder_type, reminder_date) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iss', $memberId, $type, $date);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return ($affected > 0);
}

/**
 * Dispatch a notification to a specific user across multiple channels.
 *
 * @param mysqli $conn
 * @param int|null $userId
 * @param string $title
 * @param string $message
 * @param string $type
 * @param string|null $link
 * @param array $channels Array of 'inapp', 'push', 'telegram'
 * @return array Results per channel with delivery status
 */
function notify_user(
    $conn,
    ?int $userId,
    string $title,
    string $message,
    string $type = 'general',
    ?string $link = null,
    array $channels = ['inapp', 'push', 'telegram']
): array {
    $results = [
        'notification_id' => null,
        'deliveries'      => []
    ];

    if (!$conn) {
        return $results;
    }

    $title   = trim($title);
    $message = trim($message);
    $type    = trim($type);

    // 1. In-App Notification (Always creates DB row if inapp requested)
    $notifId = null;
    if (in_array('inapp', $channels, true)) {
        try {
            $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'issss', $userId, $title, $message, $type, $link);
            $executed = mysqli_stmt_execute($stmt);
            $notifId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if ($executed && $notifId) {
                $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, sent_at) VALUES (?, 'inapp', 'sent', NOW())");
                mysqli_stmt_bind_param($stmtDel, 'i', $notifId);
                mysqli_stmt_execute($stmtDel);
                mysqli_stmt_close($stmtDel);

                $results['deliveries']['inapp'] = ['status' => 'sent'];
            } else {
                $results['deliveries']['inapp'] = ['status' => 'failed', 'error' => mysqli_error($conn)];
            }
        } catch (Throwable $e) {
            $results['deliveries']['inapp'] = ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    $results['notification_id'] = $notifId;

    // If no DB notification ID was created, create a placeholder delivery record parent
    if (!$notifId) {
        $stmtTmp = mysqli_prepare($conn, "INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmtTmp, 'issss', $userId, $title, $message, $type, $link);
        mysqli_stmt_execute($stmtTmp);
        $notifId = mysqli_insert_id($conn);
        mysqli_stmt_close($stmtTmp);
        $results['notification_id'] = $notifId;
    }

    // 2. Web Push Channel
    if (in_array('push', $channels, true)) {
        try {
            send_push_to_user($conn, $userId, $title, $message, $link);
            $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, sent_at) VALUES (?, 'push', 'sent', NOW())");
            mysqli_stmt_bind_param($stmtDel, 'i', $notifId);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
            $results['deliveries']['push'] = ['status' => 'sent'];
        } catch (Throwable $e) {
            $err = $e->getMessage();
            $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, error_message, sent_at) VALUES (?, 'push', 'failed', ?, NOW())");
            mysqli_stmt_bind_param($stmtDel, 'is', $notifId, $err);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
            $results['deliveries']['push'] = ['status' => 'failed', 'error' => $err];
        }
    }

    // 3. Telegram Channel
    if (in_array('telegram', $channels, true) && $userId) {
        try {
            $uRes = mysqli_query($conn, "SELECT telegram_chat_id, telegram_joined FROM users WHERE id = " . (int)$userId);
            $uRow = mysqli_fetch_assoc($uRes);

            if ($uRow && !empty($uRow['telegram_joined']) && !empty($uRow['telegram_chat_id'])) {
                $chatId = $uRow['telegram_chat_id'];
                $cleanTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cleanMsg   = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $tgText = "🔔 <b>" . $cleanTitle . "</b>\n\n" . $cleanMsg;
                if ($link) {
                    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '';
                    $tgText .= "\n\n🔗 <a href=\"" . htmlspecialchars($base . ltrim($link, '/')) . "\">ተጨማሪ ይመልከቱ</a>";
                }

                $sent = telegram_send($conn, $chatId, $tgText);
                if ($sent) {
                    $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, sent_at) VALUES (?, 'telegram', 'sent', NOW())");
                    mysqli_stmt_bind_param($stmtDel, 'i', $notifId);
                    mysqli_stmt_execute($stmtDel);
                    mysqli_stmt_close($stmtDel);
                    $results['deliveries']['telegram'] = ['status' => 'sent'];
                } else {
                    $err = 'ቴሌግራም መልእክት መላክ አልተሳካም (Telegram API error)';
                    $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, error_message, sent_at) VALUES (?, 'telegram', 'failed', ?, NOW())");
                    mysqli_stmt_bind_param($stmtDel, 'is', $notifId, $err);
                    mysqli_stmt_execute($stmtDel);
                    mysqli_stmt_close($stmtDel);
                    $results['deliveries']['telegram'] = ['status' => 'failed', 'error' => $err];
                }
            } else {
                $err = 'የቴሌግራም አካውንት አልተገናኘም';
                $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, error_message, sent_at) VALUES (?, 'telegram', 'failed', ?, NOW())");
                mysqli_stmt_bind_param($stmtDel, 'is', $notifId, $err);
                mysqli_stmt_execute($stmtDel);
                mysqli_stmt_close($stmtDel);
                $results['deliveries']['telegram'] = ['status' => 'failed', 'error' => $err];
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
            $stmtDel = mysqli_prepare($conn, "INSERT INTO notification_deliveries (notification_id, channel, status, error_message, sent_at) VALUES (?, 'telegram', 'failed', ?, NOW())");
            mysqli_stmt_bind_param($stmtDel, 'is', $notifId, $err);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
            $results['deliveries']['telegram'] = ['status' => 'failed', 'error' => $err];
        }
    }

    return $results;
}
