<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Set environment for testing
putenv('DB_NAME=atsede_test');
$_ENV['DB_NAME'] = 'atsede_test';
$_SERVER['APP_ENV'] = 'testing';
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// Mock session if running in CLI
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Global DB connection for tests
global $conn;
$conn = new mysqli('localhost', 'root', '', 'atsede_test');
if ($conn->connect_error) {
    die("Test DB Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Load core config and functions
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
if (file_exists(__DIR__ . '/../includes/functions.php')) {
    require_once __DIR__ . '/../includes/functions.php';
}

require_once __DIR__ . '/TestCase.php';
