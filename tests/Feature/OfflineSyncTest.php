<?php
namespace Tests\Feature;

use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    /**
     * Helper to simulate a POST JSON request to ajax/offline_sync.php
     */
    private function postSync(array $payload): array
    {
        global $conn;
        $conn = $this->conn;
        
        $GLOBALS['mock_raw_input'] = json_encode($payload);
        
        ob_start();
        try {
            include __DIR__ . '/../../ajax/offline_sync.php';
        } finally {
            $output = ob_get_clean();
            unset($GLOBALS['mock_raw_input']);
        }
        
        return json_decode($output, true) ?: ['raw' => $output];
    }

    public function testUnauthenticatedUserCannotSync()
    {
        $this->logout();
        
        $payload = [
            'events' => [
                [
                    'event_uuid'     => 'test-uuid-1',
                    'operation_type' => 'BORROW',
                    'payload'        => []
                ]
            ]
        ];

        $res = $this->postSync($payload);

        $this->assertFalse($res['success'] ?? true);
        $this->assertStringContainsString('እንደገና ይግቡ', $res['error'] ?? '');
    }

    public function testMemberCannotPerformBorrowOrReturn()
    {
        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);
        $this->login_as('member', $memberUser['id']);

        $book = $this->create_book_with_copies(1);
        $copyId = $book['copies'][0]['id'];

        $payload = [
            'events' => [
                [
                    'event_uuid'     => 'member-borrow-' . uniqid(),
                    'operation_type' => 'BORROW',
                    'payload'        => [
                        'member_id' => $memberId,
                        'copy_id'   => $copyId
                    ]
                ]
            ]
        ];

        $res = $this->postSync($payload);

        $this->assertTrue($res['success']);
        $this->assertEquals(0, $res['synced_count']);
        $this->assertEquals('rejected', $res['results'][0]['status']);
        $this->assertStringContainsString('ስልጣን የለዎትም', $res['results'][0]['message']);
    }

    public function testMemberCanRequestBookForThemselves()
    {
        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);
        $this->login_as('member', $memberUser['id']);

        $book = $this->create_book_with_copies(1);
        $bookId = $book['book']['id'];

        $uuid = 'member-req-' . uniqid();
        $payload = [
            'events' => [
                [
                    'event_uuid'     => $uuid,
                    'operation_type' => 'REQUEST',
                    'payload'        => [
                        'book_id' => $bookId
                    ]
                ]
            ]
        ];

        $res = $this->postSync($payload);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['synced_count']);
        $this->assertEquals('synced', $res['results'][0]['status']);

        // Check database borrow_requests table
        $stmt = mysqli_prepare($this->conn, "SELECT id, status FROM borrow_requests WHERE member_id = ? AND book_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $memberId, $bookId);
        mysqli_stmt_execute($stmt);
        $req = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $this->assertNotNull($req);
        $this->assertEquals('pending', $req['status']);
    }

    public function testStaffBorrowSuccessAndIdempotency()
    {
        $staffUser = $this->create_user('librarian', 'active');
        $this->login_as('librarian', $staffUser['id']);

        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);

        $book = $this->create_book_with_copies(1);
        $copyId = $book['copies'][0]['id'];

        $uuid = 'borrow-uuid-' . uniqid();
        $payload = [
            'events' => [
                [
                    'event_uuid'     => $uuid,
                    'operation_type' => 'BORROW',
                    'payload'        => [
                        'member_id' => $memberId,
                        'copy_id'   => $copyId
                    ]
                ]
            ]
        ];

        // 1st Sync
        $res1 = $this->postSync($payload);

        $this->assertEquals(1, $res1['synced_count']);
        $this->assertEquals('synced', $res1['results'][0]['status']);

        // Verify copy status in DB
        $copyRes = mysqli_query($this->conn, "SELECT status FROM book_copies WHERE id = $copyId");
        $copyRow = mysqli_fetch_assoc($copyRes);
        $this->assertEquals('borrowed', $copyRow['status']);

        // Verify borrow record and due_date format (YYYY-MM-DD)
        $recRes = mysqli_query($this->conn, "SELECT id, due_date FROM borrow_records WHERE offline_uuid = '$uuid'");
        $recRow = mysqli_fetch_assoc($recRes);
        $this->assertNotNull($recRow);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $recRow['due_date']);

        // 2nd Sync with SAME UUID (Idempotency)
        $res2 = $this->postSync($payload);

        $this->assertEquals(1, $res2['synced_count']);
        $this->assertEquals('synced', $res2['results'][0]['status']);
        $this->assertStringContainsString('ቀደም ሲል የተመሳሰለ', $res2['results'][0]['message']);

        // Count in DB should still be 1
        $countRes = mysqli_query($this->conn, "SELECT COUNT(*) as cnt FROM borrow_records WHERE offline_uuid = '$uuid'");
        $countRow = mysqli_fetch_assoc($countRes);
        $this->assertEquals(1, (int)$countRow['cnt']);
    }

    public function testBorrowRejectedIfMemberHasUnpaidFines()
    {
        $staffUser = $this->create_user('librarian', 'active');
        $this->login_as('librarian', $staffUser['id']);

        $memberUser = $this->create_user('member', 'active');
        $memberId = $this->create_member($memberUser['id']);

        $existingBook = $this->create_book_with_copies(1);
        $existingCopyId = $existingBook['copies'][0]['id'];
        $existingBookId = $existingBook['book']['id'];

        // Create an overdue borrow record with unpaid fine for this member
        mysqli_query($this->conn, "INSERT INTO borrow_records 
            (member_id, book_id, book_copy_id, due_date, overdue_fine, fine_paid, fine_waived, status) 
            VALUES ($memberId, $existingBookId, $existingCopyId, '2025-01-01', 50.00, 0.00, 0.00, 'borrowed')");

        $newBook = $this->create_book_with_copies(1);
        $newCopyId = $newBook['copies'][0]['id'];

        $uuid = 'borrow-fine-' . uniqid();
        $payload = [
            'events' => [
                [
                    'event_uuid'     => $uuid,
                    'operation_type' => 'BORROW',
                    'payload'        => [
                        'member_id' => $memberId,
                        'copy_id'   => $newCopyId
                    ]
                ]
            ]
        ];

        $res = $this->postSync($payload);

        $this->assertEquals(0, $res['synced_count']);
        $this->assertEquals('rejected', $res['results'][0]['status']);
        $this->assertStringContainsString('ያልተከፈለ ቅጣት', $res['results'][0]['message']);

        // Copy must remain 'available'
        $copyRes = mysqli_query($this->conn, "SELECT status FROM book_copies WHERE id = $newCopyId");
        $copyRow = mysqli_fetch_assoc($copyRes);
        $this->assertEquals('available', $copyRow['status']);
    }

    public function testConflictWhenCopyAlreadyBorrowed()
    {
        $staffUser = $this->create_user('librarian', 'active');
        $this->login_as('librarian', $staffUser['id']);

        $member1 = $this->create_member($this->create_user('member', 'active')['id']);
        $member2 = $this->create_member($this->create_user('member', 'active')['id']);

        $book = $this->create_book_with_copies(1);
        $copyId = $book['copies'][0]['id'];

        // First member borrows
        $uuid1 = 'borrow-conf-1-' . uniqid();
        $payload1 = [
            'events' => [
                [
                    'event_uuid'     => $uuid1,
                    'operation_type' => 'BORROW',
                    'payload'        => ['member_id' => $member1, 'copy_id' => $copyId]
                ]
            ]
        ];
        $this->postSync($payload1);

        // Second member tries to borrow same copy
        $uuid2 = 'borrow-conf-2-' . uniqid();
        $payload2 = [
            'events' => [
                [
                    'event_uuid'     => $uuid2,
                    'operation_type' => 'BORROW',
                    'payload'        => ['member_id' => $member2, 'copy_id' => $copyId]
                ]
            ]
        ];
        $res2 = $this->postSync($payload2);

        $this->assertEquals(0, $res2['synced_count']);
        $this->assertEquals('rejected', $res2['results'][0]['status']);
        $this->assertStringContainsString('ዝግጁ አይደለም', $res2['results'][0]['message']);
    }

    public function testReturnBookRestoresAvailability()
    {
        $staffUser = $this->create_user('librarian', 'active');
        $this->login_as('librarian', $staffUser['id']);

        $member = $this->create_member($this->create_user('member', 'active')['id']);
        $book = $this->create_book_with_copies(1);
        $copyId = $book['copies'][0]['id'];

        // Borrow first
        $bUuid = 'borrow-ret-' . uniqid();
        $p1 = [
            'events' => [
                ['event_uuid' => $bUuid, 'operation_type' => 'BORROW', 'payload' => ['member_id' => $member, 'copy_id' => $copyId]]
            ]
        ];
        $this->postSync($p1);

        // Find borrow record
        $bRes = mysqli_query($this->conn, "SELECT id FROM borrow_records WHERE offline_uuid = '$bUuid'");
        $bRow = mysqli_fetch_assoc($bRes);
        $borrowId = (int)$bRow['id'];

        // Return
        $rUuid = 'ret-uuid-' . uniqid();
        $p2 = [
            'events' => [
                ['event_uuid' => $rUuid, 'operation_type' => 'RETURN', 'payload' => ['borrow_id' => $borrowId, 'condition_status' => 'good']]
            ]
        ];
        $res = $this->postSync($p2);

        $this->assertEquals(1, $res['synced_count']);
        $this->assertEquals('synced', $res['results'][0]['status']);

        // Check copy status back to 'available'
        $copyRes = mysqli_query($this->conn, "SELECT status FROM book_copies WHERE id = $copyId");
        $copyRow = mysqli_fetch_assoc($copyRes);
        $this->assertEquals('available', $copyRow['status']);
    }
}
