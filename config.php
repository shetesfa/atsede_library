<?php
/**
 * config.php  —  Atsede Library
 * Loads DB credentials from config.local.php (gitignored).
 * config.local.php NEVER goes to Git or shared hosting public folders.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Load credentials from config.local.php or environment variables ---
$localConfig = [];
$_localCfg = __DIR__ . '/config.local.php';
if (file_exists($_localCfg)) {
    $res = require $_localCfg;
    if (is_array($res)) {
        $localConfig = $res;
    }
}

$host = getenv('DB_HOST') ?: ($localConfig['host'] ?? ($host ?? 'localhost'));
$user = getenv('DB_USER') ?: ($localConfig['user'] ?? ($user ?? 'root'));
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ($localConfig['pass'] ?? ($pass ?? ''));
$db   = getenv('DB_NAME') ?: ($localConfig['db'] ?? ($db ?? 'atsede_library'));
$port = (int)(getenv('DB_PORT') ?: ($localConfig['port'] ?? ($port ?? 3306)));

$conn = @mysqli_connect($host, $user, $pass, $db, $port);
if (!$conn) {
    error_log("Database connection error: " . mysqli_connect_error());
    die("አገልግሎቱ ለጊዜው አይገኝም፤ እባክዎ ከጥቂት ደቂቃዎች በኋላ ይሞክሩ።");
}
mysqli_set_charset($conn, "utf8mb4");


// Lightweight schema patch for existing installs
$checkColumn = mysqli_query($conn, "SHOW COLUMNS FROM members LIKE 'blocked_until'");
if ($checkColumn && mysqli_num_rows($checkColumn) == 0) {
    mysqli_query($conn, "ALTER TABLE members ADD COLUMN blocked_until DATETIME DEFAULT NULL");
}

// Auto-detect base URL from current directory
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $projectDirName = basename(__DIR__);
    $pos = strpos($scriptDir, '/' . $projectDirName);
    if ($pos !== false) {
        $detected = substr($scriptDir, 0, $pos + strlen($projectDirName) + 1);
        define('BASE_URL', rtrim($detected, '/') . '/');
    } else {
        define('BASE_URL', '/');
    }
}
define('UPLOAD_DIR', __DIR__ . '/uploads/covers/');
define('UPLOAD_URL', rtrim(BASE_URL, '/') . '/uploads/covers/');

// ---- Ethiopian calendar helpers ---------------------------------------
function gregorianToEthParts($gregorianDate) {
    if (empty($gregorianDate) || $gregorianDate === '0000-00-00') return null;
    $date     = new DateTime($gregorianDate);
    $gregYear = (int)$date->format('Y');

    // Ethiopian New Year is September 11 (or September 12 in year following leap year)
    $newYearDay = ($gregYear % 4 === 3) ? 12 : 11;
    $ethNewYear = new DateTime("$gregYear-09-$newYearDay");

    if ($date >= $ethNewYear) {
        $ethYear    = $gregYear - 7;
        $refNewYear = $ethNewYear;
    } else {
        $prevYear = $gregYear - 1;
        $prevNewYearDay = ($prevYear % 4 === 3) ? 12 : 11;
        $refNewYear = new DateTime("$prevYear-09-$prevNewYearDay");
        $ethYear    = $gregYear - 8;
    }

    $daysDiff = $date->diff($refNewYear)->days;
    $ethMonth = (int)floor($daysDiff / 30) + 1;
    $ethDay   = ($daysDiff % 30) + 1;
    if ($ethMonth > 13) { $ethMonth = 13; $ethDay = min($ethDay, 6); }

    return [
        'year'  => $ethYear,
        'month' => $ethMonth,
        'day'   => $ethDay,
    ];
}

function get_ethiopian_month_name($monthNum) {
    $months = [
        1  => 'መስከረም', 2  => 'ጥቅምት', 3  => 'ኅዳር',   4  => 'ታኅሣሥ',
        5  => 'ጥር',     6  => 'የካቲት', 7  => 'መጋቢት', 8  => 'ሚያዝያ',
        9  => 'ግንቦት',  10 => 'ሰኔ',    11 => 'ሐምሌ',  12 => 'ነሐሴ', 13 => 'ጳጉሜን'
    ];
    return $months[(int)$monthNum] ?? '';
}

function gregorianToEthiopian($gregorianDate) {
    $parts = gregorianToEthParts($gregorianDate);
    if (!$parts) return '—';
    return $parts['day'] . ' ' . get_ethiopian_month_name($parts['month']) . ' ' . $parts['year'] . ' ዓ.ም.';
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    return gregorianToEthiopian($date);
}

function formatEthiopianDate($dateString) {
    return gregorianToEthiopian($dateString);
}

function getCurrentEthiopianDate() {
    return gregorianToEthiopian(date('Y-m-d'));
}