<?php
/**
 * includes/LibraryService.php — Atsede Library
 *
 * Single centralized transactional service for borrowing, returning,
 * renewing, and managing book fines.
 *
 * Uses explicit transactions and SELECT ... FOR UPDATE row locks to prevent
 * double-borrowing, race conditions, and ghost copies.
 */

if (!defined('RUNNING_CRON') && session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/functions.php';

class LibraryService
{
    /**
     * Atomically issues a physical copy to an eligible member.
     *
     * @param mysqli $conn
     * @param int $memberId
     * @param int $copyId
     * @param string|null $dueDate (Y-m-d format, defaults to borrow_days setting)
     * @param int|null $issuedBy Staff user ID
     * @param string $notes
     * @param int|null $requestId Optional borrow_requests ID
     * @param string|null $offlineUuid Optional UUID for offline sync idempotency
     * @return array [success => bool, message => string, borrow_id => int|null, due_date => string|null]
     */
    public static function issue_copy(
        $conn,
        int $memberId,
        int $copyId,
        ?string $dueDate = null,
        ?int $issuedBy = null,
        string $notes = '',
        ?int $requestId = null,
        ?string $offlineUuid = null
    ): array {
        if ($memberId <= 0 || $copyId <= 0) {
            return ['success' => false, 'message' => 'የአባል ወይም የመጽሐፍ ቅጂ መለያ አልተገለጸም።'];
        }

        mysqli_begin_transaction($conn);

        try {
            // 1. Lock and fetch copy + book info
            $stmtLock = mysqli_prepare($conn, "
                SELECT bc.id, bc.book_id, bc.copy_code, bc.status AS copy_status,
                       b.title, b.is_borrowable, b.borrow_status, b.category_id
                FROM book_copies bc
                JOIN books b ON b.id = bc.book_id
                WHERE bc.id = ? FOR UPDATE
            ");
            mysqli_stmt_bind_param($stmtLock, 'i', $copyId);
            mysqli_stmt_execute($stmtLock);
            $copy = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtLock));
            mysqli_stmt_close($stmtLock);

            if (!$copy) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'የመጽሐፍ ቅጂው አልተገኘም።'];
            }

            if ($copy['copy_status'] !== 'available') {
                mysqli_rollback($conn);
                return [
                    'success' => false,
                    'message' => 'ይህ የመጽሐፍ ቅጂ በአሁኑ ሰዓት ዝግጁ አይደለም (ሁኔታ፦ ' . $copy['copy_status'] . ')።'
                ];
            }

            $bookId = (int)$copy['book_id'];

            // 2. Strict eligibility verification
            $eligibility = verify_borrow_eligibility($conn, $memberId, $bookId, $copyId);
            if (!$eligibility['can_borrow']) {
                mysqli_rollback($conn);
                $reasons = [];
                foreach ($eligibility['checks'] as $c) {
                    if (!$c['passed']) {
                        $reasons[] = $c['title'] . ' (' . $c['detail'] . ')';
                    }
                }
                return [
                    'success' => false,
                    'message' => 'ማበደር አልተቻለም፦ ' . implode('፤ ', $reasons),
                    'eligibility' => $eligibility
                ];
            }

            // 3. Determine Due Date
            if (empty($dueDate)) {
                $borrowDays = (int)get_setting($conn, 'borrow_days', 14);
                $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));
            }

            // 4. Update copy status to 'borrowed'
            $stmtUpCopy = mysqli_prepare($conn, "UPDATE book_copies SET status = 'borrowed' WHERE id = ? AND status = 'available'");
            mysqli_stmt_bind_param($stmtUpCopy, 'i', $copyId);
            mysqli_stmt_execute($stmtUpCopy);
            $affected = mysqli_stmt_affected_rows($stmtUpCopy);
            mysqli_stmt_close($stmtUpCopy);

            if ($affected !== 1) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'መጽሐፉን ለመዋስ አልተቻለም (ቅጂው በሌላ ተጠቃሚ ተወስዷል)።'];
            }

            // 5. Insert borrow record

            // 6. Insert borrow record
            $nowStr = date('Y-m-d H:i:s');
            $stmtIns = mysqli_prepare($conn, "
                INSERT INTO borrow_records (request_id, member_id, book_copy_id, book_id, borrowed_at, due_date, status, issued_by, offline_uuid)
                VALUES (?, ?, ?, ?, ?, ?, 'borrowed', ?, ?)
            ");
            mysqli_stmt_bind_param($stmtIns, 'iiiissis', $requestId, $memberId, $copyId, $bookId, $nowStr, $dueDate, $issuedBy, $offlineUuid);
            mysqli_stmt_execute($stmtIns);
            $borrowId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmtIns);

            if (!$borrowId) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'የውሰት መዝገቡን ማስቀመጥ አልተቻለም: ' . mysqli_error($conn)];
            }

            // 7. If linked to a request, mark it approved
            if ($requestId) {
                $stmtReq = mysqli_prepare($conn, "UPDATE borrow_requests SET status = 'approved', book_copy_id = ?, decided_at = NOW(), decided_by = ? WHERE id = ?");
                mysqli_stmt_bind_param($stmtReq, 'iii', $copyId, $issuedBy, $requestId);
                mysqli_stmt_execute($stmtReq);
                mysqli_stmt_close($stmtReq);
            }

            // 8. Notify member
            $mRes = mysqli_query($conn, "SELECT user_id FROM members WHERE id = $memberId");
            $mRow = mysqli_fetch_assoc($mRes);
            if ($mRow && !empty($mRow['user_id'])) {
                $cleanTitle = htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8');
                notify(
                    $conn,
                    (int)$mRow['user_id'],
                    'መጽሐፍ ተሰጥቶዎታል',
                    '"' . $cleanTitle . '" ውሰትዎ ተፈቅዶ መጽሐፉ ተሰጥቷል። የመመለሻ ቀን፦ ' . formatDate($dueDate) . '።',
                    'borrow_approved',
                    'member/my_books.php'
                );
            }

            // 9. Audit log
            audit($conn, $issuedBy, 'book_issued', "borrow_id:$borrowId copy_id:$copyId member_id:$memberId due:$dueDate");

            mysqli_commit($conn);

            return [
                'success' => true,
                'borrow_id' => $borrowId,
                'due_date' => $dueDate,
                'copy_code' => $copy['copy_code'],
                'title' => $copy['title'],
                'message' => '✓ መጽሐፉ ለአባሉ ተሰጥቷል። የመመለሻ ቀን፦ ' . formatDate($dueDate)
            ];

        } catch (Throwable $e) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'የውሰት ሂደት ስህተት፦ ' . $e->getMessage()];
        }
    }

    /**
     * Return, mark lost, or mark damaged an active borrowed copy.
     *
     * @param mysqli $conn
     * @param int $copyId
     * @param string $action 'returned' | 'damaged' | 'lost'
     * @param string $notes
     * @param int|null $receivedBy Staff user ID
     * @return array [success => bool, message => string, net_fine => float, record_id => int|null]
     */
    public static function return_copy(
        $conn,
        int $copyId,
        string $action = 'returned',
        string $notes = '',
        ?int $receivedBy = null
    ): array {
        if ($copyId <= 0) {
            return ['success' => false, 'message' => 'የመጽሐፍ ቅጂ መለያ አልተገለጸም።'];
        }

        $validActions = ['returned', 'damaged', 'lost'];
        if (!in_array($action, $validActions, true)) {
            $action = 'returned';
        }

        mysqli_begin_transaction($conn);

        try {
            // 1. Lock physical copy
            $stmtCopy = mysqli_prepare($conn, "SELECT id, book_id, copy_code, status FROM book_copies WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmtCopy, 'i', $copyId);
            mysqli_stmt_execute($stmtCopy);
            $copy = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtCopy));
            mysqli_stmt_close($stmtCopy);

            if (!$copy) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'የመጽሐፍ ቅጂው አልተገኘም።'];
            }

            // 2. Lock active borrow record
            $stmtRecord = mysqli_prepare($conn, "
                SELECT br.*, m.user_id AS member_user_id, b.title, b.id AS book_table_id
                FROM borrow_records br
                JOIN members m ON m.id = br.member_id
                JOIN books b ON b.id = br.book_id
                WHERE br.book_copy_id = ? AND br.status = 'borrowed'
                ORDER BY br.id DESC LIMIT 1 FOR UPDATE
            ");
            mysqli_stmt_bind_param($stmtRecord, 'i', $copyId);
            mysqli_stmt_execute($stmtRecord);
            $record = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtRecord));
            mysqli_stmt_close($stmtRecord);

            if (!$record) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'ለዚህ ቅጂ ምንም ገቢር የውሰት መዝገብ አልተገኘም።'];
            }

            $bookId = (int)$record['book_table_id'];
            $recordId = (int)$record['id'];

            // 3. Recalculate fine at the moment of return
            $dueDate    = new DateTime($record['due_date']);
            $today      = new DateTime(date('Y-m-d'));
            $graceDays  = (int)get_setting($conn, 'fine_grace_days', 0);
            $finePerDay = (float)get_setting($conn, 'overdue_fine_per_day', 5);

            $calculatedFine = 0.00;
            if ($today > $dueDate) {
                $diffDays = (int)$today->diff($dueDate)->days;
                $overdueDays = max(0, $diffDays - $graceDays);
                $calculatedFine = round($overdueDays * $finePerDay, 2);
            }

            $finePaid   = (float)($record['fine_paid'] ?? 0);
            $fineWaived = (float)($record['fine_waived'] ?? 0);
            $finalOverdueFine = max((float)$record['overdue_fine'], $calculatedFine);
            $netFine    = max(0.00, $finalOverdueFine - $finePaid - $fineWaived);

            // 4. Update borrow_records
            $recordStatus = ($action === 'lost') ? 'lost' : (($action === 'damaged') ? 'damaged' : 'returned');
            $nowStr = date('Y-m-d H:i:s');
            $todayStr = date('Y-m-d');

            $stmtUpRec = mysqli_prepare($conn, "
                UPDATE borrow_records 
                SET status = ?, returned_at = ?, returned_to = ?, overdue_fine = ?, last_fine_calc = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($stmtUpRec, 'ssidsi', $recordStatus, $nowStr, $receivedBy, $finalOverdueFine, $todayStr, $recordId);
            mysqli_stmt_execute($stmtUpRec);
            mysqli_stmt_close($stmtUpRec);

            // 5. Update book_copies and available_copies count
            // LOST and DAMAGED copies must NEVER become 'available'!
            if ($action === 'lost') {
                mysqli_query($conn, "UPDATE book_copies SET status = 'lost' WHERE id = $copyId");
            } elseif ($action === 'damaged') {
                mysqli_query($conn, "UPDATE book_copies SET status = 'damaged' WHERE id = $copyId");
            } else {
                mysqli_query($conn, "UPDATE book_copies SET status = 'available' WHERE id = $copyId");

                // Send availability notifications from this single place
                $alertRes = mysqli_query($conn, "SELECT user_id FROM book_availability_alerts WHERE book_id = $bookId AND is_notified = 0");
                while ($al = mysqli_fetch_assoc($alertRes)) {
                    notify(
                        $conn,
                        (int)$al['user_id'],
                        'መጽሐፉ አሁን ይገኛል 🔔',
                        '"' . htmlspecialchars($record['title'], ENT_QUOTES, 'UTF-8') . '" ወደ ቤተ-መጻሕፍት ተመልሷል። አሁን መዋስ ይችላሉ።',
                        'book_available',
                        'book.php?id=' . $bookId
                    );
                }
                mysqli_query($conn, "UPDATE book_availability_alerts SET is_notified = 1 WHERE book_id = $bookId");
            }

            // 6. Member notification
            $returnMsg = '"' . htmlspecialchars($record['title'], ENT_QUOTES, 'UTF-8') . '" ';
            if ($action === 'lost') {
                $returnMsg .= 'ቅጂ እንደጠፋ ተመዝግቧል።';
            } elseif ($action === 'damaged') {
                $returnMsg .= 'ቅጂ እንደተጎዳ ተመዝግቧል።';
            } else {
                $returnMsg .= 'ቅጂ በትክክል ተመልሷል። እናመሰግናለን።';
            }

            if ($netFine > 0) {
                $returnMsg .= ' ያልተከፈለ ቅጣት፦ ' . number_format($netFine, 2) . ' ብር።';
            }

            notify($conn, (int)$record['member_user_id'], 'መጽሐፍ ተመላሽ መረጃ', $returnMsg, 'general', 'member/my_books.php');

            // 7. Audit log
            audit($conn, $receivedBy, 'book_returned', "record_id:$recordId copy_id:$copyId action:$action fine:$netFine");

            mysqli_commit($conn);

            return [
                'success' => true,
                'record_id' => $recordId,
                'action' => $action,
                'net_fine' => $netFine,
                'title' => $record['title'],
                'message' => 'ተመላሹ በተሳካ ሁኔታ ተመዝግቧል።' . ($netFine > 0 ? ' ያልተከፈለ ቅጣት፦ ' . number_format($netFine, 2) . ' ብር' : '')
            ];

        } catch (Throwable $e) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'የተመላሽ ሂደት ስህተት፦ ' . $e->getMessage()];
        }
    }

    /**
     * Renew an active borrow record.
     *
     * @param mysqli $conn
     * @param int $borrowId
     * @param string|null $newDueDate
     * @param int|null $userId
     * @return array [success => bool, message => string]
     */
    public static function renew($conn, int $borrowId, ?string $newDueDate = null, ?int $userId = null): array
    {
        if ($borrowId <= 0) {
            return ['success' => false, 'message' => 'የውሰት መዝገብ መለያ አልተገለጸም።'];
        }

        mysqli_begin_transaction($conn);

        try {
            $stmt = mysqli_prepare($conn, "
                SELECT br.*, b.title, m.user_id AS member_user_id
                FROM borrow_records br
                JOIN books b ON b.id = br.book_id
                JOIN members m ON m.id = br.member_id
                WHERE br.id = ? FOR UPDATE
            ");
            mysqli_stmt_bind_param($stmt, 'i', $borrowId);
            mysqli_stmt_execute($stmt);
            $record = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if (!$record || $record['status'] !== 'borrowed') {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'ገቢር የውሰት መዝገብ አልተገኘም።'];
            }

            // Check if already overdue
            $today = date('Y-m-d');
            if ($record['due_date'] < $today) {
                mysqli_rollback($conn);
                return ['success' => false, 'message' => 'የዘገየ መጽሐፍ ማደስ አይቻልም፤ መጀመሪያ መመለስ እና ቅጣት መክፈል አለበት።'];
            }

            if (empty($newDueDate)) {
                $borrowDays = (int)get_setting($conn, 'borrow_days', 14);
                $newDueDate = date('Y-m-d', strtotime("+$borrowDays days", strtotime($record['due_date'])));
            }

            $stmtUp = mysqli_prepare($conn, "UPDATE borrow_records SET due_date = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtUp, 'si', $newDueDate, $borrowId);
            mysqli_stmt_execute($stmtUp);
            mysqli_stmt_close($stmtUp);

            notify(
                $conn,
                (int)$record['member_user_id'],
                'ውሰት ታድሷል',
                '"' . htmlspecialchars($record['title'], ENT_QUOTES, 'UTF-8') . '" ውሰትዎ ታድሷል። አዲሱ የመመለሻ ቀን፦ ' . formatDate($newDueDate),
                'general',
                'member/my_books.php'
            );

            audit($conn, $userId, 'borrow_renewed', "borrow_id:$borrowId new_due:$newDueDate");

            mysqli_commit($conn);

            return [
                'success' => true,
                'new_due_date' => $newDueDate,
                'message' => 'ውሰቱ ታድሷል፤ አዲሱ የመመለሻ ቀን፦ ' . formatDate($newDueDate)
            ];

        } catch (Throwable $e) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'የማደስ ሂደት ስህተት፦ ' . $e->getMessage()];
        }
    }

    /**
     * Record fine payment wrapper.
     */
    public static function record_fine_payment($conn, int $borrowId, float $amount, ?int $receivedBy = null, string $notes = ''): array
    {
        if ($borrowId <= 0 || $amount <= 0) {
            return ['success' => false, 'message' => 'ትክክለኛ የውሰት መለያ እና የክፍያ መጠን ያስገቡ።'];
        }

        $rec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM borrow_records WHERE id = $borrowId"));
        if (!$rec) {
            return ['success' => false, 'message' => 'የውሰት መዝገቡ አልተገኘም።'];
        }

        $memberId = (int)$rec['member_id'];
        record_fine_payment($conn, $borrowId, $memberId, $amount, 0.00, $receivedBy, $notes);

        return ['success' => true, 'message' => 'የቅጣት ክፍያው በተሳካ ሁኔታ ተመዝግቧል።'];
    }

    /**
     * Waive fine wrapper.
     */
    public static function waive_fine($conn, int $borrowId, float $amount, ?int $waivedBy = null, string $reason = ''): array
    {
        return waive_fine($conn, $borrowId, $amount, $waivedBy, $reason);
    }
}

// Global functional wrappers for direct calling
function issue_copy($conn, $memberId, $copyId, $dueDate = null, $issuedBy = null, $notes = '', $requestId = null, $offlineUuid = null) {
    return LibraryService::issue_copy($conn, (int)$memberId, (int)$copyId, $dueDate, $issuedBy ? (int)$issuedBy : null, $notes, $requestId ? (int)$requestId : null, $offlineUuid);
}

function return_copy($conn, $copyId, $action = 'returned', $notes = '', $receivedBy = null) {
    return LibraryService::return_copy($conn, (int)$copyId, $action, $notes, $receivedBy ? (int)$receivedBy : null);
}

function renew($conn, $borrowId, $newDueDate = null, $userId = null) {
    return LibraryService::renew($conn, (int)$borrowId, $newDueDate, $userId ? (int)$userId : null);
}
