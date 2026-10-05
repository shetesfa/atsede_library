<?php
namespace Tests\Feature;

use Tests\TestCase;
use Tests\FakeTelegram;

require_once __DIR__ . '/../../includes/bot_handler.php';

class TelegramSecurityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        set_setting($this->conn, 'telegram_bot_token', 'test_dummy_token_123');
        set_setting($this->conn, 'telegram_webhook_secret', 'secret_webhook_key_xyz');
    }

    public function testWebhookWrongSecretReturns403(): void {
        global $conn;
        $conn = $this->conn;
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = 'wrong_secret';

        ob_start();
        include __DIR__ . '/../../telegram_bot.php';
        $output = ob_get_clean();

        $this->assertEquals(403, http_response_code());
        $this->assertStringContainsString('Access denied', $output);
    }

    public function testSqlInjectionInChatIdDoesNotLeakOrError(): void {
        $update = [
            'message' => [
                'chat' => ['id' => '1 OR 1=1', 'type' => 'private'],
                'from' => ['id' => 999999],
                'text' => '🔍 መጽሐፍ ፈልግ test'
            ]
        ];

        // Should not throw SQL exception and safely cast chatId to integer
        handle_telegram_update($update, $this->conn, 'test_dummy_token_123');

        $this->assertNotEmpty(FakeTelegram::$requests);
        $lastReq = end(FakeTelegram::$requests);
        $this->assertEquals('sendMessage', $lastReq['method']);
        $this->assertEquals(1, $lastReq['payload']['chat_id']);
    }

    public function testAccountBindingWithWrongAndRightToken(): void {
        $userId = $this->createUser('member', 'active');
        $validToken = generate_telegram_verify_token($this->conn, $userId);

        $chatId = 88812345;
        $fromId = 55567890;

        // 1. Wrong token
        $updateWrong = [
            'message' => [
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId],
                'text' => '/start verify_invalid_token_abc'
            ]
        ];
        handle_telegram_update($updateWrong, $this->conn, 'test_dummy_token_123');

        $stmt = $this->conn->prepare("SELECT telegram_chat_id, telegram_user_id, telegram_joined FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($userRow['telegram_chat_id']);
        $this->assertNull($userRow['telegram_user_id']);

        // 2. Right token
        $updateRight = [
            'message' => [
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId],
                'text' => '/start verify_' . $validToken
            ]
        ];
        handle_telegram_update($updateRight, $this->conn, 'test_dummy_token_123');

        $stmt2 = $this->conn->prepare("SELECT telegram_chat_id, telegram_user_id, telegram_joined FROM users WHERE id = ?");
        $stmt2->bind_param("i", $userId);
        $stmt2->execute();
        $boundUser = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        $this->assertEquals($chatId, $boundUser['telegram_chat_id']);
        $this->assertEquals($fromId, $boundUser['telegram_user_id']);
        $this->assertEquals(1, $boundUser['telegram_joined']);
    }

    public function testPendingOrRejectedUserGetsNoPrivateData(): void {
        $userId = $this->createUser('member', 'pending');
        $member = $this->createMember($userId, 'pending');

        $chatId = 777123;
        $fromId = 777123;

        $stmt = $this->conn->prepare("UPDATE users SET telegram_chat_id = ?, telegram_user_id = ?, telegram_joined = 1 WHERE id = ?");
        $stmt->bind_param("iii", $chatId, $fromId, $userId);
        $stmt->execute();
        $stmt->close();

        FakeTelegram::reset();

        $update = [
            'message' => [
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId],
                'text' => '📚 ያዋስኳቸው መጻሕፍት'
            ]
        ];
        handle_telegram_update($update, $this->conn, 'test_dummy_token_123');

        $lastReq = end(FakeTelegram::$requests);
        $this->assertStringContainsString('አልተረጋገጠም', $lastReq['payload']['text']);
        $this->assertStringNotContainsString('በእጅዎ ያሉ', $lastReq['payload']['text']);
    }

    public function testGroupChatNeverExposesPrivateCommands(): void {
        $userId = $this->createUser('member', 'active');
        $member = $this->createMember($userId, 'active');

        $groupChatId = -100123456789;
        $fromId = 333444;

        $stmt = $this->conn->prepare("UPDATE users SET telegram_chat_id = ?, telegram_user_id = ?, telegram_joined = 1 WHERE id = ?");
        $stmt->bind_param("iii", $fromId, $fromId, $userId);
        $stmt->execute();
        $stmt->close();

        FakeTelegram::reset();

        // Send private command in group chat
        $update = [
            'message' => [
                'chat' => ['id' => $groupChatId, 'type' => 'supergroup'],
                'from' => ['id' => $fromId],
                'text' => '💰 ወርሃዊ ክፍያ'
            ]
        ];
        handle_telegram_update($update, $this->conn, 'test_dummy_token_123');

        $lastReq = end(FakeTelegram::$requests);
        $this->assertStringContainsString('በግል መልዕክት', $lastReq['payload']['text']);
    }
}
