<?php
/**
 * config.php  —  Atsede Library
 * Loads DB credentials from config.local.php (gitignored).
 * config.local.php NEVER goes to Git or shared hosting public folders.
 */

// Timezone configuration (Africa/Addis_Ababa, UTC+3)
date_default_timezone_set('Africa/Addis_Ababa');

// Centralized error logging
error_reporting(E_ALL);
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0750, true);
    @file_put_contents($logsDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}
ini_set('log_errors', '1');
ini_set('error_log', $logsDir . '/error.log');

if ($appEnv === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');

    // Polite Amharic error page for fatal unhandled exceptions
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            if (PHP_SAPI !== 'cli') {
                echo '<!DOCTYPE html><html lang="am"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ስህተት ተከስቷል</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;color:#1e293b;text-align:center;padding:20px;}.card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 4px 16px rgba(0,0,0,0.08);max-width:440px;}h2{margin:0 0 12px;color:#a61d21;}p{color:#64748b;font-size:0.95rem;line-height:1.6;}a{display:inline-block;margin-top:16px;padding:10px 20px;background:#a61d21;color:#fff;text-decoration:none;border-radius:10px;font-weight:bold;}</style></head><body><div class="card"><h2>ይቅርታ!</h2><p>ያልተጠበቀ የቴክኒክ ችግር አጋጥሟል። ችግሩ ለቴክኒክ ቡድኑ ተመዝግቧል፤ እባክዎ ከጥቂት ደቂቃዎች በኋላ እንደገና ይሞክሩ።</p><a href="/">ወደ ዋና ገጽ ተመለስ</a></div></body></html>';
            }
        }
    });
} else {
    ini_set('display_errors', '1');
}

// Project-specific session name and secure cookie parameters
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_name('atsede_sess_id');
    
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => 86400 * 7,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    @session_start();
}

// Global Security Headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: https: blob:;");
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
@mysqli_query($conn, "SET time_zone = '+03:00'");


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

// ---- Ethiopian calendar helpers (Universal JDN Algorithm for All Years) ----

function ethiopian_to_jd($year, $month, $day) {
    $y = (int)$year - 1;
    $cycle = intdiv($y, 4);
    $yearInCycle = $y % 4;
    $days = $cycle * 1461;
    if ($yearInCycle === 1) {
        $days += 365;
    } elseif ($yearInCycle === 2) {
        $days += 730;
    } elseif ($yearInCycle === 3) {
        $days += 1096;
    }
    $days += ((int)$month - 1) * 30 + ((int)$day - 1);
    return 1724221 + $days;
}

function jd_to_ethiopian($jd) {
    $r = (int)$jd - 1724221;
    if ($r < 0) return null;
    $cycle = intdiv($r, 1461);
    $rem = $r % 1461;

    if ($rem < 365) {
        $yearInCycle = 0;
        $dayInYear = $rem;
    } elseif ($rem < 730) {
        $yearInCycle = 1;
        $dayInYear = $rem - 365;
    } elseif ($rem < 1096) {
        $yearInCycle = 2; // Leap year (ዘመነ ሉቃስ - 6 Pagume days)
        $dayInYear = $rem - 730;
    } else {
        $yearInCycle = 3;
        $dayInYear = $rem - 1096;
    }

    $year = ($cycle * 4) + $yearInCycle + 1;
    if ($dayInYear < 360) {
        $month = intdiv($dayInYear, 30) + 1;
        $day = ($dayInYear % 30) + 1;
    } else {
        $month = 13;
        $day = ($dayInYear - 360) + 1;
    }

    return [
        'year'  => (int)$year,
        'month' => (int)$month,
        'day'   => (int)$day,
    ];
}

function gregorian_to_jd_universal($month, $day, $year) {
    if (function_exists('gregoriantojd')) {
        return gregoriantojd((int)$month, (int)$day, (int)$year);
    }
    $m = (int)$month;
    $d = (int)$day;
    $y = (int)$year;
    $a = intdiv(14 - $m, 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    return $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
}

function jd_to_gregorian_universal($jd) {
    if (function_exists('jdtogregorian')) {
        return jdtogregorian((int)$jd);
    }
    $l = (int)$jd + 68569;
    $n = intdiv(4 * $l, 146097);
    $l = $l - intdiv(146097 * $n + 3, 4);
    $i = intdiv(4000 * ($l + 1), 1461001);
    $l = $l - intdiv(1461 * $i, 4) + 31;
    $j = intdiv(80 * $l, 2447);
    $d = $l - intdiv(2447 * $j, 80);
    $l = intdiv($j, 11);
    $m = $j + 2 - 12 * $l;
    $y = 100 * ($n - 49) + $i + $l;
    return sprintf('%02d/%02d/%04d', $m, $d, $y);
}

function gregorianToEthParts($gregorianDate) {
    if (empty($gregorianDate) || $gregorianDate === '0000-00-00') return null;
    try {
        $date = new DateTime($gregorianDate);
        $m = (int)$date->format('n');
        $d = (int)$date->format('j');
        $y = (int)$date->format('Y');
        $jd = gregorian_to_jd_universal($m, $d, $y);
        return jd_to_ethiopian($jd);
    } catch (\Throwable $e) {
        return null;
    }
}

function ethiopianToGregorian($year, $month, $day) {
    $jd = ethiopian_to_jd((int)$year, (int)$month, (int)$day);
    $greg = jd_to_gregorian_universal($jd); // "m/d/Y"
    $parts = explode('/', $greg);
    return sprintf('%04d-%02d-%02d', (int)$parts[2], (int)$parts[0], (int)$parts[1]);
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