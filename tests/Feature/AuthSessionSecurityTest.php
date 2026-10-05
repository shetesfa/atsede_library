<?php
namespace Tests\Feature;

use Tests\TestCase;

class AuthSessionSecurityTest extends TestCase
{
    public function testLoginThrottlingTenMistakesTwentyMinutesBlock()
    {
        $testUsername = 'throttle_user_' . bin2hex(random_bytes(3));
        $testIp = '192.168.1.55';

        // 1. Initial state: not blocked
        $status = check_login_throttle($this->conn, $testUsername, $testIp);
        $this->assertFalse($status['blocked']);

        // 2. Perform 9 failed attempts -> still not blocked
        for ($i = 1; $i <= 9; $i++) {
            record_login_attempt($this->conn, $testUsername, $testIp);
        }
        $status9 = check_login_throttle($this->conn, $testUsername, $testIp);
        $this->assertFalse($status9['blocked']);
        $this->assertEquals(9, $status9['attempts']);

        // 3. 10th failed attempt -> MUST BE BLOCKED for 20 minutes (close 🔐)
        record_login_attempt($this->conn, $testUsername, $testIp);
        $status10 = check_login_throttle($this->conn, $testUsername, $testIp);
        $this->assertTrue($status10['blocked'], 'Must be blocked after 10 failed attempts.');
        $this->assertGreaterThan(0, $status10['retry_after']);
        $this->assertLessThanOrEqual(1200, $status10['retry_after']);
        $this->assertStringContainsString('ለ 20 ደቂቃ ተቆልፏል', $status10['message']);

        // 4. Successful login clears throttle
        clear_login_attempts($this->conn, $testUsername, $testIp);
        $statusCleared = check_login_throttle($this->conn, $testUsername, $testIp);
        $this->assertFalse($statusCleared['blocked']);
    }

    public function testRegistrationRateLimitingByIp()
    {
        $testIp = '10.0.0.88';

        // 4 attempts -> not blocked
        for ($i = 1; $i <= 4; $i++) {
            record_register_attempt($this->conn, $testIp);
        }
        $status4 = check_register_throttle($this->conn, $testIp);
        $this->assertFalse($status4['blocked']);

        // 5th attempt -> throttled
        record_register_attempt($this->conn, $testIp);
        $status5 = check_register_throttle($this->conn, $testIp);
        $this->assertTrue($status5['blocked']);
        $this->assertStringContainsString('ከተፈቀደው በላይ', $status5['message']);
    }

    public function testRequireLoginEvictsSuspendedOrBlockedUsers()
    {
        global $conn;
        $conn = $this->conn;

        $user = $this->create_user('member', 'active');
        $this->login_as('member', $user['id']);

        // User is initially valid
        $this->assertEquals('active', $_SESSION['user']['status']);

        // Suspend user in DB
        mysqli_query($this->conn, "UPDATE users SET status = 'suspended' WHERE id = " . (int)$user['id']);

        // require_login() must detect status change, destroy session, and redirect
        require_login();

        $this->assertEmpty($_SESSION, 'Session must be emptied when user status is suspended.');
    }

    public function testMemberInfoRestrictedToStaffAndSelfViaCardToken()
    {
        global $conn;
        $conn = $this->conn;

        // Create Member A and Member B
        $userA = $this->create_user('member', 'active');
        $memA = $this->createMember($userA['id']);
        $tokenA = $memA['card_token'];

        $userB = $this->create_user('member', 'active');
        $memB = $this->createMember($userB['id']);
        $tokenB = $memB['card_token'];

        $librarianUser = $this->create_user('librarian', 'active');

        // Helper to invoke member_info.php
        $callMemberInfo = function ($token) {
            global $conn;
            $conn = $this->conn;
            http_response_code(200);

            $_GET['token'] = $token;
            ob_start();
            try {
                include __DIR__ . '/../../ajax/member_info.php';
            } finally {
                $out = ob_get_clean();
                unset($_GET['token']);
            }
            return ['code' => http_response_code(), 'body' => $out];
        };

        // 1. Member B attempts to view Member A's card -> 403 Forbidden
        $this->login_as('member', $userB['id']);
        $resDenied = $callMemberInfo($tokenA);
        $this->assertEquals(403, $resDenied['code'], 'Member B must get 403 when accessing Member A card.');
        $this->assertStringContainsString('ፍቃድ የለዎትም', $resDenied['body']);

        // 2. Member A views their OWN card -> 200 OK
        $this->login_as('member', $userA['id']);
        $resOwner = $callMemberInfo($tokenA);
        $this->assertEquals(200, $resOwner['code']);
        $this->assertStringContainsString('ይፋዊ የዲጂታል አባልነት ማረጋገጫ', $resOwner['body']);

        // 3. Librarian views Member A's card -> 200 OK
        $this->login_as('librarian', $librarianUser['id']);
        $resStaff = $callMemberInfo($tokenA);
        $this->assertEquals(200, $resStaff['code']);
        $this->assertStringContainsString('ይፋዊ የዲጂታል አባልነት ማረጋገጫ', $resStaff['body']);

        // 4. Invalid token -> 404 Not Found
        $resNotFound = $callMemberInfo('invalid_token_' . uniqid());
        $this->assertEquals(404, $resNotFound['code']);
        $this->assertStringContainsString('አባሉ አልተገኘም', $resNotFound['body']);
    }

    public function testToastXssSanitization()
    {
        $appJs = file_get_contents(__DIR__ . '/../../assets/js/app.js');
        // Ensure toast message uses textContent instead of innerHTML interpolation
        $this->assertStringContainsString('span.textContent = message', $appJs, 'Toast must assign message via textContent to prevent XSS.');
        $this->assertStringNotContainsString('<span>${message}</span>', $appJs, 'Toast must not interpolate unescaped message into innerHTML.');
    }
}
