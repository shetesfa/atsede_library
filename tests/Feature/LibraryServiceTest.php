<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use function issue_copy;
use function return_copy;
use function renew;
use function record_fine_payment;
use function waive_fine;
use function get_member_outstanding_fine;
use function recalculate_all_fines;
use function next_codes_for_category;

class LibraryServiceTest extends TestCase
{
    public function testIssueReturnFineLifecycle(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];
        $book = $this->createBookWithCopies(1);
        $copyId = (int)$book['copies'][0]['id'];

        // 1. Issue copy
        $resIssue = issue_copy($conn, $memberId, $copyId);
        $this->assertTrue($resIssue['success'], 'Book issue should succeed: ' . ($resIssue['message'] ?? ''));
        $this->assertNotEmpty($resIssue['borrow_id']);

        // Verify copy status is borrowed
        $cRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM book_copies WHERE id = $copyId"));
        $this->assertSame('borrowed', $cRow['status']);

        // 2. Return copy
        $resReturn = return_copy($conn, $copyId, 'returned');
        $this->assertTrue($resReturn['success'], 'Book return should succeed: ' . ($resReturn['message'] ?? ''));

        // Verify copy status is available again
        $cRow2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM book_copies WHERE id = $copyId"));
        $this->assertSame('available', $cRow2['status']);
    }

    public function testReturnOfLostOrDamagedNeverResurrectsAvailable(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];

        // Test Lost
        $bookLost = $this->createBookWithCopies(1);
        $copyLostId = (int)$bookLost['copies'][0]['id'];
        issue_copy($conn, $memberId, $copyLostId);
        $retLost = return_copy($conn, $copyLostId, 'lost');
        $this->assertTrue($retLost['success']);

        $cLost = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM book_copies WHERE id = $copyLostId"));
        $this->assertSame('lost', $cLost['status']);

        // Test Damaged
        $bookDamaged = $this->createBookWithCopies(1);
        $copyDamagedId = (int)$bookDamaged['copies'][0]['id'];
        issue_copy($conn, $memberId, $copyDamagedId);
        $retDamaged = return_copy($conn, $copyDamagedId, 'damaged');
        $this->assertTrue($retDamaged['success']);

        $cDamaged = mysqli_fetch_assoc(mysqli_query($conn, "SELECT status FROM book_copies WHERE id = $copyDamagedId"));
        $this->assertSame('damaged', $cDamaged['status']);
    }

    public function testDoubleReturnReturnsError(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];
        $book = $this->createBookWithCopies(1);
        $copyId = (int)$book['copies'][0]['id'];

        issue_copy($conn, $memberId, $copyId);
        $firstReturn = return_copy($conn, $copyId, 'returned');
        $this->assertTrue($firstReturn['success']);

        // Attempt second return on already returned copy
        $secondReturn = return_copy($conn, $copyId, 'returned');
        $this->assertFalse($secondReturn['success'], 'Second return should fail because no active record exists');
    }

    public function testMemberWithUnpaidFineCannotBorrow(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];

        // Create an overdue returned record with unpaid fine
        $book1 = $this->createBookWithCopies(1);
        $copy1Id = (int)$book1['copies'][0]['id'];
        $book1Id = (int)$book1['id'];
        $pastDate = date('Y-m-d', strtotime('-10 days'));

        mysqli_query($conn, "INSERT INTO borrow_records (member_id, book_copy_id, book_id, borrowed_at, due_date, returned_at, status, overdue_fine, fine_paid, fine_waived)
            VALUES ($memberId, $copy1Id, $book1Id, NOW(), '$pastDate', NOW(), 'returned', 50.00, 0.00, 0.00)");
        $borrowRecId = mysqli_insert_id($conn);

        // Verify outstanding fine includes returned records
        $fine = get_member_outstanding_fine($conn, $memberId);
        $this->assertGreaterThan(0.0, $fine, 'Outstanding fine must include unpaid returned records');

        // Attempting to borrow a new book must fail
        $book2 = $this->createBookWithCopies(1);
        $copy2Id = (int)$book2['copies'][0]['id'];
        $resIssue = issue_copy($conn, $memberId, $copy2Id);
        $this->assertFalse($resIssue['success'], 'Member with unpaid fine must be blocked from borrowing');

        // Pay the fine
        record_fine_payment($conn, $borrowRecId, $memberId, 50.00, 0.00);
        $fineAfter = get_member_outstanding_fine($conn, $memberId);
        $this->assertEquals(0.0, $fineAfter);

        // Now borrowing should succeed
        $resIssue2 = issue_copy($conn, $memberId, $copy2Id);
        $this->assertTrue($resIssue2['success'], 'Borrowing should succeed after fine is cleared');
    }

    public function testReferenceBookCannotBeBorrowed(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];

        $book = $this->createBookWithCopies(1);
        $bookId = (int)$book['id'];
        $copyId = (int)$book['copies'][0]['id'];

        // Mark book as reference
        mysqli_query($conn, "UPDATE books SET borrow_status = 'reference' WHERE id = $bookId");

        $res = issue_copy($conn, $memberId, $copyId);
        $this->assertFalse($res['success'], 'Reference book must never be allowed for checkout');
        $this->assertStringContainsString('Reference', $res['message']);
    }

    public function testFinePaidPartiallyThenReturned(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];

        $book = $this->createBookWithCopies(1);
        $copyId = (int)$book['copies'][0]['id'];
        $dueDate = date('Y-m-d', strtotime('-5 days'));

        $issueRes = issue_copy($conn, $memberId, $copyId, $dueDate);
        $this->assertTrue($issueRes['success']);
        $borrowId = (int)$issueRes['borrow_id'];

        // Recalculate fines
        recalculate_all_fines($conn);

        // Pay partial fine
        record_fine_payment($conn, $borrowId, $memberId, 10.00, 0.00);

        // Return copy
        $retRes = return_copy($conn, $copyId, 'returned');
        $this->assertTrue($retRes['success']);

        $rec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT overdue_fine, fine_paid, fine_waived FROM borrow_records WHERE id = $borrowId"));
        $this->assertEquals(10.00, (float)$rec['fine_paid']);
        $this->assertGreaterThanOrEqual(10.00, (float)$rec['overdue_fine']);
    }

    public function testNextCodesForCategorySupportsOver26(): void
    {
        $conn = $this->conn;
        $catRes = mysqli_query($conn, "SELECT id FROM categories LIMIT 1");
        $catRow = mysqli_fetch_assoc($catRes);
        $catId = $catRow ? (int)$catRow['id'] : 1;

        $codes = next_codes_for_category($conn, $catId, 30);
        $this->assertCount(30, $codes);

        // First copy is A (index 0)
        $this->assertStringEndsWith('A', $codes[0]);
        // 26th copy is Z (index 25)
        $this->assertStringEndsWith('Z', $codes[25]);
        // 27th copy is AA (index 26)
        $this->assertStringEndsWith('AA', $codes[26]);
        // 28th copy is AB (index 27)
        $this->assertStringEndsWith('AB', $codes[27]);
    }

    public function testWaiveFineRequiresReason(): void
    {
        $conn = $this->conn;
        $member = $this->createMember();
        $memberId = (int)$member['id'];
        $book = $this->createBookWithCopies(1);
        $copyId = (int)$book['copies'][0]['id'];
        $bookId = (int)$book['id'];

        mysqli_query($conn, "INSERT INTO borrow_records (member_id, book_copy_id, book_id, borrowed_at, due_date, status, overdue_fine)
            VALUES ($memberId, $copyId, $bookId, NOW(), CURDATE(), 'borrowed', 20.00)");
        $recId = mysqli_insert_id($conn);

        // Empty reason should fail
        $resFail = waive_fine($conn, $recId, 10.00, null, '');
        $this->assertFalse($resFail['success']);
        $this->assertStringContainsString('ምክንያት', $resFail['message']);

        // With reason should succeed
        $resOk = waive_fine($conn, $recId, 10.00, null, 'የሕመም ማስረጃ ስላቀረበ');
        $this->assertTrue($resOk['success']);

        $rec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT fine_waived FROM borrow_records WHERE id = $recId"));
        $this->assertEquals(10.00, (float)$rec['fine_waived']);
    }
}
