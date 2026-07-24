<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin','librarian']);
header('Content-Type: application/json');

$roomId = (int)($_GET['room_id'] ?? 0);
if ($roomId <= 0) { echo json_encode(['shelves' => []]); exit; }

$shelves = [];
$res = mysqli_query($conn, "SELECT id, name FROM shelves WHERE room_id=$roomId ORDER BY id ASC");
$idx = 0;
while ($row = mysqli_fetch_assoc($res)) {
    $row['name'] = shelf_display_name($idx);
    $shelves[] = $row;
    $idx++;
}

echo json_encode(['shelves' => $shelves]);
