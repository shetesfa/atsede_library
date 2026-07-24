<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
$userId = (int)current_user()['id'];
mysqli_query($conn, "UPDATE notifications SET is_read=1 WHERE (user_id=$userId OR user_id IS NULL)");
echo json_encode(['ok' => true]);
