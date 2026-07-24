<?php
/**
 * config.php
 * Database connection + core date helpers for Atsede Library.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Database credentials --------------------------------------------
$host = "localhost";
$user = "root";
$pass = "";
$db   = "atsede_library";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    die("አገልግሎቱ ለጊዜው አይገኝም። እባክዎ ከጥቂት ደቂቃዎች በኋላ ይሞክሩ።");
}
mysqli_set_charset($conn, "utf8mb4");

// Lightweight schema patch for existing installs
$checkColumn = mysqli_query($conn, "SHOW COLUMNS FROM members LIKE 'blocked_until'");
if (mysqli_num_rows($checkColumn) == 0) {
    mysqli_query($conn, "ALTER TABLE members ADD COLUMN blocked_until DATETIME DEFAULT NULL");
}

// Auto-detect base URL from current directory
// Manual override: uncomment and set if auto-detection fails
define('BASE_URL', '/');  // Use '/' for root-level hosting
// define('BASE_URL', '/atsede_library');  // Use '/atsede_library' for subdirectory

if (!defined('BASE_URL')) {
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    // Remove trailing slash for consistent comparison
    $scriptPath = rtrim($scriptPath, '/');
    $baseFolder = '/atsede_library';
    if (strpos($scriptPath, $baseFolder) === 0) {
        define('BASE_URL', $baseFolder);
    } else {
        // If at root or empty, use '/', otherwise use the detected path
        define('BASE_URL', $scriptPath === '' || $scriptPath === '.' ? '/' : '/' . $scriptPath);
    }
}
define('UPLOAD_DIR', __DIR__ . '/uploads/covers/');
define('UPLOAD_URL', BASE_URL . '/uploads/covers/');

// ---- Ethiopian calendar helpers ---------------------------------------
function gregorianToEthiopian($gregorianDate) {
    $date = new DateTime($gregorianDate);
    $gregYear = (int)$date->format('Y');

    $ethNewYear = new DateTime("$gregYear-09-11");
    if ($gregYear % 4 == 3) {
        $ethNewYear = new DateTime("$gregYear-09-12");
    }

    if ($date >= $ethNewYear) {
        $ethYear = $gregYear - 7;
        $refNewYear = $ethNewYear;
    } else {
        $prevYear = $gregYear - 1;
        $refNewYear = new DateTime("$prevYear-09-11");
        if ($prevYear % 4 == 3) {
            $refNewYear = new DateTime("$prevYear-09-12");
        }
        $ethYear = $gregYear - 8;
    }

    $daysDiff = $date->diff($refNewYear)->days;
    $ethMonth = (int)floor($daysDiff / 30) + 1;
    $ethDay = ($daysDiff % 30) + 1;
    if ($ethMonth > 13) { $ethMonth = 13; $ethDay = min($ethDay, 6); }

    $months = [
        1=>'Meskerem',2=>'Tikimt',3=>'Hidar',4=>'Tahsas',5=>'Tir',6=>'Yekatit',
        7=>'Megabit',8=>'Miazia',9=>'Ginbot',10=>'Sene',11=>'Hamle',12=>'Nehase',13=>'Pagume'
    ];
    return $ethDay . ' ' . ($months[$ethMonth] ?? '') . ' ' . $ethYear;
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date('d M Y', strtotime($date));
}

function formatEthiopianDate($dateString) {
    if (empty($dateString) || $dateString === '0000-00-00') return '—';
    return gregorianToEthiopian($dateString);
}

function getCurrentEthiopianDate() {
    return gregorianToEthiopian(date('Y-m-d'));
}
