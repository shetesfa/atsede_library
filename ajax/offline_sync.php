<?php
/**
 * ajax/offline_sync.php
 * Secure offline event synchronization endpoint.
 * Enforces role checks, atomic transactions with row locking (SELECT ... FOR UPDATE),
 * strict eligibility verification, parameter type safety, and Amharic response messages.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

global $conn;

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'ክፍለ ጊዜዎ አልቋል፤ እንደገና ይግቡ'
    ], JSON_UNESCAPED_UNICODE);
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

$user = current_user();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? 'member';
$isStaff = in_array($userRole, ['librarian', 'admin'], true);

// Fetch member ID if current user is a member
$currentUserMemberId = 0;
if ($userRole === 'member') {
    $stmtM = mysqli_prepare($conn, "SELECT id FROM members WHERE user_id = ? LIMIT 1");
    if ($stmtM) {
        mysqli_stmt_bind_param($stmtM, 'i', $userId);
        mysqli_stmt_execute($stmtM);
        $mRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtM));
        $currentUserMemberId = (int)($mRow['id'] ?? 0);
        mysqli_stmt_close($stmtM);
    }
}

$raw = $GLOBALS['mock_raw_input'] ?? file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['events']) || !is_array($data['events'])) {
    echo json_encode([
        'success'      => true,
        'synced_count' => 0,
        'results'      => []
    ], JSON_UNESCAPED_UNICODE);
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

$deviceId = clean($data['device_id'] ?? ('browser_' . $userId));
$results = [];
$syncedCount = 0;

foreach ($data['events'] as $ev) {
    $uuid = trim($ev['event_uuid'] ?? '');
    $opType = strtoupper(trim($ev['operation_type'] ?? ''));
    $payload = $ev['payload'] ?? [];

    if (empty($uuid) || empty($opType)) {
        continue;
    }

    // 1. Idempotency Check: Was this UUID already processed?
    $checkStmt = mysqli_prepare($conn, "SELECT id, status, error_message FROM sync_events WHERE event_uuid = ? LIMIT 1");
    if ($checkStmt) {
        mysqli_stmt_bind_param($checkStmt, 's', $uuid);
        mysqli_stmt_execute($checkStmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
        mysqli_stmt_close($checkStmt);

        if ($existing && $existing['status'] === 'synced') {
            $results[] = [
                'event_uuid' => $uuid,
                'status'     => 'synced',
                'message'    => 'ቀደም ሲል የተመሳሰለ (Already synced)'
            ];
            $syncedCount++;
            continue;
        } elseif ($existing && $existing['status'] === 'rejected') {
            $results[] = [
                'event_uuid' => $uuid,
                'status'     => 'rejected',
                'message'    => $existing['error_message'] ?: 'ክስተቱ ቀደም ሲል ውድቅ ተደርጓል'
            ];
            continue;
        }
    }

    // 2. Validate Event Timestamp (not in the future, not older than 30 days)
    $rawTs = $ev['timestamp'] ?? null;
    $now = time();
    $eventTime = $now;
    if (!empty($rawTs)) {
        $parsedTime = strtotime($rawTs);
        if ($parsedTime !== false && $parsedTime <= ($now + 300) && $parsedTime >= ($now - 30 * 86400)) {
            $eventTime = $parsedTime;
        }
    }
    $eventDateStr = date('Y-m-d H:i:s', $eventTime);

    // 3. Record event as pending in sync_events using prepared statement
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $insStmt = mysqli_prepare($conn, "INSERT INTO sync_events (event_uuid, user_id, device_id, operation_type, payload, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE retry_count = retry_count + 1");
    if ($insStmt) {
        mysqli_stmt_bind_param($insStmt, 'sisss', $uuid, $userId, $deviceId, $opType, $payloadJson);
        mysqli_stmt_execute($insStmt);
        mysqli_stmt_close($insStmt);
    }

    $eventStatus = 'failed';
    $eventMessage = '';

    // 4. Role Authorization Guard
    if (in_array($opType, ['BORROW', 'RETURN', 'PAYMENT'], true) && !$isStaff) {
        $eventStatus = 'rejected';
        $eventMessage = 'ይህን ክንውን ለማከናወን ስልጣን የለዎትም (ሊብራሪያን ወይም አድሚን ብቻ)።';
    } else {
        // Process each operation atomically
        try {
            switch ($opType) {
                case 'REQUEST':
                    // Members can only request for themselves
                    $reqMemberId = (int)($payload['member_id'] ?? 0);
                    $bookId      = (int)($payload['book_id'] ?? 0);
                    $copyId      = !empty($payload['copy_id']) ? (int)$payload['copy_id'] : null;

                    if ($userRole === 'member') {
                        if ($reqMemberId === 0) {
                            $reqMemberId = $currentUserMemberId;
                        } elseif ($reqMemberId !== $currentUserMemberId) {
                            $eventStatus = 'rejected';
                            $eventMessage = 'ጥያቄ ማቅረብ የሚችሉት ለራስዎ መለያ ብቻ ነው።';
                            break;
                        }
                    }

                    if ($reqMemberId <= 0 || $bookId <= 0) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ያልተሟላ የጥያቄ መረጃ።';
                        break;
                    }

                    // Check for existing pending request (deduplication)
                    $dedupeStmt = mysqli_prepare($conn, "SELECT id FROM borrow_requests 
                                                         WHERE member_id = ? AND book_id = ? AND status = 'pending' 
                                                         LIMIT 1");
                    mysqli_stmt_bind_param($dedupeStmt, 'ii', $reqMemberId, $bookId);
                    mysqli_stmt_execute($dedupeStmt);
                    $existingReq = mysqli_fetch_assoc(mysqli_stmt_get_result($dedupeStmt));
                    mysqli_stmt_close($dedupeStmt);

                    if ($existingReq) {
                        $eventStatus = 'synced';
                        $eventMessage = 'የውሰት ጥያቄዎ አስቀድሞ በመጠባበቅ ላይ ይገኛል።';
                        break;
                    }

                    $stmtReq = mysqli_prepare($conn, "INSERT INTO borrow_requests (member_id, book_id, book_copy_id, type, status, requested_at, offline_uuid)
                                                      VALUES (?, ?, ?, 'borrow', 'pending', ?, ?)
                                                      ON DUPLICATE KEY UPDATE id = id");
                    mysqli_stmt_bind_param($stmtReq, 'iiiss', $reqMemberId, $bookId, $copyId, $eventDateStr, $uuid);
                    mysqli_stmt_execute($stmtReq);
                    mysqli_stmt_close($stmtReq);

                    $eventStatus = 'synced';
                    $eventMessage = 'የውሰት ጥያቄዎ በተሳካ ሁኔታ ቀርቧል፤ ሊብራሪያኑ ሲያረጋግጥ ይደውልልዎታል።';
                    break;

                case 'BORROW':
                    $memberId   = (int)($payload['member_id'] ?? 0);
                    $copyId     = (int)($payload['copy_id'] ?? 0);
                    $bookId     = (int)($payload['book_id'] ?? 0);
                    $borrowDays = (int)get_setting($conn, 'borrow_days', 14);
                    $dueDate    = date('Y-m-d', strtotime("+{$borrowDays} days", $eventTime));

                    if ($memberId <= 0 || $copyId <= 0) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ያልተሟላ የውሰት መረጃ።';
                        break;
                    }

                    mysqli_begin_transaction($conn);

                    // 1. Lock and inspect the copy
                    $copyLockStmt = mysqli_prepare($conn, "SELECT id, book_id, status FROM book_copies WHERE id = ? FOR UPDATE");
                    mysqli_stmt_bind_param($copyLockStmt, 'i', $copyId);
                    mysqli_stmt_execute($copyLockStmt);
                    $copyRow = mysqli_fetch_assoc(mysqli_stmt_get_result($copyLockStmt));
                    mysqli_stmt_close($copyLockStmt);

                    if (!$copyRow) {
                        mysqli_rollback($conn);
                        $eventStatus = 'rejected';
                        $eventMessage = 'የመጽሐፍ ቅጂው አልተገኘም።';
                        break;
                    }

                    if ($bookId <= 0) {
                        $bookId = (int)$copyRow['book_id'];
                    }

                    if ($copyRow['status'] !== 'available') {
                        mysqli_rollback($conn);
                        $eventStatus = 'rejected';
                        $eventMessage = 'ይህ የመጽሐፍ ቅጂ በአሁኑ ሰዓት ዝግጁ አይደለም (ሁኔታ፦ ' . $copyRow['status'] . ')።';
                        break;
                    }

                    // 2. Strict eligibility checks (Active, not blocked, paid month, borrowable, borrow limit, fines)
                    $eligibility = verify_borrow_eligibility($conn, $memberId, $bookId, $copyId);
                    if (!$eligibility['can_borrow']) {
                        mysqli_rollback($conn);
                        $eventStatus = 'rejected';
                        $reasons = [];
                        foreach ($eligibility['checks'] as $c) {
                            if (!$c['passed']) {
                                $reasons[] = $c['detail'];
                            }
                        }
                        $eventMessage = 'አባሉ መጽሐፍ ለመዋስ ብቁ አይደለም፦ ' . implode('፤ ', $reasons);
                        break;
                    }

                    // 3. Atomically update copy status to 'borrowed'
                    $upCopyStmt = mysqli_prepare($conn, "UPDATE book_copies SET status = 'borrowed' WHERE id = ? AND status = 'available'");
                    mysqli_stmt_bind_param($upCopyStmt, 'i', $copyId);
                    mysqli_stmt_execute($upCopyStmt);
                    $affected = mysqli_stmt_affected_rows($upCopyStmt);
                    mysqli_stmt_close($upCopyStmt);

                    if ($affected !== 1) {
                        mysqli_rollback($conn);
                        $eventStatus = 'rejected';
                        $eventMessage = 'መጽሐፉን ለመዋስ አልተቻለም (ቅጂው በሌላ ተጠቃሚ ተወስዷል)።';
                        break;
                    }

                    // 4. Insert borrow record with verified parameter types (iiisis)
                    $stmtBorrow = mysqli_prepare($conn, "INSERT INTO borrow_records (member_id, book_copy_id, book_id, borrowed_at, due_date, issued_by, offline_uuid)
                                                         VALUES (?, ?, ?, ?, ?, ?, ?)
                                                         ON DUPLICATE KEY UPDATE id = id");
                    mysqli_stmt_bind_param($stmtBorrow, 'iiissis', $memberId, $copyId, $bookId, $eventDateStr, $dueDate, $userId, $uuid);
                    $executed = mysqli_stmt_execute($stmtBorrow);
                    mysqli_stmt_close($stmtBorrow);

                    if (!$executed) {
                        mysqli_rollback($conn);
                        $eventStatus = 'failed';
                        $eventMessage = 'የውሰት መዝገቡን ማስቀመጥ አልተቻለም: ' . mysqli_error($conn);
                        break;
                    }

                    mysqli_commit($conn);
                    $eventStatus = 'synced';
                    $eventMessage = 'መጽሐፉ በተሳካ ሁኔታ ተዋሰ (የመመለሻ ቀን: ' . formatDate($dueDate) . ')';
                    break;

                case 'RETURN':
                    $copyId   = (int)($payload['copy_id'] ?? 0);
                    $borrowId = (int)($payload['borrow_id'] ?? 0);

                    if ($copyId <= 0 && $borrowId > 0) {
                        $bStmt = mysqli_prepare($conn, "SELECT book_copy_id FROM borrow_records WHERE id = ? LIMIT 1");
                        if ($bStmt) {
                            mysqli_stmt_bind_param($bStmt, 'i', $borrowId);
                            mysqli_stmt_execute($bStmt);
                            $bRow = mysqli_fetch_assoc(mysqli_stmt_get_result($bStmt));
                            $copyId = (int)($bRow['book_copy_id'] ?? 0);
                            mysqli_stmt_close($bStmt);
                        }
                    }

                    if ($copyId <= 0) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ያልተሟላ የተመላሽ ቅጂ መረጃ።';
                        break;
                    }

                    mysqli_begin_transaction($conn);

                    $retLock = mysqli_prepare($conn, "SELECT id, status FROM book_copies WHERE id = ? FOR UPDATE");
                    mysqli_stmt_bind_param($retLock, 'i', $copyId);
                    mysqli_stmt_execute($retLock);
                    $cRow = mysqli_fetch_assoc(mysqli_stmt_get_result($retLock));
                    mysqli_stmt_close($retLock);

                    if (!$cRow) {
                        mysqli_rollback($conn);
                        $eventStatus = 'rejected';
                        $eventMessage = 'የተመላሽ ቅጂው አልተገኘም።';
                        break;
                    }

                    // Update copy back to 'available'
                    $upAvail = mysqli_prepare($conn, "UPDATE book_copies SET status = 'available' WHERE id = ?");
                    mysqli_stmt_bind_param($upAvail, 'i', $copyId);
                    mysqli_stmt_execute($upAvail);
                    mysqli_stmt_close($upAvail);

                    // Close active borrow record
                    $recStmt = mysqli_prepare($conn, "UPDATE borrow_records 
                                                      SET status = 'returned', returned_at = ?, returned_to = ? 
                                                      WHERE book_copy_id = ? AND status = 'borrowed'");
                    mysqli_stmt_bind_param($recStmt, 'sii', $eventDateStr, $userId, $copyId);
                    mysqli_stmt_execute($recStmt);
                    mysqli_stmt_close($recStmt);

                    mysqli_commit($conn);
                    $eventStatus = 'synced';
                    $eventMessage = 'መጽሐፉ በተሳካ ሁኔታ ተመልሷል።';
                    break;

                case 'PAYMENT':
                    $memberId = (int)($payload['member_id'] ?? 0);
                    $month    = clean($payload['payment_month'] ?? '');
                    $amount   = (float)($payload['amount'] ?? 0);
                    $method   = clean($payload['payment_method'] ?? 'Cash');
                    $ref      = clean($payload['reference_number'] ?? '');
                    $notes    = clean($payload['notes'] ?? 'Offline recorded');

                    if ($memberId > 0 && $amount > 0 && !empty($month)) {
                        record_membership_payment($conn, $memberId, $month, $amount, $method, $userId, $ref, $uuid, $notes);
                        $eventStatus = 'synced';
                        $eventMessage = 'ክፍያው በተሳካ ሁኔታ ተመዝግቧል።';
                    } else {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ያልተሟላ የክፍያ መረጃ።';
                    }
                    break;

                default:
                    $eventStatus = 'rejected';
                    $eventMessage = "ያልታወቀ ክንውን፦ $opType";
                    break;
            }
        } catch (\Throwable $e) {
            if ($conn->connect_errno === 0) {
                @mysqli_rollback($conn);
            }
            $eventStatus = 'failed';
            $eventMessage = 'የስርዓት ስህተት፦ ' . $e->getMessage();
        }
    }

    // 5. Update sync_events using prepared statements
    $syncUpdateStmt = mysqli_prepare($conn, "UPDATE sync_events 
                                            SET status = ?, error_message = ?, synced_at = ? 
                                            WHERE event_uuid = ?");
    if ($syncUpdateStmt) {
        $syncedAtVal = ($eventStatus === 'synced') ? $eventDateStr : null;
        mysqli_stmt_bind_param($syncUpdateStmt, 'ssss', $eventStatus, $eventMessage, $syncedAtVal, $uuid);
        mysqli_stmt_execute($syncUpdateStmt);
        mysqli_stmt_close($syncUpdateStmt);
    }

    if ($eventStatus === 'synced') {
        $syncedCount++;
    }

    $results[] = [
        'event_uuid' => $uuid,
        'status'     => $eventStatus,
        'message'    => $eventMessage
    ];
}

echo json_encode([
    'success'      => true,
    'synced_count' => $syncedCount,
    'results'      => $results
], JSON_UNESCAPED_UNICODE);
