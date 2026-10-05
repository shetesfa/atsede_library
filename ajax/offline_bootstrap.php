<?php
/**
 * ajax/offline_bootstrap.php
 * Provides full offline cache payload for PWA and scanner.
 * Strict role-gating: only staff receive member rosters;
 * Book copies are structured as clean typed objects {id, copy_code, qr_identifier, status, position};
 * Safe user profile lookups without assuming session state.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

global $conn;

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ያልተፈቀደ መግቢያ፤ እባክዎ እንደገና ይግቡ።'], JSON_UNESCAPED_UNICODE);
    if (!defined('PHPUNIT_RUNNING')) exit;
    return;
}

$user = current_user();
$userId = (int)$user['id'];

// Always verify fresh user profile and role from database
$uStmt = mysqli_prepare($conn, "SELECT id, full_name, username, phone, role, status FROM users WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($uStmt, 'i', $userId);
mysqli_stmt_execute($uStmt);
$dbUser = mysqli_fetch_assoc(mysqli_stmt_get_result($uStmt)) ?: $user;
mysqli_stmt_close($uStmt);

$role = $dbUser['role'] ?? 'member';
$currentMonth = current_billing_month();
$minMonthly = get_minimum_monthly_payment($conn);

$data = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'user' => [
        'id'        => $userId,
        'full_name' => $dbUser['full_name'] ?? $dbUser['username'] ?? '',
        'role'      => $role,
        'phone'     => $dbUser['phone'] ?? ''
    ],
    'settings' => [
        'minimum_monthly_payment'  => $minMonthly,
        'current_billing_month'     => $currentMonth,
        'current_billing_month_am'  => format_billing_month_amharic($currentMonth)
    ]
];

// 1. Fetch all book copies in clean, structured form (no fragile GROUP_CONCAT)
$copiesByBook = [];
$copiesRes = mysqli_query($conn, "
    SELECT id, book_id, copy_code, qr_identifier, status 
    FROM book_copies 
    ORDER BY id ASC
");
if ($copiesRes) {
    while ($cp = mysqli_fetch_assoc($copiesRes)) {
        $bId = (int)$cp['book_id'];
        $copiesByBook[$bId][] = [
            'id'            => (int)$cp['id'],
            'copy_code'     => $cp['copy_code'],
            'qr_identifier' => $cp['qr_identifier'],
            'status'        => $cp['status']
        ];
    }
}

// 2. Fetch Books Catalog
$booksRes = mysqli_query($conn, "
    SELECT b.id, b.title, b.author, b.borrow_status, b.is_borrowable, b.non_borrowable_reason, 
           b.price, b.publication_year, b.publisher, b.position,
           c.name AS category_name, r.name AS room_name, s.name AS shelf_name
    FROM books b
    LEFT JOIN categories c ON c.id = b.category_id
    LEFT JOIN rooms r ON r.id = b.room_id
    LEFT JOIN shelves s ON s.id = b.shelf_id
    WHERE b.borrow_status != 'archived'
    ORDER BY b.title ASC
");

$books = [];
if ($booksRes) {
    while ($b = mysqli_fetch_assoc($booksRes)) {
        $bookId = (int)$b['id'];
        $bCopies = $copiesByBook[$bookId] ?? [];
        
        // Count available copies
        $availCount = 0;
        foreach ($bCopies as $cp) {
            if ($cp['status'] === 'available') {
                $availCount++;
            }
        }

        $b['id']               = $bookId;
        $b['is_borrowable']    = (int)($b['is_borrowable'] ?? 1);
        $b['available_copies'] = $availCount;
        $b['copies']           = $bCopies;

        $books[] = $b;
    }
}
$data['books'] = $books;

// 3. Role-specific Data Gating
if ($role === 'librarian' || $role === 'admin') {
    // Only staff can view member rosters and payment statuses
    $membersRes = mysqli_query($conn, "
        SELECT m.id AS member_id, m.user_id, u.full_name, u.phone, m.class, m.student_id, u.status
        FROM members m
        JOIN users u ON u.id = m.user_id
        WHERE u.status = 'active'
        ORDER BY u.full_name ASC
    ");
    $members = [];
    if ($membersRes) {
        while ($m = mysqli_fetch_assoc($membersRes)) {
            $st = get_member_payment_status($conn, $m['member_id'], $currentMonth);
            $m['payment_status'] = $st;
            $members[] = $m;
        }
    }
    $data['members'] = $members;

    // Active loans for staff overview
    $loansRes = mysqli_query($conn, "
        SELECT br.id, br.member_id, br.book_copy_id, br.book_id, br.due_date,
               b.title, bc.copy_code, bc.qr_identifier, u.full_name AS member_name, u.phone AS member_phone
        FROM borrow_records br
        JOIN books b ON b.id = br.book_id
        JOIN book_copies bc ON bc.id = br.book_copy_id
        JOIN members m ON m.id = br.member_id
        JOIN users u ON u.id = m.user_id
        WHERE br.status = 'borrowed'
    ");
    $loans = [];
    if ($loansRes) {
        while ($l = mysqli_fetch_assoc($loansRes)) {
            $loans[] = $l;
        }
    }
    $data['active_loans'] = $loans;

} elseif ($role === 'member') {
    // Members only see their own payment status and active loans
    $mRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM members WHERE user_id = $userId LIMIT 1"));
    if ($mRow) {
        $memberId = (int)$mRow['id'];
        $data['my_payment_status'] = get_member_payment_status($conn, $memberId, $currentMonth);
        $data['my_payment_history'] = get_member_payment_history($conn, $memberId, 12);
        
        $myLoans = mysqli_query($conn, "
            SELECT br.*, b.title, b.author, bc.copy_code, bc.qr_identifier
            FROM borrow_records br
            JOIN books b ON b.id = br.book_id
            JOIN book_copies bc ON bc.id = br.book_copy_id
            WHERE br.member_id = $memberId AND br.status = 'borrowed'
        ");
        $loans = [];
        if ($myLoans) {
            while ($l = mysqli_fetch_assoc($myLoans)) {
                $loans[] = $l;
            }
        }
        $data['my_loans'] = $loans;
    }
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);
if (!defined('PHPUNIT_RUNNING')) exit;
