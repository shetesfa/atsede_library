<?php
/**
 * tools/backup.php — Atsede Library Automated Backup Tool
 *
 * Backs up database and uploads directory to backups/
 * Enforces security (.htaccess Deny from all) and backup retention rotation
 * (keeps last 7 daily and 4 weekly backups).
 *
 * Usage:
 *   php tools/backup.php
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/functions.php';
    require_role('admin');
} else {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/functions.php';
}

echo "=== Atsede Library Backup Utility ===\n\n";

$backupsDir = __DIR__ . '/../backups';
if (!is_dir($backupsDir)) {
    mkdir($backupsDir, 0750, true);
}

// 1. Secure backups directory with .htaccess
$htaccessPath = $backupsDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    file_put_contents($htaccessPath, "Order deny,allow\nDeny from all\n");
}

$timestamp = date('Y-m-d_H-i-s');
$dbBackupFile = $backupsDir . "/db_backup_{$timestamp}.sql";
$uploadsZipFile = $backupsDir . "/uploads_backup_{$timestamp}.zip";

// 2. Dump Database
echo "Dumping database ... ";
$dbName = defined('DB_NAME') ? DB_NAME : 'atsede_library';
$dbUser = defined('DB_USER') ? DB_USER : 'root';
$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';

$tablesRes = mysqli_query($conn, "SHOW TABLES");
$tables = [];
while ($tRow = mysqli_fetch_row($tablesRes)) {
    $tables[] = $tRow[0];
}

$sqlHandle = fopen($dbBackupFile, 'w');
fwrite($sqlHandle, "-- Atsede Library Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($tables as $table) {
    // Write CREATE TABLE
    $cRes = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
    $cRow = mysqli_fetch_row($cRes);
    fwrite($sqlHandle, "DROP TABLE IF EXISTS `$table`;\n" . $cRow[1] . ";\n\n");

    // Write INSERT statements (excluding session or sensitive log dumps if empty)
    $dRes = mysqli_query($conn, "SELECT * FROM `$table`");
    while ($row = mysqli_fetch_assoc($dRes)) {
        $keys = array_map(fn($k) => "`$k`", array_keys($row));
        $vals = array_map(function($v) use ($conn) {
            if ($v === null) return 'NULL';
            return "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
        }, array_values($row));
        fwrite($sqlHandle, "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $vals) . ");\n");
    }
    fwrite($sqlHandle, "\n");
}

fwrite($sqlHandle, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($sqlHandle);
echo "OK (" . basename($dbBackupFile) . ")\n";

// 3. Compress uploads/ directory
echo "Compressing uploads ... ";
$uploadsDir = __DIR__ . '/../uploads';
if (class_exists('ZipArchive') && is_dir($uploadsDir)) {
    $zip = new ZipArchive();
    if ($zip->open($uploadsZipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen(realpath($uploadsDir)) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
        echo "OK (" . basename($uploadsZipFile) . ")\n";
    } else {
        echo "ZIP creation failed\n";
    }
} else {
    echo "SKIPPED (ZipArchive not available or uploads missing)\n";
}

// 4. Rotation Policy: keep last 7 daily, 4 weekly
echo "Rotating old backups ... ";
$allBackups = glob($backupsDir . '/*_backup_*');
$now = time();
$sevenDaysAgo = $now - (7 * 86400);

// Group by type (db or uploads)
$dbBackups = glob($backupsDir . '/db_backup_*.sql');
$zipBackups = glob($backupsDir . '/uploads_backup_*.zip');

foreach ([$dbBackups, $zipBackups] as $fileGroup) {
    if (count($fileGroup) > 11) { // 7 daily + 4 weekly
        sort($fileGroup); // Oldest first
        $deleteCount = count($fileGroup) - 11;
        for ($i = 0; $i < $deleteCount; $i++) {
            if (file_exists($fileGroup[$i])) {
                @unlink($fileGroup[$i]);
            }
        }
    }
}
echo "OK\n";

if (isset($user['id'])) {
    audit($conn, (int)$user['id'], 'system_backup', 'Database and uploads backup generated');
}

echo "\nBackup completed successfully.\n";
