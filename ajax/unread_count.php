<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!current_user()) { echo json_encode(['count' => 0]); exit; }
echo json_encode(['count' => unread_count($conn, (int)current_user()['id'])]);
