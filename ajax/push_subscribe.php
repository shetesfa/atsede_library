<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['endpoint'])) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$userId = (int)current_user()['id'];
$endpoint = $data['endpoint'];
$p256dh = $data['keys']['p256dh'] ?? '';
$auth = $data['keys']['auth'] ?? '';

$stmt = mysqli_prepare($conn, "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE user_id=?, p256dh=?, auth=?");
mysqli_stmt_bind_param($stmt, 'isssiss', $userId, $endpoint, $p256dh, $auth, $userId, $p256dh, $auth);
mysqli_stmt_execute($stmt);

echo json_encode(['ok' => true]);
