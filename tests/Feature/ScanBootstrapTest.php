<?php
namespace Tests\Feature;

use Tests\TestCase;

class ScanBootstrapTest extends TestCase
{
    private function fetchBootstrap(): array
    {
        global $conn;
        $conn = $this->conn;

        ob_start();
        try {
            include __DIR__ . '/../../ajax/offline_bootstrap.php';
        } finally {
            $output = ob_get_clean();
        }

        return json_decode($output, true) ?: ['raw' => $output];
    }

    public function testUnauthenticatedBootstrapReturns401()
    {
        $this->logout();
        $res = $this->fetchBootstrap();

        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('ያልተፈቀደ', $res['error'] ?? '');
    }

    public function testMemberBootstrapDoesNotExposeMembersList()
    {
        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);
        $this->login_as('member', $memberUser['id']);

        $res = $this->fetchBootstrap();

        $this->assertTrue($res['success']);
        $this->assertArrayNotHasKey('members', $res, 'Members roster must never be exposed to normal members.');
        $this->assertArrayHasKey('books', $res);
        $this->assertArrayHasKey('my_payment_status', $res);

        // Check book and copies structure
        if (!empty($res['books'])) {
            $firstBook = $res['books'][0];
            $this->assertArrayHasKey('copies', $firstBook);
            $this->assertArrayHasKey('position', $firstBook);
            if (!empty($firstBook['copies'])) {
                $copy = $firstBook['copies'][0];
                $this->assertArrayHasKey('id', $copy);
                $this->assertArrayHasKey('copy_code', $copy);
                $this->assertArrayHasKey('qr_identifier', $copy);
                $this->assertArrayHasKey('status', $copy);
            }
        }
    }

    public function testLibrarianBootstrapIncludesMembersAndLoans()
    {
        $staffUser = $this->create_user('librarian', 'active');
        $this->login_as('librarian', $staffUser['id']);

        // Create a test member and book
        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);
        $book = $this->create_book_with_copies(2);

        $res = $this->fetchBootstrap();

        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('members', $res);
        $this->assertArrayHasKey('active_loans', $res);
        $this->assertArrayHasKey('books', $res);

        // Verify book copy details
        $found = false;
        foreach ($res['books'] as $b) {
            if ($b['id'] === $book['book']['id']) {
                $found = true;
                $this->assertCount(2, $b['copies']);
                $this->assertEquals(2, $b['available_copies']);
                $this->assertIsInt($b['copies'][0]['id']);
                break;
            }
        }
        $this->assertTrue($found, 'Newly created book must appear in bootstrap catalog.');
    }

    public function testResolveCopyByQrPrioritizesQrIdentifierOverCopyCode()
    {
        // Create Book A with copy: code="CONFLICT_CODE", qr="QR_A"
        $bookA = $this->create_book_with_copies(1);
        $copyAId = $bookA['copies'][0]['id'];
        mysqli_query($this->conn, "UPDATE book_copies SET copy_code = 'CONFLICT_CODE', qr_identifier = 'QR_A' WHERE id = $copyAId");

        // Create Book B with copy: code="OTHER_CODE", qr="CONFLICT_CODE" (matching Book A's copy_code)
        $bookB = $this->create_book_with_copies(1);
        $copyBId = $bookB['copies'][0]['id'];
        mysqli_query($this->conn, "UPDATE book_copies SET copy_code = 'OTHER_CODE', qr_identifier = 'CONFLICT_CODE' WHERE id = $copyBId");

        // When resolving 'CONFLICT_CODE', it must strictly return Book B (matched by qr_identifier)
        $resolved = resolve_copy_by_qr($this->conn, 'CONFLICT_CODE');
        $this->assertNotNull($resolved);
        $this->assertEquals($copyBId, (int)$resolved['id'], 'Must prioritize qr_identifier match over copy_code match.');

        // When resolving 'QR_A', it matches Book A by qr_identifier
        $resolvedA = resolve_copy_by_qr($this->conn, 'QR_A');
        $this->assertNotNull($resolvedA);
        $this->assertEquals($copyAId, (int)$resolvedA['id']);

        // When resolving 'OTHER_CODE', fallback to copy_code matches Book B
        $resolvedFallback = resolve_copy_by_qr($this->conn, 'OTHER_CODE');
        $this->assertNotNull($resolvedFallback);
        $this->assertEquals($copyBId, (int)$resolvedFallback['id']);

        // Resolving URL with query param
        $resolvedUrl = resolve_copy_by_qr($this->conn, 'https://example.com/qr.php?code=QR_A');
        $this->assertNotNull($resolvedUrl);
        $this->assertEquals($copyAId, (int)$resolvedUrl['id']);

        // Non-existent code returns null
        $this->assertNull(resolve_copy_by_qr($this->conn, 'NON_EXISTENT_QR_' . uniqid()));
        $this->assertNull(resolve_copy_by_qr($this->conn, ''));
    }
}
