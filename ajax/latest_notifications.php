<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit;
}

$userId = (int)$user['id'];
$res = mysqli_query($conn, "
    SELECT id, title, message, created_at 
    FROM notifications 
    WHERE (user_id = $userId OR user_id IS NULL) AND is_read = 0 
    ORDER BY id DESC LIMIT 5
");

$notifications = [];
while ($row = mysqli_fetch_assoc($res)) {
    $notifications[] = [
        'id' => (int)$row['id'],
        'title' => $row['title'],
        'message' => $row['message'],
        'created_at' => $row['created_at'],
    ];
}

echo json_encode([
    'count' => count($notifications),
    'notifications' => $notifications
]);
