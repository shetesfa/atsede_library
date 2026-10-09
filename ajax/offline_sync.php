<?php
/**
 * ajax/offline_sync.php
 * Secure offline event synchronization endpoint.
 * Enforces role checks, atomic transactions with row locking (SELECT ... FOR UPDATE),
 * strict eligibility verification, parameter type safety, and Amharic response messages.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/LibraryService.php';

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

// ---------------------------------------------------------------
// Helpers (guarded so the file can be included repeatedly by tests)
// ---------------------------------------------------------------
if (!function_exists('atsede_copy_id_from_qr')) {
    /** Resolve a book_copies.id from a qr_identifier (used when a copy was created offline and has no server id yet). */
    function atsede_copy_id_from_qr($conn, $qr) {
        $qr = trim((string)$qr);
        if ($qr === '') return 0;
        $st = mysqli_prepare($conn, "SELECT id FROM book_copies WHERE qr_identifier = ? LIMIT 1");
        if (!$st) return 0;
        mysqli_stmt_bind_param($st, 's', $qr);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        return (int)($row['id'] ?? 0);
    }
}

if (!function_exists('atsede_new_qr_identifier')) {
    function atsede_new_qr_identifier($bookId, $code) {
        return 'ATS-COPY-' . strtoupper(substr(md5($bookId . '_' . $code . '_' . bin2hex(random_bytes(8))), 0, 10));
    }
}

if (!function_exists('atsede_save_offline_cover')) {
    /**
     * Save a base64 data-URL cover sent by an offline client through the SAME
     * pipeline as the online form (secure_process_image + optional GitHub storage).
     * Returns the stored cover name/URL, null when no cover, or false on failure.
     */
    function atsede_save_offline_cover($dataUrl) {
        if (!is_string($dataUrl) || $dataUrl === '') return null;
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,#i', $dataUrl)) return false;
        $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bin === false || strlen($bin) > 3 * 1024 * 1024) return false;

        $tmp = tempnam(sys_get_temp_dir(), 'offcov_');
        if ($tmp === false || file_put_contents($tmp, $bin) === false) return false;

        $res = secure_process_image($tmp, UPLOAD_DIR, 'cover_' . time());
        @unlink($tmp);
        if (empty($res['success'])) return false;

        $name = $res['file_name'];
        if (!function_exists('upload_cover_to_github')) {
            @require_once __DIR__ . '/../includes/github_storage.php';
        }
        if (function_exists('upload_cover_to_github')) {
            $gh = upload_cover_to_github(UPLOAD_DIR . $name, $name);
            if ($gh) $name = $gh;
        }
        return $name;
    }
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
    if (in_array($opType, ['BORROW', 'RETURN', 'PAYMENT', 'ADD_BOOK'], true) && !$isStaff) {
        $eventStatus = 'rejected';
        $eventMessage = 'ይህን ክንውን ለማከናወን ስልጣን የለዎትም (ሊብራሪያን ወይም አድሚን ብቻ)።';
    } else {
        // Process each operation atomically
        try {
            switch ($opType) {
                case 'REQUEST':
                case 'BORROW_REQUEST':
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
                    if ($copyId <= 0) {
                        // Copy was created offline (no server id yet): resolve by its QR identifier
                        $copyId = atsede_copy_id_from_qr($conn, $payload['qr_identifier'] ?? '');
                    }
                    $borrowDays = (int)get_setting($conn, 'borrow_days', 14);
                    $dueDate    = date('Y-m-d', strtotime("+{$borrowDays} days", $eventTime));

                    if ($memberId <= 0 || $copyId <= 0) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ያልተሟላ የውሰት መረጃ።';
                        break;
                    }

                    $res = issue_copy($conn, $memberId, $copyId, $dueDate, $userId, '', null, $uuid);
                    if ($res['success']) {
                        $eventStatus = 'synced';
                        $eventMessage = 'መጽሐፉ በተሳካ ሁኔታ ተዋሰ (የመመለሻ ቀን: ' . formatDate($dueDate) . ')';
                    } else {
                        $eventStatus = 'rejected';
                        $eventMessage = $res['message'];
                    }
                    break;

                case 'RETURN':
                    $copyId   = (int)($payload['copy_id'] ?? 0);
                    if ($copyId <= 0) {
                        $copyId = atsede_copy_id_from_qr($conn, $payload['qr_identifier'] ?? '');
                    }
                    $borrowId = (int)($payload['borrow_id'] ?? 0);
                    $action   = clean($payload['action'] ?? 'returned');

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

                    $res = return_copy($conn, $copyId, $action, '', $userId);
                    if ($res['success']) {
                        $eventStatus = 'synced';
                        $eventMessage = 'መጽሐፉ በተሳካ ሁኔታ ተመልሷል።';
                    } else {
                        $eventStatus = 'rejected';
                        $eventMessage = $res['message'];
                    }
                    break;

                case 'ADD_BOOK':
                    $title      = clean($payload['title'] ?? '');
                    $author     = clean($payload['author'] ?? '');
                    if ($author === '' || strtolower($author) === 'unwritten') {
                        $author = 'ጸሃፊው አልተገለጸም';
                    }
                    $categoryId = (int)($payload['category_id'] ?? 0);
                    $quantity   = min(200, max(1, (int)($payload['quantity'] ?? 1)));
                    $year       = clean($payload['year'] ?? '') ?: null;
                    $publisher  = clean($payload['publisher'] ?? '') ?: null;
                    $description = clean($payload['description'] ?? '') ?: null;
                    $price      = (isset($payload['price']) && $payload['price'] !== '' && $payload['price'] !== null) ? (float)$payload['price'] : null;
                    $roomId     = (int)($payload['room_id'] ?? 0) ?: null;
                    $shelfId    = (int)($payload['shelf_id'] ?? 0) ?: null;
                    $position   = clean($payload['position'] ?? '') ?: null;
                    $borrowStatus = in_array($payload['borrow_status'] ?? '', ['available','restricted','reference','archived'], true) ? $payload['borrow_status'] : 'available';
                    $isBorrowable = isset($payload['is_borrowable']) ? (int)(bool)$payload['is_borrowable'] : 1;
                    $nonBorrowableReason = clean($payload['non_borrowable_reason'] ?? '') ?: null;

                    if ($title === '' || $categoryId <= 0) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'የመጽሐፍ ስም እና ምድብ ያስፈልጋሉ።';
                        break;
                    }

                    $catChk = mysqli_prepare($conn, "SELECT id FROM categories WHERE id = ? LIMIT 1");
                    mysqli_stmt_bind_param($catChk, 'i', $categoryId);
                    mysqli_stmt_execute($catChk);
                    $catOk = mysqli_fetch_assoc(mysqli_stmt_get_result($catChk));
                    mysqli_stmt_close($catChk);
                    if (!$catOk) {
                        $eventStatus = 'rejected';
                        $eventMessage = 'ምድቡ በአገልጋዩ ላይ አልተገኘም።';
                        break;
                    }

                    // Cover image (optional). A bad image must not lose the book itself.
                    $coverName = atsede_save_offline_cover($payload['cover_data'] ?? null);
                    $coverNote = '';
                    if ($coverName === false) {
                        $coverName = null;
                        $coverNote = ' (የሽፋን ምስሉ አልተቀበለም፤ በኋላ ያክሉ)';
                    }

                    // Offline entry cannot ask the librarian about duplicates, so keep the
                    // book as a separate record (non-destructive) and flag it.
                    $dupNote = '';
                    $dupStmt = mysqli_prepare($conn, "SELECT id FROM books WHERE LOWER(title)=LOWER(?) AND LOWER(author)=LOWER(?) LIMIT 1");
                    mysqli_stmt_bind_param($dupStmt, 'ss', $title, $author);
                    mysqli_stmt_execute($dupStmt);
                    $dupRow = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
                    mysqli_stmt_close($dupStmt);
                    if ($dupRow) {
                        $dupNote = " ⚠ ተመሳሳይ መጽሐፍ (ቁጥር {$dupRow['id']}) ቀደም ሲል አለ፤ እንደ የተለየ መዝገብ ተመዝግቧል።";
                    }

                    mysqli_begin_transaction($conn);
                    $insB = mysqli_prepare($conn, "INSERT INTO books (title, author, category_id, quantity, publication_year, publisher, description, price, cover_image, room_id, shelf_id, position, borrow_status, is_borrowable, non_borrowable_reason, created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    mysqli_stmt_bind_param($insB, 'ssiisssdsiissisi', $title, $author, $categoryId, $quantity, $year, $publisher, $description, $price, $coverName, $roomId, $shelfId, $position, $borrowStatus, $isBorrowable, $nonBorrowableReason, $userId);
                    mysqli_stmt_execute($insB);
                    $newBookId = (int)mysqli_insert_id($conn);
                    mysqli_stmt_close($insB);

                    // Authoritative copy codes come from the server (no clashes between devices).
                    // The QR identifiers chosen offline are KEPT so labels printed offline stay valid.
                    $codes = next_codes_for_category($conn, $categoryId, $quantity);
                    $clientCopies = is_array($payload['copies'] ?? null) ? array_values($payload['copies']) : [];
                    foreach ($codes as $i => $code) {
                        $qrId = trim((string)($clientCopies[$i]['qr_identifier'] ?? ''));
                        $valid = (bool)preg_match('/^ATS-COPY-[A-Z0-9]{8,20}$/', $qrId);
                        if ($valid && atsede_copy_id_from_qr($conn, $qrId) > 0) {
                            $valid = false; // already used
                        }
                        if (!$valid) {
                            $qrId = atsede_new_qr_identifier($newBookId, $code);
                        }
                        $insC = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code, qr_identifier) VALUES (?,?,?)");
                        mysqli_stmt_bind_param($insC, 'iss', $newBookId, $code, $qrId);
                        mysqli_stmt_execute($insC);
                        mysqli_stmt_close($insC);
                    }
                    mysqli_commit($conn);

                    notify_broadcast($conn, 'አዲስ መጽሐፍ ታክሏል', "\"$title\" በ$author ወደ ቤተ መጻሕፍት ገብቷል።", 'new_book', 'book.php?id=' . $newBookId);
                    audit($conn, $userId, 'book_created_offline', "book_id:$newBookId codes:" . implode(',', $codes) . ($dupRow ? ' possible_duplicate_of:' . $dupRow['id'] : ''));

                    $eventStatus = 'synced';
                    $eventMessage = 'መጽሐፉ ታክሏል። ኮዶች፦ ' . implode(', ', $codes) . $coverNote . $dupNote;
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
