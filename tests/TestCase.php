<?php
namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use mysqli;

class FakeTelegram {
    public static array $requests = [];
    public static ?array $customResponse = null;

    public static function reset(): void {
        self::$requests = [];
        self::$customResponse = null;
    }

    public static function record(string $method, array $payload): array {
        self::$requests[] = ['method' => $method, 'payload' => $payload];
        if (self::$customResponse !== null) {
            return self::$customResponse;
        }
        return ['ok' => true, 'result' => ['message_id' => rand(100, 9999)]];
    }
}

abstract class TestCase extends BaseTestCase {
    protected mysqli $conn;

    protected function setUp(): void {
        parent::setUp();
        global $conn;
        if (!$conn || !($conn instanceof mysqli) || $conn->connect_errno) {
            $conn = new mysqli('localhost', 'root', '', 'atsede_test');
            $conn->set_charset("utf8mb4");
        }
        $this->conn = $conn;
        // Start transaction for test isolation
        $this->conn->begin_transaction();
        FakeTelegram::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void {
        // Rollback transaction to keep test database clean
        $this->conn->rollback();
        FakeTelegram::reset();
        $_SESSION = [];
        parent::tearDown();
    }

    protected function createUser(string $role = 'member', string $status = 'active'): int {
        $username = 'testuser_' . bin2hex(random_bytes(4));
        $phone = '09' . rand(10000000, 99999999);
        $password = password_hash('TestPass123!', PASSWORD_DEFAULT);
        $fullName = 'ሙከራ ተጠቃሚ ' . rand(10, 99);

        $stmt = $this->conn->prepare("INSERT INTO users (full_name, phone, username, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $fullName, $phone, $username, $password, $role, $status);
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();

        return $userId;
    }

    protected function createMember(?int $userId = null, string $status = 'active'): array {
        if (!$userId) {
            $userId = $this->createUser('member', $status);
        }
        $class = 'ክፍል ' . rand(9, 12);
        $studentId = 'ST-' . rand(1000, 9999);
        $maxLimit = 3;

        $cardToken = bin2hex(random_bytes(16));
        $stmt = $this->conn->prepare("INSERT INTO members (user_id, class, student_id, max_borrow_limit, card_token) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $userId, $class, $studentId, $maxLimit, $cardToken);
        $stmt->execute();
        $memberId = $stmt->insert_id;
        $stmt->close();

        // Also add a payment for current month so member is considered paid
        $currentMonth = function_exists('current_billing_month') ? current_billing_month() : date('Y-m');
        $amount = 50.00;
        $stmtPay = $this->conn->prepare("INSERT INTO membership_payments (member_id, payment_month, amount, sync_status) VALUES (?, ?, ?, 'synced')");
        $stmtPay->bind_param("isd", $memberId, $currentMonth, $amount);
        $stmtPay->execute();
        $stmtPay->close();

        return [
            'id' => $memberId,
            'user_id' => $userId,
            'class' => $class,
            'student_id' => $studentId,
            'card_token' => $cardToken,
            'max_borrow_limit' => $maxLimit,
            'status' => $status
        ];
    }

    protected function createBookWithCopies(int $copiesCount = 1, int $isBorrowable = 1): array {
        $title = 'የሙከራ መጽሐፍ ' . bin2hex(random_bytes(3));
        $author = 'ሙከራ ደራሲ';
        $categoryId = 1;

        $stmt = $this->conn->prepare("INSERT INTO books (title, author, category_id, is_borrowable, quantity) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiii", $title, $author, $categoryId, $isBorrowable, $copiesCount);
        $stmt->execute();
        $bookId = $stmt->insert_id;
        $stmt->close();

        $copies = [];
        for ($i = 1; $i <= $copiesCount; $i++) {
            $copyCode = 'BK' . $bookId . 'C' . $i . bin2hex(random_bytes(2));
            $qrIdentifier = 'QR-' . bin2hex(random_bytes(8));
            $status = 'available';

            $stmtCopy = $this->conn->prepare("INSERT INTO book_copies (book_id, copy_code, qr_identifier, status) VALUES (?, ?, ?, ?)");
            $stmtCopy->bind_param("isss", $bookId, $copyCode, $qrIdentifier, $status);
            $stmtCopy->execute();
            $copyId = $stmtCopy->insert_id;
            $stmtCopy->close();

            $copies[] = [
                'id' => $copyId,
                'book_id' => $bookId,
                'copy_code' => $copyCode,
                'qr_identifier' => $qrIdentifier,
                'status' => $status
            ];
        }

        return [
            'id' => $bookId,
            'title' => $title,
            'copies' => $copies
        ];
    }

    protected function loginAs(string $role, ?int $userId = null): array {
        if (!$userId) {
            $userId = $this->createUser($role, 'active');
        }
        
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user;
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['last_activity'] = time();

        if ($role === 'member') {
            $stmtM = $this->conn->prepare("SELECT * FROM members WHERE user_id = ?");
            $stmtM->bind_param("i", $userId);
            $stmtM->execute();
            $member = $stmtM->get_result()->fetch_assoc();
            $stmtM->close();
            if ($member) {
                $_SESSION['member_id'] = $member['id'];
                $_SESSION['member'] = $member;
            }
        }

        return $user;
    }

    protected function logout(): void {
        $_SESSION = [];
    }

    protected function create_user(string $role = 'member', string $status = 'active'): array {
        $id = $this->createUser($role, $status);
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $user;
    }

    protected function create_member(?int $userId = null, string $status = 'active'): int {
        $mem = $this->createMember($userId, $status);
        return $mem['id'];
    }

    protected function create_book_with_copies(int $copiesCount = 1, int $isBorrowable = 1): array {
        $res = $this->createBookWithCopies($copiesCount, $isBorrowable);
        return [
            'book' => ['id' => $res['id'], 'title' => $res['title']],
            'copies' => $res['copies']
        ];
    }

    protected function login_as(string $role, ?int $userId = null): array {
        return $this->loginAs($role, $userId);
    }
}
