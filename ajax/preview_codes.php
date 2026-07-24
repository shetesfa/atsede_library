<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin','librarian']);
header('Content-Type: application/json');

$categoryId = (int)($_GET['category_id'] ?? 0);
$quantity = max(1, (int)($_GET['quantity'] ?? 1));

if ($categoryId <= 0) { echo json_encode(['codes' => []]); exit; }
echo json_encode(['codes' => next_codes_for_category($conn, $categoryId, $quantity)]);
