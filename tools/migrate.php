<?php
/**
 * tools/migrate.php — Atsede Library Migration Runner
 *
 * Runs all pending migrations from database/migrations/ in sequential order.
 * Safe to run repeatedly (idempotent).
 *
 * Usage:
 *   php tools/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/../config.php';

echo "=== Atsede Library Database Migration Runner ===\n\n";

// 1. Ensure migrations table exists
$createMigrationsTable = "
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL UNIQUE,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (!mysqli_query($conn, $createMigrationsTable)) {
    die("[FAIL] Could not initialize migrations table: " . mysqli_error($conn) . "\n");
}

// 2. Fetch already applied migrations
$applied = [];
$res = mysqli_query($conn, "SELECT migration FROM migrations ORDER BY id ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $applied[$row['migration']] = true;
}

// 3. Scan database/migrations/ directory
$migrationsDir = __DIR__ . '/../database/migrations';
if (!is_dir($migrationsDir)) {
    die("[FAIL] Migrations directory not found: $migrationsDir\n");
}

$files = scandir($migrationsDir);
$migrationFiles = [];
foreach ($files as $f) {
    if (str_ends_with($f, '.sql')) {
        $migrationFiles[] = $f;
    }
}
sort($migrationFiles, SORT_NATURAL);

if (empty($migrationFiles)) {
    echo "No migration files found.\n";
    exit(0);
}

$newCount = 0;

foreach ($migrationFiles as $filename) {
    if (isset($applied[$filename])) {
        echo "  [SKIP] $filename (already applied)\n";
        continue;
    }

    echo "  [RUN]  $filename ... ";
    $filePath = $migrationsDir . '/' . $filename;
    $sqlContent = file_get_contents($filePath);

    if (empty(trim($sqlContent))) {
        echo "EMPTY (marked as applied)\n";
        $stmt = mysqli_prepare($conn, "INSERT INTO migrations (migration) VALUES (?)");
        mysqli_stmt_bind_param($stmt, 's', $filename);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        continue;
    }

    // Split SQL into individual statements while respecting triggers/procedures
    $statements = array_filter(
        array_map('trim', explode(';', $sqlContent)),
        fn($stmt) => !empty($stmt)
    );

    $hasError = false;
    $errorMsg = '';

    foreach ($statements as $query) {
        // Strip line comments
        $lines = explode("\n", $query);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (!str_starts_with($trimmed, '--') && !str_starts_with($trimmed, '/*')) {
                $cleanLines[] = $line;
            }
        }
        $executableQuery = trim(implode("\n", $cleanLines));
        if (empty($executableQuery)) continue;

        if (!@mysqli_query($conn, $executableQuery)) {
            $err = mysqli_error($conn);
            // Ignore benign DDL duplicate errors
            if (!str_contains($err, 'Duplicate') && !str_contains($err, 'already exists')) {
                $hasError = true;
                $errorMsg = $err;
                break;
            }
        }
    }

    if ($hasError) {
        echo "FAILED!\n";
        echo "         Error: $errorMsg\n";
        exit(1);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO migrations (migration) VALUES (?)");
        mysqli_stmt_bind_param($stmt, 's', $filename);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        echo "OK\n";
        $newCount++;
    }
}

echo "\nMigration run complete. Applied $newCount new migration(s).\n";
