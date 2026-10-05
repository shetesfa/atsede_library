<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Tests\FakeTelegram;
use function notify_user;
use function unread_count;

class NotificationDeliveryTest extends TestCase
{
    public function testNotifyUserAcrossChannels(): void
    {
        $conn = $this->conn;
        $userId = $this->createUser('member');

        $res = notify_user($conn, $userId, 'ሙከራ ማሳወቂያ', 'የማሳወቂያ መልእክት ሙከራ', 'general', 'member/my_books.php', ['inapp', 'push', 'telegram']);

        $this->assertNotEmpty($res['notification_id']);
        $notifId = (int)$res['notification_id'];

        // Verify in-app delivery recorded
        $stmtDel = mysqli_query($conn, "SELECT channel, status FROM notification_deliveries WHERE notification_id = $notifId");
        $deliveries = [];
        while ($d = mysqli_fetch_assoc($stmtDel)) {
            $deliveries[$d['channel']] = $d['status'];
        }

        $this->assertArrayHasKey('inapp', $deliveries);
        $this->assertSame('sent', $deliveries['inapp']);

        $this->assertArrayHasKey('push', $deliveries);
        $this->assertSame('sent', $deliveries['push']);

        $this->assertArrayHasKey('telegram', $deliveries);
        // User hasn't joined telegram yet, so delivery records 'failed' without crashing
        $this->assertSame('failed', $deliveries['telegram']);
    }

    public function testCronRunsTwiceSecondIsNoOp(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];
        $book = $this->createBookWithCopies(1);
        $copyId = (int)$book['copies'][0]['id'];
        $bookId = (int)$book['id'];
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $today = date('Y-m-d');

        // Create borrowed record due tomorrow
        mysqli_query($conn, "INSERT INTO borrow_records (member_id, book_copy_id, book_id, borrowed_at, due_date, status)
            VALUES ($memberId, $copyId, $bookId, NOW(), '$tomorrow', 'borrowed')");

        // First run of should_send_reminder
        $shouldSendFirst = should_send_reminder($conn, $memberId, 'due_tomorrow', $today);
        $this->assertTrue($shouldSendFirst, 'First check of the day should allow sending reminder');

        // Second run on the same day must be rejected by reminder_log deduplication
        $shouldSendSecond = should_send_reminder($conn, $memberId, 'due_tomorrow', $today);
        $this->assertFalse($shouldSendSecond, 'Second run of the day must be rejected (no-op duplicate)');
    }

    public function testFailedTelegramDoesNotKillNotificationOrProcess(): void
    {
        $conn = $this->conn;
        $userId = $this->createUser('member');

        // Link telegram with dummy chat id
        mysqli_query($conn, "UPDATE users SET telegram_chat_id = '99999999', telegram_joined = 1 WHERE id = $userId");

        // Simulate Telegram API network failure or block
        FakeTelegram::$customResponse = ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'];

        // notify_user must not throw or crash
        $res = notify_user($conn, $userId, 'አሳሳቢ', 'ቴሌግራም ቢቋረጥም ሂደቱ ይቀጥላል', 'general', null, ['inapp', 'telegram']);

        $this->assertNotEmpty($res['notification_id']);
        $notifId = (int)$res['notification_id'];

        $del = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status, error_message FROM notification_deliveries WHERE notification_id = $notifId AND channel = 'telegram'"));
        $this->assertSame('failed', $del['status']);

        // In-app should still be sent successfully
        $inapp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM notification_deliveries WHERE notification_id = $notifId AND channel = 'inapp'"));
        $this->assertSame('sent', $inapp['status']);
    }

    public function testMarkAllAsReadAndSingleClickRead(): void
    {
        $conn = $this->conn;
        $userId = $this->createUser('member');

        // 1. Create one personal notification and one broadcast notification
        mysqli_query($conn, "INSERT INTO notifications (user_id, title, message, is_read) VALUES ($userId, 'የግል ማሳወቂያ', 'የግል መልዕክት', 0)");
        $personalId = mysqli_insert_id($conn);

        mysqli_query($conn, "INSERT INTO notifications (user_id, title, message, is_read) VALUES (NULL, 'አጠቃላይ ማሳወቂያ', 'የጋራ መልዕክት', 0)");
        $broadcastId = mysqli_insert_id($conn);

        // Initially both are unread
        $countInitial = unread_count($conn, $userId);
        $this->assertSame(2, $countInitial);

        // 2. Mark personal notification read (simulate clicking single personal notification)
        mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE id = $personalId");
        $countAfterPersonal = unread_count($conn, $userId);
        $this->assertSame(1, $countAfterPersonal);

        // 3. Mark broadcast notification read via notification_reads (simulate clicking single broadcast)
        mysqli_query($conn, "INSERT IGNORE INTO notification_reads (user_id, notification_id) VALUES ($userId, $broadcastId)");
        $countAfterBoth = unread_count($conn, $userId);
        $this->assertSame(0, $countAfterBoth);
    }
}
