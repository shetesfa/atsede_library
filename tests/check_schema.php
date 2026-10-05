<?php
require_once __DIR__ . '/../config.php';
foreach (['shelves', 'rooms', 'books', 'book_copies'] as $table) {
    echo "=== Table: $table ===\n";
    $r = mysqli_query($conn, "DESCRIBE $table");
    while ($row = mysqli_fetch_assoc($r)) {
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}
