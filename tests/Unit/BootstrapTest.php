<?php
namespace Tests\Unit;

use Tests\TestCase;

class BootstrapTest extends TestCase {
    public function testDatabaseConnectionAndHelpers(): void {
        $this->assertNotNull($this->conn);
        $userId = $this->createUser('member', 'active');
        $this->assertGreaterThan(0, $userId);

        $member = $this->createMember($userId, 'active');
        $this->assertEquals($userId, $member['user_id']);

        $book = $this->createBookWithCopies(2, 1);
        $this->assertCount(2, $book['copies']);

        $user = $this->loginAs('member', $userId);
        $this->assertEquals($userId, $_SESSION['user_id']);
        $this->assertEquals('member', $_SESSION['role']);
    }
}
